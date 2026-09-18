import { expect, test } from "@playwright/test";
import { apiHeaders, createCustomer, register, sessionToken } from "./helpers";

// Данные одной организации не видны из другой ни в интерфейсе, ни по API
test("tenants cannot see each other", async ({ browser }) => {
  const alice = await browser.newPage();
  await register(alice, "Alice", "Wonderland Inc");
  const customerId = await createCustomer(alice, "Cheshire Cat");
  const aliceToken = await sessionToken(alice);

  const bob = await browser.newPage();
  await register(bob, "Bob", "Builders Ltd");
  const bobToken = await sessionToken(bob);

  await bob.goto(`/customers/${customerId}`);
  await expect(bob.getByText("Cheshire Cat")).toHaveCount(0);
  await expect(bob.getByText("Не найдено.")).toBeVisible();

  const api = process.env.BOS_URL ?? "http://localhost:8090";
  const foreign = await bob.request.get(`${api}/api/v1/customers/${customerId}`, { headers: apiHeaders(bobToken) });
  expect(foreign.status()).toBe(404);
  const own = await alice.request.get(`${api}/api/v1/customers/${customerId}`, { headers: apiHeaders(aliceToken) });
  expect(own.status()).toBe(200);

  await bob.goto("/customers");
  await expect(bob.getByText("Cheshire Cat")).toHaveCount(0);
});

test("the same idempotency key replays the first response instead of charging twice", async ({ page }) => {
  await register(page, "Idem", "Once Only");
  const token = await sessionToken(page);
  const api = process.env.BOS_URL ?? "http://localhost:8090";
  const headers = apiHeaders(token);

  const customer = await page.request.post(`${api}/api/v1/customers`, { headers, data: { name: "Replay" } });
  const customerId = (await customer.json()).data.id;
  const invoice = await page.request.post(`${api}/api/v1/invoices`, {
    headers,
    data: { customer_id: customerId, items: [{ description: "One-off", unit_amount: 1500 }] },
  });
  const invoiceId = (await invoice.json()).data.id;
  await page.request.post(`${api}/api/v1/invoices/${invoiceId}/finalize`, { headers });

  const key = `e2e-${Date.now()}`;
  const first = await page.request.post(`${api}/api/v1/payments`, { headers: { ...headers, "Idempotency-Key": key }, data: { invoice_id: invoiceId, payment_method: "tok_ok" } });
  const second = await page.request.post(`${api}/api/v1/payments`, { headers: { ...headers, "Idempotency-Key": key }, data: { invoice_id: invoiceId, payment_method: "tok_ok" } });
  expect(first.status()).toBe(201);
  expect(second.status()).toBe(201);
  expect(second.headers()["idempotent-replayed"]).toBe("true");
  expect((await second.json()).data.id).toBe((await first.json()).data.id);

  const payments = await page.request.get(`${api}/api/v1/payments?invoice_id=${invoiceId}`, { headers });
  expect((await payments.json()).data).toHaveLength(1);
});
