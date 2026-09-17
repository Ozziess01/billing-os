import { Badge, type Tone } from "@/components/ui";
import type { InvoiceStatus, PaymentStatus, RefundStatus } from "@/types";

const invoiceLabels: Record<InvoiceStatus, [string, Tone]> = {
  draft: ["Черновик", "muted"],
  open: ["Открыт", "accent"],
  paid: ["Оплачен", "ok"],
  void: ["Аннулирован", "muted"],
  uncollectible: ["Безнадёжный", "err"],
};

const paymentLabels: Record<PaymentStatus, [string, Tone]> = {
  pending: ["Создан", "muted"],
  processing: ["В обработке", "warn"],
  succeeded: ["Успешно", "ok"],
  failed: ["Отказ", "err"],
  canceled: ["Отменён", "muted"],
};

const refundLabels: Record<RefundStatus, [string, Tone]> = {
  pending: ["В обработке", "warn"],
  succeeded: ["Возвращено", "ok"],
  failed: ["Отказ", "err"],
};

export function InvoiceStatusBadge({ status }: { status: InvoiceStatus }) {
  const [label, tone] = invoiceLabels[status] ?? [status, "muted"];
  return <Badge tone={tone}>{label}</Badge>;
}

export function PaymentStatusBadge({ status }: { status: PaymentStatus }) {
  const [label, tone] = paymentLabels[status] ?? [status, "muted"];
  return <Badge tone={tone}>{label}</Badge>;
}

export function RefundStatusBadge({ status }: { status: RefundStatus }) {
  const [label, tone] = refundLabels[status] ?? [status, "muted"];
  return <Badge tone={tone}>{label}</Badge>;
}
