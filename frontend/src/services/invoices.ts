import { api, idempotencyKey, query } from "@/lib/api";
import type { Invoice, InvoiceStatus, Paginated, Wrapped } from "@/types";

export interface InvoiceItemInput {
  price_id?: string | null;
  description?: string | null;
  quantity?: number;
  unit_amount?: number | null;
}

export interface InvoiceInput {
  customer_id: string;
  currency?: string;
  description?: string | null;
  items?: InvoiceItemInput[];
}

export const invoices = {
  list: (params: { status?: InvoiceStatus | ""; customer_id?: string; subscription_id?: string; page?: number; per_page?: number } = {}) =>
    api.get<Paginated<Invoice>>(`/invoices${query(params)}`),
  get: (id: string) => api.get<Wrapped<Invoice>>(`/invoices/${id}`),
  create: (body: InvoiceInput) => api.post<Wrapped<Invoice>>("/invoices", body, { idempotencyKey: idempotencyKey() }),
  forSubscription: (subscriptionId: string) => api.post<Wrapped<Invoice>>(`/subscriptions/${subscriptionId}/invoice`, undefined, { idempotencyKey: idempotencyKey() }),
  addItem: (id: string, body: InvoiceItemInput) => api.post<Wrapped<Invoice>>(`/invoices/${id}/items`, body),
  removeItem: (id: string, itemId: string) => api.delete<Wrapped<Invoice>>(`/invoices/${id}/items/${itemId}`),
  finalize: (id: string) => api.post<Wrapped<Invoice>>(`/invoices/${id}/finalize`, undefined, { idempotencyKey: idempotencyKey() }),
  void: (id: string) => api.post<Wrapped<Invoice>>(`/invoices/${id}/void`),
  uncollectible: (id: string) => api.post<Wrapped<Invoice>>(`/invoices/${id}/uncollectible`),
  remove: (id: string) => api.delete<void>(`/invoices/${id}`),
};
