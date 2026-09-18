import { api, query } from "@/lib/api";
import type { Coupon, Paginated, Subscription, Wrapped } from "@/types";

export interface CouponInput {
  code: string;
  name: string;
  type: "percent" | "fixed";
  percent_off?: number | null;
  amount_off?: number | null;
  currency?: string | null;
  duration?: "once" | "forever";
  redeem_by?: string | null;
  max_redemptions?: number | null;
  customer_id?: string | null;
}

export const coupons = {
  list: (params: { active?: boolean; page?: number } = {}) => api.get<Paginated<Coupon>>(`/coupons${query(params)}`),
  create: (body: CouponInput) => api.post<Wrapped<Coupon>>("/coupons", body),
  update: (id: string, body: { name?: string; active?: boolean }) => api.patch<Wrapped<Coupon>>(`/coupons/${id}`, body),
  apply: (subscriptionId: string, code: string) => api.post<Wrapped<Subscription>>(`/subscriptions/${subscriptionId}/coupon`, { coupon_code: code }),
};
