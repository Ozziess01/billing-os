import { expect, test, type Page } from "@playwright/test";
import { createCustomer, createProduct, register, payInvoice, subscribe } from "./helpers";

// npm run screenshots -> docs/screenshots/*.jpg
const dir = process.env.SCREENSHOTS_DIR ?? "../docs/screenshots";

async function shot(page: Page, name: string) {
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${dir}/${name}.jpg`, type: "jpeg", quality: 80 });
}

test("capture screenshots for the readme", async ({ page, browser }) => {
  await register(page, "Ada Lovelace", "Analytical Engines");
  await createProduct(page, "Pro plan", [
    { name: "Pro monthly", amount: "19.99" },
    { name: "API calls", amount: "0.02", metered: true },
  ]);
  await shot(page, "products");

  await page.goto("/coupons");
  await page.getByRole("button", { name: "Новый купон" }).click();
  await page.getByLabel("Код").fill("LAUNCH25");
  await page.getByLabel("Название").fill("Launch discount");
  await page.getByRole("spinbutton", { name: "Процент" }).fill("25");
  await page.getByRole("dialog").getByRole("button", { name: "Создать" }).click();
  await expect(page.getByText("LAUNCH25")).toBeVisible();

  await createCustomer(page, "Globex Corp", "tok_ok");
  await subscribe(page, "Pro monthly", { quantity: "3", coupon: "LAUNCH25", extraPrices: ["API calls"] });
  await page.getByLabel(/Отчёт об использовании/).fill("4200");
  await page.getByRole("button", { name: "Записать" }).click();
  await expect(page.getByText("4 200").first()).toBeVisible();
  await shot(page, "subscription");

  await createCustomer(page, "Initech", "tok_async");
  await subscribe(page, "Pro monthly");
  await page.goto("/invoices");
  await expect(page.getByText("INV-000002")).toBeVisible();
  await expect(page.locator("tr", { hasText: "INV-000002" }).getByText("Оплачен")).toBeVisible({ timeout: 30_000 });
  await shot(page, "invoices");

  await page.getByText("INV-000001").click();
  await expect(page.getByText("Скидка")).toBeVisible();
  await shot(page, "invoice");

  await createCustomer(page, "Stark Industries");
  await subscribe(page, "Pro monthly", { quantity: "10" });
  await page.getByRole("button", { name: "Выставить инвойс за период" }).click();
  await page.waitForURL("**/invoices/*");
  const dialog = await payInvoice(page, "tok_action");
  await expect(dialog.getByText("Провайдер ждёт подтверждения")).toBeVisible();
  await shot(page, "payment-3ds");
  await dialog.getByRole("button", { name: "Подтвердить" }).click();
  await dialog.getByText("Закрыть", { exact: true }).click();
  await expect(page.getByText("Оплачен", { exact: true }).first()).toBeVisible({ timeout: 30_000 });
  await page.locator("a", { hasText: "#1" }).last().click();
  await page.waitForURL("**/payments/*");
  await page.getByRole("button", { name: "Вернуть" }).click();
  await page.getByLabel(/Сумма/).fill("50");
  await page.getByLabel("Причина").fill("Goodwill");
  await page.getByRole("dialog").getByRole("button", { name: "Вернуть" }).click();
  await expect(page.getByText("Возвращено").first()).toBeVisible();
  await page.getByRole("dialog").getByText("Закрыть", { exact: true }).click();
  await shot(page, "payment-refund");

  await page.goto("/dashboard");
  await expect(page.getByText("Globex Corp")).toBeVisible();
  await shot(page, "dashboard");
  await page.goto("/ledger");
  await expect(page.getByText("Денежные средства").first()).toBeVisible();
  await shot(page, "ledger");
  await page.goto("/webhooks");
  await expect(page.getByText("payment.succeeded").first()).toBeVisible();
  await shot(page, "webhooks");
  await page.goto("/notifications");
  await shot(page, "notifications");
  await page.goto("/settings");
  await page.getByLabel("Название ключа").fill("backend");
  await page.getByRole("button", { name: "Выпустить ключ" }).click();
  await expect(page.getByText("bos_live_").first()).toBeVisible();
  await shot(page, "settings");

  await page.goto("/customers");
  await page.getByText("Globex Corp").click();
  await page.getByRole("button", { name: "Ссылка в портал" }).click();
  const portalUrl = await page.locator("a", { hasText: "/portal/bps_" }).innerText();
  const portal = await browser.newPage({ viewport: { width: 1100, height: 800 } });
  await portal.goto(portalUrl);
  await expect(portal.getByText("Globex Corp").first()).toBeVisible();
  await shot(portal, "portal");
  await portal.getByRole("link", { name: "Инвойсы" }).click();
  await portal.getByText("INV-000001").click();
  await expect(portal.getByText("Итого")).toBeVisible();
  await shot(portal, "portal-invoice");
  await portal.close();

  // Scalar грузит бандл с CDN - ждём сеть, а не только DOM
  await page.goto("/api/docs", { waitUntil: "networkidle", timeout: 60_000 });
  await expect(page.getByRole("heading", { name: "BillingOS API" })).toBeVisible({ timeout: 60_000 });
  await shot(page, "api-docs");
});
