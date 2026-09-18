import { api, query } from "@/lib/api";
import type { Paginated, UsageEvent, UsageSummary, Wrapped } from "@/types";

export const usage = {
  list: (params: { subscription_item_id?: string; customer_id?: string; page?: number } = {}) => api.get<Paginated<UsageEvent>>(`/usage${query(params)}`),
  record: (body: { subscription_item_id: string; quantity: number; timestamp?: string; idempotency_key?: string }) => api.post<Wrapped<UsageEvent>>("/usage", body),
  summary: (subscriptionId: string) => api.get<Wrapped<UsageSummary>>(`/subscriptions/${subscriptionId}/usage`),
};
