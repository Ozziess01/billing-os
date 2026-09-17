import { Badge, type Tone } from "@/components/ui";
import type { SubscriptionStatus } from "@/types";

const labels: Record<SubscriptionStatus, [string, Tone]> = {
  trialing: ["Триал", "accent"],
  active: ["Активна", "ok"],
  past_due: ["Просрочена", "warn"],
  canceled: ["Отменена", "muted"],
  incomplete: ["Не оплачена", "err"],
};

export function StatusBadge({ status, cancelAtPeriodEnd = false }: { status: SubscriptionStatus; cancelAtPeriodEnd?: boolean }) {
  const [label, tone] = labels[status] ?? [status, "muted"];
  return (
    <span className="inline-flex items-center gap-1.5">
      <Badge tone={tone}>{label}</Badge>
      {cancelAtPeriodEnd && status !== "canceled" && <Badge tone="warn">отмена в конце периода</Badge>}
    </span>
  );
}
