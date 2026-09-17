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

export type InvoiceStatus = "draft" | "open" | "paid" | "void" | "uncollectible";

export interface InvoiceItem {
  id: string;
  price_id: string | null;
  description: string;
  quantity: number;
  unit_amount: Money;
  amount: Money;
  period_start: string | null;
  period_end: string | null;
}

export interface Invoice {
  id: string;
  number: string | null;
  status: InvoiceStatus;
  customer_id: string;
  customer?: Customer;
  subscription_id: string | null;
  currency: string;
  subtotal: Money;
  discount: Money;
  total: Money;
  amount_paid: Money;
  amount_due: Money;
  description: string | null;
  period_start: string | null;
  period_end: string | null;
  due_at: string | null;
  finalized_at: string | null;
  paid_at: string | null;
  voided_at: string | null;
  uncollectible_at: string | null;
  items?: InvoiceItem[];
  payments?: Payment[];
  metadata: Metadata;
  created_at: string;
  updated_at: string;
}

export type PaymentStatus = "pending" | "processing" | "succeeded" | "failed" | "canceled";

export interface Payment {
  id: string;
  invoice_id: string;
  invoice?: Invoice;
  customer_id: string;
  customer?: Customer;
  attempt_number: number;
  status: PaymentStatus;
  amount: Money;
  amount_refunded: Money;
  refundable_amount: Money;
  provider: string;
  provider_payment_id: string | null;
  payment_method: string;
  failure_code: string | null;
  failure_message: string | null;
  next_action: { type: string; provider_payment_id?: string } | null;
  refunds?: Refund[];
  succeeded_at: string | null;
  failed_at: string | null;
  canceled_at: string | null;
  created_at: string;
}

export type RefundStatus = "pending" | "succeeded" | "failed";

export interface Refund {
  id: string;
  payment_id: string;
  status: RefundStatus;
  amount: Money;
  reason: string | null;
  provider_refund_id: string | null;
  failure_message: string | null;
  succeeded_at: string | null;
  failed_at: string | null;
  created_at: string;
}

export type LedgerAccountType = "cash" | "receivable" | "revenue" | "adjustments";

export interface LedgerAccount {
  id: string;
  type: LedgerAccountType;
  name: string;
  currency: string;
  balance: Money;
}

export interface LedgerEntry {
  account_id: string;
  account_type: LedgerAccountType | null;
  account_name: string | null;
  debit: Money;
  credit: Money;
}

export interface LedgerTransaction {
  id: string;
  type: "invoice" | "payment" | "refund" | "adjustment";
  description: string;
  amount: Money;
  reference_type: string;
  reference_id: string;
  posted_at: string;
  entries?: LedgerEntry[];
}

export interface WebhookEvent {
  id: string;
  provider: string;
  event_id: string;
  type: string;
  status: "received" | "processing" | "processed" | "ignored" | "failed";
  attempts: number;
  error: string | null;
  payload: Record<string, unknown>;
  processed_at: string | null;
  created_at: string;
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
