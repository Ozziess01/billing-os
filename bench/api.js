// k6-сценарии для BillingOS. Стек уже поднят; запуск:
//   docker run --rm -i --network billingos-prod_default -v "$PWD/bench:/bench" grafana/k6 run \
//     -e BASE=http://nginx/api/v1 /bench/api.js
//
// Четыре потока нагрузки: чтение списков, оформление подписки с автосписанием
// (инвойс + платёж + леджер в одной транзакции), ручная оплата инвойса с
// Idempotency-Key и поток usage-событий. Лимит POST /payments - 60 в минуту на
// пользователя, поэтому setup регистрирует несколько аккаунтов и VU делят их.
import http from "k6/http";
import exec from "k6/execution";
import { check, fail } from "k6";
import { randomString } from "https://jslib.k6.io/k6-utils/1.4.0/index.js";

const BASE = __ENV.BASE || "http://localhost:8090/api/v1";
const ACCOUNTS = Number(__ENV.ACCOUNTS || 5);
const DURATION = __ENV.DURATION || "60s";

export const options = {
  insecureSkipTLSVerify: true,
  scenarios: {
    reads: {
      executor: "constant-arrival-rate",
      exec: "reads",
      rate: Number(__ENV.READ_RPS || 50),
      timeUnit: "1s",
      duration: DURATION,
      preAllocatedVUs: 20,
      maxVUs: 60,
    },
    subscribe: {
      executor: "constant-arrival-rate",
      exec: "subscribe",
      rate: Number(__ENV.SUBSCRIBE_RPS || 5),
      timeUnit: "1s",
      duration: DURATION,
      preAllocatedVUs: 10,
      maxVUs: 40,
    },
    pay: {
      executor: "constant-arrival-rate",
      exec: "pay",
      rate: Number(__ENV.PAY_RPS || 4),
      timeUnit: "1s",
      duration: DURATION,
      preAllocatedVUs: 10,
      maxVUs: 40,
    },
    usage: {
      executor: "constant-arrival-rate",
      exec: "usage",
      rate: Number(__ENV.USAGE_RPS || 20),
      timeUnit: "1s",
      duration: DURATION,
      preAllocatedVUs: 10,
      maxVUs: 40,
    },
  },
  thresholds: {
    http_req_failed: ["rate<0.01"],
    "http_req_duration{scenario:reads}": ["p(95)<400"],
    "http_req_duration{scenario:usage}": ["p(95)<400"],
    "http_req_duration{scenario:subscribe}": ["p(95)<2000"],
    "http_req_duration{scenario:pay}": ["p(95)<2000"],
    checks: ["rate>0.99"],
  },
};

const json = (token, extra = {}) => ({
  headers: { "Content-Type": "application/json", Accept: "application/json", Authorization: `Bearer ${token}`, ...extra },
});

function post(url, body, params, expected = 201) {
  const res = http.post(url, JSON.stringify(body), params);
  if (res.status !== expected) {
    fail(`${url} -> ${res.status}: ${res.body.slice(0, 200)}`);
  }
  return res.json();
}

// один аккаунт = организация + продукт с фиксированной и metered ценой + клиент с картой
export function setup() {
  const accounts = [];
  for (let i = 0; i < ACCOUNTS; i++) {
    const email = `bench-${Date.now()}-${i}-${randomString(6)}@example.com`;
    const auth = post(
      `${BASE}/auth/register`,
      { name: `Bench ${i}`, email, password: "bench-password", password_confirmation: "bench-password", organization: `Bench org ${i}` },
      { headers: { "Content-Type": "application/json", Accept: "application/json" } },
    );
    const token = auth.token;
    const product = post(`${BASE}/products`, { name: "Bench plan" }, json(token)).data;
    const price = post(`${BASE}/prices`, { product_id: product.id, currency: "EUR", unit_amount: 1999, billing_interval: "month" }, json(token)).data;
    const metered = post(`${BASE}/prices`, { product_id: product.id, currency: "EUR", usage_type: "metered", unit_amount_decimal: "0.5", billing_interval: "month" }, json(token)).data;
    const customer = post(`${BASE}/customers`, { name: "Bench customer", default_payment_method: "tok_ok" }, json(token)).data;
    const subscription = post(
      `${BASE}/subscriptions`,
      { customer_id: customer.id, items: [{ price_id: price.id }, { price_id: metered.id }] },
      json(token),
    ).data;
    const meteredItem = subscription.items.find((item) => item.usage_type === "metered");
    accounts.push({ token, priceId: price.id, customerId: customer.id, meteredItemId: meteredItem.id });
  }

  // прогрев: первый запрос к каждому пути компилирует код в opcache и греет кеши БД,
  // без этого p95 первых секунд - про холодный старт, а не про приложение
  for (const { token } of accounts) {
    for (const page of ["/customers", "/subscriptions", "/invoices", "/payments", "/dashboard"]) {
      http.get(`${BASE}${page}`, json(token));
    }
  }
  return { accounts };
}

// аккаунты по кругу по глобальному счётчику итераций сценария: arrival-rate
// раздаёт итерации первым свободным VU, и привязка к __VU перекосила бы лимит на пользователя
const account = (data) => data.accounts[exec.scenario.iterationInTest % data.accounts.length];

export function reads(data) {
  const { token } = account(data);
  const pages = ["/customers", "/subscriptions", "/invoices", "/payments", "/dashboard"];
  const res = http.get(`${BASE}${pages[Math.floor(Math.random() * pages.length)]}`, json(token));
  check(res, { "list 200": (r) => r.status === 200 });
}

export function subscribe(data) {
  const { token, priceId } = account(data);
  const customer = post(`${BASE}/customers`, { name: `Sub ${randomString(6)}`, default_payment_method: "tok_ok" }, json(token)).data;
  const res = http.post(`${BASE}/subscriptions`, JSON.stringify({ customer_id: customer.id, items: [{ price_id: priceId, quantity: 2 }] }), json(token));
  check(res, {
    "subscription 201": (r) => r.status === 201,
    "active after auto-collect": (r) => r.status === 201 && r.json("data.status") === "active",
  });
}

export function pay(data) {
  const { token, customerId } = account(data);
  const invoice = post(`${BASE}/invoices`, { customer_id: customerId, items: [{ description: "Bench line", unit_amount: 2500 }] }, json(token)).data;
  post(`${BASE}/invoices/${invoice.id}/finalize`, {}, json(token), 200);
  const res = http.post(`${BASE}/payments`, JSON.stringify({ invoice_id: invoice.id, payment_method: "tok_ok" }), json(token, { "Idempotency-Key": `bench-${invoice.id}` }));
  check(res, {
    "payment 201": (r) => r.status === 201,
    "succeeded": (r) => r.status === 201 && r.json("data.status") === "succeeded",
  });
}

export function usage(data) {
  const { token, meteredItemId } = account(data);
  const res = http.post(
    `${BASE}/usage`,
    JSON.stringify({ subscription_item_id: meteredItemId, quantity: 1 + Math.floor(Math.random() * 50), idempotency_key: `bench-${__VU}-${__ITER}-${randomString(4)}` }),
    json(token),
  );
  check(res, { "usage 201": (r) => r.status === 201 });
}
