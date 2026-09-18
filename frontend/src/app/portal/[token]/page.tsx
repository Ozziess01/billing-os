"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { usePortal } from "@/components/portal/PortalShell";
import { StatusBadge } from "@/components/subscriptions/StatusBadge";
import { Button, Card, Empty, ErrorNote, Money } from "@/components/ui";
import { formatDate } from "@/lib/format";
import { formatMoney, itemLabel } from "@/lib/money";
import { portal } from "@/services/portal";

export default function PortalSubscriptionsPage() {
  const { token } = usePortal();
  const client = useQueryClient();
  const list = useQuery({ queryKey: ["portal", token, "subscriptions"], queryFn: () => portal.subscriptions(token) });
  const cancel = useMutation({
    mutationFn: ({ id, atPeriodEnd }: { id: string; atPeriodEnd: boolean }) => portal.cancel(token, id, atPeriodEnd),
    onSuccess: () => client.invalidateQueries({ queryKey: ["portal", token] }),
  });

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Подписки</h1>
      {cancel.error && <ErrorNote error={cancel.error} />}
      {list.data?.data.map((s) => (
        <Card key={s.id} className="px-5 py-4">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <div className="flex items-center gap-2">
                <StatusBadge status={s.status} cancelAtPeriodEnd={s.cancel_at_period_end} />
                {s.coupon && <span className="text-xs text-muted">купон {s.coupon.code}</span>}
              </div>
              <div className="mt-2 text-sm">{s.items?.map(itemLabel).join(", ")}</div>
              <div className="mt-1 text-xs text-muted">
                Период {formatDate(s.current_period_start)} — {formatDate(s.current_period_end)}
                {s.trial_ends_at && ` · триал до ${formatDate(s.trial_ends_at)}`}
                {s.ended_at && ` · завершена ${formatDate(s.ended_at)}`}
              </div>
            </div>
            <div className="text-right">
              {s.period_amount && <div className="font-mono text-lg"><Money formatted={formatMoney(s.period_amount.amount, s.period_amount.currency)} /></div>}
              {s.status !== "canceled" && !s.cancel_at_period_end && (
                <Button variant="ghost" size="sm" className="mt-2" disabled={cancel.isPending} onClick={() => confirm("Отменить подписку в конце оплаченного периода?") && cancel.mutate({ id: s.id, atPeriodEnd: true })}>
                  Отменить в конце периода
                </Button>
              )}
              {s.status !== "canceled" && s.cancel_at_period_end && <div className="mt-2 text-xs text-muted">Завершится {formatDate(s.current_period_end)}</div>}
            </div>
          </div>
        </Card>
      ))}
      {list.data && list.data.data.length === 0 && (
        <Card>
          <Empty>Подписок нет.</Empty>
        </Card>
      )}
    </div>
  );
}
