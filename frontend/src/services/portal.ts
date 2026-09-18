import type { Customer, Invoice, Payment, Subscription, Wrapped } from "@/types";

const BASE = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8090/api/v1";

export class PortalError extends Error {
  status: number;
  errors: Record<string, string[]>;

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message);
    this.status = status;
    this.errors = errors;
  }
}

/** Клиентский портал ходит по токену сессии из ссылки, без пользовательской учётки. */
async function request<T>(token: string, method: "GET" | "POST" | "PATCH", path: string, body?: unknown): Promise<T> {
  const res = await fetch(`${BASE}/portal${path}`, {
    method,
    headers: {
      Accept: "application/json",
      Authorization: `Bearer ${token}`,
      ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new PortalError(res.status, data.message ?? res.statusText, data.errors ?? {});
  return data as T;
}

export interface PortalSession {
  customer: Customer;
  organization: { name: string; default_currency: string };
  expires_at: string;
}

export const portal = {
  session: (token: string) => request<Wrapped<PortalSession>>(token, "GET", "/session"),
  updateBilling: (token: string, body: { name?: string; email?: string | null; default_payment_method?: string | null }) =>
    request<Wrapped<Customer>>(token, "PATCH", "/billing", body),
  subscriptions: (token: string) => request<{ data: Subscription[] }>(token, "GET", "/subscriptions"),
  cancel: (token: string, id: string, atPeriodEnd: boolean) => request<Wrapped<Subscription>>(token, "POST", `/subscriptions/${id}/cancel`, { at_period_end: atPeriodEnd }),
  invoices: (token: string) => request<{ data: Invoice[] }>(token, "GET", "/invoices"),
  invoice: (token: string, id: string) => request<Wrapped<Invoice>>(token, "GET", `/invoices/${id}`),
  pay: (token: string, id: string, paymentMethod: string) => request<Wrapped<Payment>>(token, "POST", `/invoices/${id}/pay`, { payment_method: paymentMethod }),
  payments: (token: string) => request<{ data: Payment[] }>(token, "GET", "/payments"),
  exportUrl: (id: string, format: "csv" | "json") => `${BASE}/portal/invoices/${id}/export?format=${format}`,
};
