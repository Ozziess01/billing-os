import { expect, test } from "@playwright/test";
import { createCustomer, createProduct, ledgerCard, register, payInvoice, subscribe } from "./helpers";

// Сквозной сценарий: регистрация -> продукт -> клиент -> подписка -> инвойс ->
// платёж -> вебхук -> инвойс оплачен -> леджер сбалансирован
test("billing flow from registration to a balanced ledger", async ({ page }) => {
  await register(page, "Grace Hopper", "Cobol Cloud");
  await expect(page.getByRole("heading", { name: "Cobol Cloud" })).toBeVisible();

  await createProduct(page, "Team plan", [{ name: "Team monthly", amount: "49" }]);
  await createCustomer(page, "Initech");
  await subscribe(page, "Team monthly", { quantity: "2" });
  await expect(page.getByText("98,00").first()).toBeVisible();

  // инвойс за период: номер выдаётся при финализации
  await page.getByRole("button", { name: "Выставить инвойс за период" }).click();
  await page.waitForURL("**/invoices/*");
  await expect(page.getByText("INV-000001").first()).toBeVisible();
  await expect(page.getByText("Открыт", { exact: true }).first()).toBeVisible();

  // асинхронный платёж: провайдер отвечает processing, итог приходит вебхуком через очередь
  const dialog = await payInvoice(page, "tok_async");
  await expect(dialog.getByText("Провайдер принял платёж")).toBeVisible();
  await dialog.getByText("Закрыть", { exact: true }).click();
  await expect(page.getByText("Оплачен", { exact: true }).first()).toBeVisible({ timeout: 30_000 });

  // платёж успешен, событие провайдера обработано
  await page.goto("/payments");
  await expect(page.getByText("Успешно", { exact: true })).toBeVisible();
  await page.goto("/webhooks");
  await expect(page.getByText("payment.succeeded").first()).toBeVisible();

  // леджер: инвойс и платёж проведены, касса = выручка, дебиторка закрыта
  await page.goto("/ledger");
  await expect(page.getByText("Денежные средства").first()).toBeVisible();
  await expect(ledgerCard(page, "Денежные средства")).toContainText("98,00");
  await expect(ledgerCard(page, "Дебиторская задолженность")).toContainText("0,00");
  await expect(ledgerCard(page, "Выручка")).toContainText("98,00");
  await expect(page.getByText("Инвойс", { exact: true }).first()).toBeVisible();
  await expect(page.getByText("Платёж", { exact: true }).first()).toBeVisible();

  // сводка на дашборде обновилась
  await page.goto("/dashboard");
  await expect(page.getByText("Initech")).toBeVisible();
});

test("automatic collection with a coupon and metered usage", async ({ page }) => {
  await register(page, "Linus", "Kernel SaaS");
  await createProduct(page, "API access", [
    { name: "Base", amount: "10" },
    { name: "Requests", amount: "0.1", metered: true },
  ]);

  await page.goto("/coupons");
  await page.getByRole("button", { name: "Новый купон" }).click();
  await page.getByLabel("Код").fill("SAVE20");
  await page.getByLabel("Название").fill("Save 20");
  await page.getByRole("spinbutton", { name: "Процент" }).fill("20");
  await page.getByRole("dialog").getByRole("button", { name: "Создать" }).click();
  await expect(page.getByText("SAVE20")).toBeVisible();

  await createCustomer(page, "Torvalds Inc", "tok_ok");
  await subscribe(page, "Base", { coupon: "SAVE20", extraPrices: ["Requests"] });
  await expect(page.getByText("SAVE20")).toBeVisible();

  // usage по metered-позиции: записывается сразу, в инвойс попадёт при продлении
  await page.getByLabel(/Отчёт об использовании/).fill("12345");
  await page.getByRole("button", { name: "Записать" }).click();
  await expect(page.getByText("12 345").first()).toBeVisible();

  // первый инвойс списан автоматически со скидкой 20%: 10 -> 8
  await page.goto("/invoices");
  await page.getByText("INV-000001").click();
  await expect(page.getByText("Оплачен", { exact: true }).first()).toBeVisible();
  await expect(page.getByText("Скидка")).toBeVisible();
  await expect(page.getByText("8,00").first()).toBeVisible();

  await page.goto("/notifications");
  await expect(page.getByText("Новая подписка").first()).toBeVisible();
});
