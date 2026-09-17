import { api, query } from "@/lib/api";
import type { LedgerAccount, LedgerTransaction, Paginated, WebhookEvent } from "@/types";

export const ledger = {
  accounts: () => api.get<{ data: LedgerAccount[] }>("/ledger/accounts"),
  transactions: (params: { type?: string; reference_id?: string; currency?: string; page?: number } = {}) =>
    api.get<Paginated<LedgerTransaction>>(`/ledger/transactions${query(params)}`),
};

export const webhooks = {
  events: (params: { page?: number } = {}) => api.get<Paginated<WebhookEvent>>(`/webhooks/events${query(params)}`),
};
