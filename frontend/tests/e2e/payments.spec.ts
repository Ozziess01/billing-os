import { expect, test } from "@playwright/test";
import { createCustomer, createProduct, ledgerCard, register, payInvoice, subscribe, optionValue } from "./helpers";

test.describe("payment outcomes", () => {
  test.beforeEach(async ({ page }) => {
    await register(page, "Ada", "Analytical Engines");
    await createProduct(page, "Engine time", [{ name: "Monthly", amount: "30" }]);
    await createCustomer(page, "Babbage Ltd");
    await subscribe(page, "Monthly");
    await page.getByRole("button", { name: "Выставить инвойс за период" }).click();
    await page.waitForURL("**/invoices/*");
    await expect(page.getByText("INV-000001").first()).toBeVisible();
  });

  test("a declined card leaves the invoice open and records the failed attempt", async ({ page }) => {
    const dialog = await payInvoice(page, "tok_fail");
    await expect(dialog.getByText("Отказ", { exact: true })).toBeVisible();
    await expect(dialog.getByText("Карта отклонена банком.")).toBeVisible();
    await dialog.getByText("Закрыть", { exact: true }).click();

    await expect(page.getByText("Открыт", { exact: true }).first()).toBeVisible();
    await expect(page.getByText("Отказ", { exact: true }).first()).toBeVisible();

    // вторая попытка проходит: неудачная не блокирует инвойс
    const retry = await payInvoice(page, "tok_ok");
    await expect(retry.getByText("Успешно", { exact: true })).toBeVisible();
    await retry.getByText("Закрыть", { exact: true }).click();
    await expect(page.getByText("Оплачен", { exact: true }).first()).toBeVisible();
    await expect(page.getByText("#2")).toBeVisible();
  });

  test("requires_action is confirmed and settled by a webhook, then partially refunded", async ({ page }) => {
    const dialog = await payInvoice(page, "tok_action");
    await expect(dialog.getByText("Провайдер ждёт подтверждения")).toBeVisible();
    await dialog.getByRole("button", { name: "Подтвердить" }).click();
    await expect(dialog.getByText("Провайдер отправит вебхук")).toBeVisible();
    await dialog.getByText("Закрыть", { exact: true }).click();
    await expect(page.getByText("Оплачен", { exact: true }).first()).toBeVisible({ timeout: 30_000 });

    await page.locator("a", { hasText: "#1" }).last().click();
    await page.waitForURL("**/payments/*");
    await page.getByRole("button", { name: "Вернуть" }).click();
    await page.getByLabel(/Сумма/).fill("10");
    await page.getByLabel("Причина").fill("Скидка задним числом");
    await page.getByRole("dialog").getByRole("button", { name: "Вернуть" }).click();
    await expect(page.getByText("Возвращено").first()).toBeVisible();
    await page.getByRole("dialog").getByText("Закрыть", { exact: true }).click();
    await expect(page.getByText("Доступно к возврату")).toBeVisible();
    await expect(page.getByText("20,00").first()).toBeVisible();

    // возврат - отдельная обратная проводка, оригинальный платёж не меняется
    await page.goto("/ledger");
    await expect(page.getByText("Возврат", { exact: true }).first()).toBeVisible();
    await expect(ledgerCard(page, "Денежные средства")).toContainText("20,00");
  });

  test("a draft invoice with a custom line is finalized and paid from the portal", async ({ page, browser }) => {
    await page.goto("/invoices");
    await page.getByRole("button", { name: "Новый инвойс" }).click();
    const form = page.getByRole("dialog");
    await form.locator("select").first().selectOption(await optionValue(form.locator("select").first(), "Babbage Ltd"));
    await form.getByRole("button", { name: "Создать черновик" }).click();
    await page.waitForURL("**/invoices/*");
    await page.getByRole("button", { name: "Добавить позицию" }).click();
    await page.getByRole("button", { name: "Произвольная" }).click();
    await page.getByLabel("Описание").fill("Onboarding workshop");
    await page.getByLabel(/Сумма за единицу/).fill("300");
    await page.getByRole("dialog").getByRole("button", { name: "Добавить позицию" }).click();
    await expect(page.getByText("Onboarding workshop")).toBeVisible();
    await page.getByRole("button", { name: "Финализировать" }).click();
    await expect(page.getByText("INV-000002").first()).toBeVisible();

    // клиент платит сам через портал
    await page.goto("/customers");
    await page.getByText("Babbage Ltd").click();
    await page.getByRole("button", { name: "Ссылка в портал" }).click();
    const portalUrl = await page.locator("a", { hasText: "/portal/bps_" }).innerText();

    const portal = await browser.newPage();
    await portal.goto(portalUrl);
    await expect(portal.getByText("Babbage Ltd").first()).toBeVisible();
    await portal.getByRole("link", { name: "Инвойсы" }).click();
    await portal.getByText("INV-000002").click();
    await expect(portal.getByText("Итого")).toBeVisible();
    await portal.getByLabel("Платёжный метод").selectOption("tok_ok");
    await portal.getByRole("button", { name: "Оплатить" }).click();
    await expect(portal.getByText("Успешно", { exact: true }).first()).toBeVisible();
    await expect(portal.getByText("Оплачен", { exact: true }).first()).toBeVisible();
    await portal.close();

    await page.goto("/invoices");
    await expect(page.locator("tr", { hasText: "INV-000002" }).getByText("Оплачен")).toBeVisible();
  });
});
