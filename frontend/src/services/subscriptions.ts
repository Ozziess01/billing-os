import { api, query } from "@/lib/api";
import type { Dashboard, Metadata, Paginated, Subscription, SubscriptionStatus, Wrapped } from "@/types";

export interface SubscriptionInput {
  customer_id: string;
  items: { price_id: string; quantity?: number }[];
  trial_days?: number;
  starts_at?: string;
  coupon_code?: string;
  metadata?: Metadata | null;
}

export const subscriptions = {
  list: (params: { status?: SubscriptionStatus | ""; customer_id?: string; page?: number; per_page?: number } = {}) =>
    api.get<Paginated<Subscription>>(`/subscriptions${query(params)}`),
  get: (id: string) => api.get<Wrapped<Subscription>>(`/subscriptions/${id}`),
  create: (body: SubscriptionInput) => api.post<Wrapped<Subscription>>("/subscriptions", body),
  cancel: (id: string, atPeriodEnd: boolean) => api.post<Wrapped<Subscription>>(`/subscriptions/${id}/cancel`, { at_period_end: atPeriodEnd }),
  resume: (id: string) => api.post<Wrapped<Subscription>>(`/subscriptions/${id}/resume`),
};

export const dashboard = {
  get: () => api.get<Wrapped<Dashboard>>("/dashboard"),
};
