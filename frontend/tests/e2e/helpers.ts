import { expect, type Locator, type Page } from "@playwright/test";

export const PASSWORD = "secret-password";

export async function register(page: Page, name: string, organization: string) {
  const email = `${name.toLowerCase().replace(/\s+/g, "-")}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.com`;
  await page.goto("/register");
  await page.getByLabel("Имя").fill(name);
  await page.getByLabel("Почта").fill(email);
  await page.getByLabel("Пароль", { exact: true }).fill(PASSWORD);
  await page.getByLabel("Пароль ещё раз").fill(PASSWORD);
  await page.getByLabel("Организация").fill(organization);
  await page.getByRole("button", { name: "Создать аккаунт" }).click();
  await page.waitForURL("**/dashboard");
  return email;
}

export async function login(page: Page, email: string) {
  await page.goto("/login");
  await page.getByLabel("Почта").fill(email);
  await page.getByLabel("Пароль").fill(PASSWORD);
  await page.getByRole("button", { name: "Войти" }).click();
  await page.waitForURL("**/dashboard");
}

export async function createProduct(page: Page, name: string, prices: { name: string; amount: string; metered?: boolean }[]) {
  await page.goto("/products");
  await page.getByRole("button", { name: "Новый продукт" }).click();
  await page.getByLabel("Название").fill(name);
  await page.getByRole("button", { name: "Создать" }).click();
  await expect(page.getByText("Цен пока нет")).toBeVisible();
  for (const price of prices) {
    await page.getByRole("button", { name: "Добавить цену" }).click();
    await page.getByLabel("Название цены").fill(price.name);
    if (price.metered) {
      await page.getByLabel("Тип").selectOption("metered");
      await page.getByLabel(/Цена за единицу/).fill(price.amount);
    } else {
      await page.getByLabel("Сумма за единицу").fill(price.amount);
    }
    await page.getByRole("button", { name: "Создать цену" }).click();
    await expect(page.getByText(price.name).first()).toBeVisible();
  }
}

export async function createCustomer(page: Page, name: string, paymentMethod?: string) {
  await page.goto("/customers");
  await page.getByRole("button", { name: "Новый клиент" }).click();
  await page.getByLabel("Название").fill(name);
  await page.getByLabel("Почта").fill(`${name.toLowerCase().replace(/\s+/g, ".")}@customer.test`);
  if (paymentMethod) {
    await page.getByLabel("Платёжный метод для автосписания").selectOption(paymentMethod);
  }
  await page.getByRole("button", { name: "Создать" }).click();
  await page.waitForURL("**/customers/*");
  return page.url().split("/").pop()!;
}

export async function optionValue(select: Locator, text: string) {
  return (await select.locator("option").filter({ hasText: text }).first().getAttribute("value"))!;
}

// подписка из карточки клиента; страница уже открыта на /customers/{id}
export async function subscribe(page: Page, priceName: string, opts: { quantity?: string; coupon?: string; extraPrices?: string[] } = {}) {
  await page.getByRole("button", { name: "Оформить подписку" }).click();
  const dialog = page.getByRole("dialog");
  await expect(dialog).toBeVisible();
  const priceSelect = dialog.locator("select").nth(1);
  await priceSelect.selectOption(await optionValue(priceSelect, priceName));
  if (opts.quantity) await dialog.getByLabel("Количество").first().fill(opts.quantity);
  for (const [i, extra] of (opts.extraPrices ?? []).entries()) {
    await dialog.getByRole("button", { name: "+ ещё позиция" }).click();
    const select = dialog.locator("select").nth(i + 2);
    await select.selectOption(await optionValue(select, extra));
  }
  if (opts.coupon) await dialog.getByLabel("Купон").fill(opts.coupon);
  await dialog.getByRole("button", { name: "Оформить подписку" }).click();
  await page.waitForURL("**/subscriptions/*");
  await expect(page.getByText("Итого за период")).toBeVisible();
  return page.url().split("/").pop()!;
}

export async function payInvoice(page: Page, method: string) {
  await page.getByRole("button", { name: "Оплатить" }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByLabel("Платёжный метод").selectOption(method);
  await dialog.getByRole("button", { name: /^Оплатить/ }).click();
  return dialog;
}

// карточка счёта на странице леджера: "Денежные средства · EUR" и баланс под ней
export function ledgerCard(page: Page, account: string, currency = "EUR") {
  return page.getByText(`${account} · ${currency}`, { exact: true }).locator("..");
}

export function apiHeaders(token: string) {
  return { Authorization: `Bearer ${token}`, Accept: "application/json" };
}

// токен текущей сессии из localStorage: для запросов к API мимо интерфейса
export async function sessionToken(page: Page) {
  return page.evaluate(() => window.localStorage.getItem("billingos_token") ?? "");
}
