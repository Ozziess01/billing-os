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
  default_payment_method: string | null;
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
  usage_type: "licensed" | "metered";
  unit_amount_decimal: string | null;
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
  usage_type?: "licensed" | "metered";
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
  cancel_reason: string | null;
  ended_at: string | null;
  coupon?: Coupon | null;
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
  coupon_id: string | null;
  auto_collect: boolean;
  collection_attempts: number;
  next_payment_attempt_at: string | null;
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

export interface Coupon {
  id: string;
  code: string;
  name: string;
  type: "percent" | "fixed";
  percent_off: number | null;
  amount_off: Money | null;
  currency: string | null;
  duration: "once" | "forever";
  redeem_by: string | null;
  max_redemptions: number | null;
  times_redeemed: number;
  customer_id: string | null;
  active: boolean;
  valid: boolean;
  created_at: string;
}

export interface UsageEvent {
  id: string;
  subscription_item_id: string;
  customer_id: string;
  quantity: number;
  timestamp: string;
  idempotency_key: string | null;
  invoice_item_id: string | null;
  created_at: string;
}

export interface UsageSummary {
  subscription_id: string;
  period_start: string;
  period_end: string;
  items: { subscription_item_id: string; price_id: string; units: number; unbilled_units: number; estimated_amount: number }[];
}

export interface AppNotification {
  id: string;
  event: string | null;
  title: string;
  body: string;
  resource: { type: string; id: string } | null;
  read_at: string | null;
  created_at: string;
}

export interface NotificationPreference {
  event: string;
  label: string;
  in_app: boolean;
  mail: boolean;
}

export interface ApiKey {
  id: string;
  name: string;
  prefix: string;
  created_by: string | null;
  last_used_at: string | null;
  expires_at: string | null;
  revoked_at: string | null;
  active: boolean;
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
