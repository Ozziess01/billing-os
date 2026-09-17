import { api, query } from "@/lib/api";
import type { BillingInterval, Metadata, Paginated, Price, Product, Wrapped } from "@/types";

export interface ProductInput {
  name: string;
  description?: string | null;
  active?: boolean;
  metadata?: Metadata | null;
}

export interface PriceInput {
  product_id: string;
  nickname?: string | null;
  currency: string;
  unit_amount: number;
  billing_interval: BillingInterval;
  interval_count?: number;
}

export const products = {
  list: (params: { active?: boolean; page?: number; per_page?: number } = {}) => api.get<Paginated<Product>>(`/products${query(params)}`),
  get: (id: string) => api.get<Wrapped<Product>>(`/products/${id}`),
  create: (body: ProductInput) => api.post<Wrapped<Product>>("/products", body),
  update: (id: string, body: Partial<ProductInput>) => api.patch<Wrapped<Product>>(`/products/${id}`, body),
  remove: (id: string) => api.delete<void>(`/products/${id}`),
};

export const prices = {
  list: (params: { product_id?: string; active?: boolean; page?: number; per_page?: number } = {}) => api.get<Paginated<Price>>(`/prices${query(params)}`),
  create: (body: PriceInput) => api.post<Wrapped<Price>>("/prices", body),
  update: (id: string, body: { nickname?: string | null; active?: boolean }) => api.patch<Wrapped<Price>>(`/prices/${id}`, body),
  remove: (id: string) => api.delete<void>(`/prices/${id}`),
};
