export type Role = "owner" | "admin" | "developer" | "viewer";

export interface User {
  id: number;
  name: string;
  email: string;
  created_at: string;
}

export interface Organization {
  id: number;
  name: string;
  slug: string;
  default_currency: string;
  owner_id: number;
  role?: Role;
  members_count?: number;
  created_at: string;
}

export interface Member {
  id: number;
  user_id: number;
  name: string;
  email: string;
  role: Role;
  joined_at: string;
}

export interface Money {
  amount: number;
  currency: string;
  formatted: string;
}

export type Metadata = Record<string, string | number | boolean | null>;

export interface Customer {
  id: string;
  external_id: string | null;
  name: string;
  email: string | null;
  description: string | null;
  metadata: Metadata;
  subscriptions_count?: number;
  subscriptions?: Subscription[];
  created_at: string;
  updated_at: string;
}

export interface Product {
  id: string;
  name: string;
  description: string | null;
  active: boolean;
  metadata: Metadata;
  prices?: Price[];
  prices_count?: number;
  created_at: string;
  updated_at: string;
}

export type BillingInterval = "day" | "week" | "month" | "year";

export interface Price {
  id: string;
  product_id: string;
  product?: Product;
  nickname: string | null;
  currency: string;
  unit_amount: number;
  unit_amount_formatted: string;
  billing_interval: BillingInterval;
  interval_count: number;
  active: boolean;
  metadata: Metadata;
  created_at: string;
}

export type SubscriptionStatus = "trialing" | "active" | "past_due" | "canceled" | "incomplete";

export interface SubscriptionItem {
  id: string;
  price_id: string;
  price?: Price;
  quantity: number;
  amount?: Money;
}

export interface Subscription {
  id: string;
  customer_id: string;
  customer?: Customer;
  status: SubscriptionStatus;
  currency: string;
  period_amount?: Money;
  trial_ends_at: string | null;
  current_period_start: string;
  current_period_end: string;
  cancel_at_period_end: boolean;
  canceled_at: string | null;
  items?: SubscriptionItem[];
  metadata: Metadata;
  created_at: string;
  updated_at: string;
}

export interface Dashboard {
  customers: number;
  products: number;
  subscriptions: {
    total: number;
    by_status: Record<SubscriptionStatus, number>;
  };
  mrr: Money[];
  recent_subscriptions: Subscription[];
}

export interface Paginated<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface Wrapped<T> {
  data: T;
}
