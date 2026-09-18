import { api, query } from "@/lib/api";
import type { Customer, Metadata, Paginated, Wrapped } from "@/types";

export interface CustomerInput {
  name: string;
  email?: string | null;
  external_id?: string | null;
  description?: string | null;
  default_payment_method?: string | null;
  metadata?: Metadata | null;
}

export const customers = {
  list: (params: { q?: string; page?: number; per_page?: number } = {}) => api.get<Paginated<Customer>>(`/customers${query(params)}`),
  get: (id: string) => api.get<Wrapped<Customer>>(`/customers/${id}`),
  create: (body: CustomerInput) => api.post<Wrapped<Customer>>("/customers", body),
  update: (id: string, body: Partial<CustomerInput>) => api.patch<Wrapped<Customer>>(`/customers/${id}`, body),
  remove: (id: string) => api.delete<void>(`/customers/${id}`),
  portalSession: (id: string) => api.post<Wrapped<{ url: string; token: string; expires_at: string }>>(`/customers/${id}/portal-session`),
};
