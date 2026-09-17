import { api, idempotencyKey, query } from "@/lib/api";
import type { Paginated, Payment, PaymentStatus, Refund, Wrapped } from "@/types";

export const payments = {
  list: (params: { status?: PaymentStatus | ""; invoice_id?: string; customer_id?: string; page?: number; per_page?: number } = {}) =>
    api.get<Paginated<Payment>>(`/payments${query(params)}`),
  get: (id: string) => api.get<Wrapped<Payment>>(`/payments/${id}`),
  // ключ на попытку: пока пользователь не нажмёт снова, повтор запроса вернёт тот же платёж
  pay: (body: { invoice_id: string; payment_method: string }, key = idempotencyKey()) => api.post<Wrapped<Payment>>("/payments", body, { idempotencyKey: key }),
  refund: (id: string, body: { amount?: number | null; reason?: string | null }, key = idempotencyKey()) =>
    api.post<Wrapped<Refund>>(`/payments/${id}/refund`, body, { idempotencyKey: key }),
  cancel: (id: string) => api.post<Wrapped<Payment>>(`/payments/${id}/cancel`),
  confirmFake: (providerPaymentId: string, success = true) => api.post<{ confirmed: boolean }>(`/providers/fake/payments/${providerPaymentId}/confirm`, { success }),
  refunds: (params: { payment_id?: string; page?: number } = {}) => api.get<Paginated<Refund>>(`/refunds${query(params)}`),
};

/** Платёжные методы fake-провайдера: исход известен заранее, удобно для демо и E2E. */
export const paymentMethods = [
  { value: "tok_ok", label: "Карта: успешно" },
  { value: "tok_async", label: "Карта: успех придёт вебхуком через пару секунд" },
  { value: "tok_action", label: "Карта: нужно подтверждение (3-D Secure)" },
  { value: "tok_fail", label: "Карта: отклонена банком" },
  { value: "tok_insufficient", label: "Карта: недостаточно средств" },
  { value: "tok_ok_norefund", label: "Карта: успешно, возвраты не проходят" },
  { value: "tok_error", label: "Провайдер недоступен" },
];
