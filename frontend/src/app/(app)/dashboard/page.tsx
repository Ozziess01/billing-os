"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { StatusBadge } from "@/components/subscriptions/StatusBadge";
import { Card, Empty, Money, PageTitle, Stat, Table, Td, Th } from "@/components/ui";
import { useOrganization } from "@/hooks/useAuth";
import { formatDate } from "@/lib/format";
import { formatMoney, itemLabel } from "@/lib/money";
import { dashboard } from "@/services/subscriptions";

export default function DashboardPage() {
  const { current } = useOrganization();
  const { data, isLoading } = useQuery({ queryKey: ["dashboard"], queryFn: dashboard.get, enabled: !!current });
  const d = data?.data;

  return (
    <>
      <PageTitle title={current ? current.name : "Обзор"} subtitle="Клиенты, подписки и регулярная выручка организации." />

      <div className="grid gap-4 md:grid-cols-4">
        <Stat label="MRR" value={d ? (d.mrr.length ? d.mrr.map((m) => <div key={m.currency}>{formatMoney(m.amount, m.currency)}</div>) : "—") : "…"} hint="активные и триальные подписки, приведённые к месяцу" />
        <Stat label="Клиенты" value={d?.customers ?? "…"} />
        <Stat label="Активные подписки" value={d ? d.subscriptions.by_status.active + d.subscriptions.by_status.trialing : "…"} hint={d ? `${d.subscriptions.by_status.trialing} на триале · ${d.subscriptions.by_status.past_due} просрочено` : undefined} />
        <Stat label="Продукты" value={d?.products ?? "…"} hint="активных" />
      </div>

      <Card className="mt-6" title="Последние подписки" actions={<Link href="/subscriptions" className="text-xs text-accent hover:underline">все подписки →</Link>}>
        <Table>
          <thead>
            <tr>
              <Th>Клиент</Th>
              <Th>Статус</Th>
              <Th>Позиции</Th>
              <Th className="text-right">За период</Th>
              <Th>Период до</Th>
            </tr>
          </thead>
          <tbody>
            {d?.recent_subscriptions.map((s) => (
              <tr key={s.id} className="hover:bg-panel-2/60">
                <Td>
                  <Link href={`/subscriptions/${s.id}`} className="font-medium hover:text-accent">
                    {s.customer?.name ?? s.customer_id}
                  </Link>
                </Td>
                <Td>
                  <StatusBadge status={s.status} cancelAtPeriodEnd={s.cancel_at_period_end} />
                </Td>
                <Td className="text-muted">{s.items?.map(itemLabel).join(", ")}</Td>
                <Td className="text-right">{s.period_amount && <Money formatted={formatMoney(s.period_amount.amount, s.period_amount.currency)} />}</Td>
                <Td className="text-muted">{formatDate(s.current_period_end)}</Td>
              </tr>
            ))}
            {d && d.recent_subscriptions.length === 0 && (
              <tr>
                <td colSpan={5}>
                  <Empty>Подписок пока нет. Создайте продукт с ценой и оформите первую.</Empty>
                </td>
              </tr>
            )}
            {isLoading && (
              <tr>
                <td colSpan={5}>
                  <Empty>Загрузка…</Empty>
                </td>
              </tr>
            )}
          </tbody>
        </Table>
      </Card>
    </>
  );
}
