"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useParams } from "next/navigation";
import { StatusBadge } from "@/components/subscriptions/StatusBadge";
import { BackLink, Button, Card, ErrorNote, Money, PageTitle, Table, Td, Th } from "@/components/ui";
import { useOrganization } from "@/hooks/useAuth";
import { formatDate } from "@/lib/format";
import { formatMoney, intervalText } from "@/lib/money";
import { subscriptions } from "@/services/subscriptions";

export default function SubscriptionPage() {
  const { id } = useParams<{ id: string }>();
  const client = useQueryClient();
  const { current } = useOrganization();
  const canManage = current?.role !== "viewer";

  const { data, isLoading, error } = useQuery({ queryKey: ["subscriptions", id], queryFn: () => subscriptions.get(id) });
  const refresh = () => {
    client.invalidateQueries({ queryKey: ["subscriptions"] });
    client.invalidateQueries({ queryKey: ["dashboard"] });
    client.invalidateQueries({ queryKey: ["customers"] });
  };
  const cancel = useMutation({ mutationFn: (atPeriodEnd: boolean) => subscriptions.cancel(id, atPeriodEnd), onSuccess: refresh });
  const resume = useMutation({ mutationFn: () => subscriptions.resume(id), onSuccess: refresh });

  if (isLoading) return <div className="text-sm text-muted">Загрузка…</div>;
  if (error || !data) return <ErrorNote error={error ?? new Error("Подписка не найдена.")} />;

  const s = data.data;
  const live = s.status !== "canceled";

  return (
    <>
      <BackLink href="/subscriptions">Подписки</BackLink>
      <PageTitle
        title={s.customer?.name ?? "Подписка"}
        subtitle={s.items?.[0]?.price ? `${s.items[0].price.product?.name ?? ""} · ${intervalText(s.items[0].price)}` : undefined}
        actions={
          canManage && live ? (
            <>
              {s.cancel_at_period_end ? (
                <Button variant="secondary" disabled={resume.isPending} onClick={() => resume.mutate()}>
                  Возобновить
                </Button>
              ) : (
                <Button variant="secondary" disabled={cancel.isPending} onClick={() => cancel.mutate(true)}>
                  Отменить в конце периода
                </Button>
              )}
              <Button variant="danger" disabled={cancel.isPending} onClick={() => confirm("Отменить подписку прямо сейчас? Это необратимо.") && cancel.mutate(false)}>
                Отменить сейчас
              </Button>
            </>
          ) : undefined
        }
      />
      {(cancel.error || resume.error) && <div className="mb-4"><ErrorNote error={cancel.error ?? resume.error} /></div>}

      <div className="grid gap-6 lg:grid-cols-3">
        <Card title="Состояние" className="lg:col-span-1">
          <dl className="space-y-3 px-5 py-4 text-sm">
            <Row label="Статус">
              <StatusBadge status={s.status} cancelAtPeriodEnd={s.cancel_at_period_end} />
            </Row>
            <Row label="Клиент">
              <Link href={`/customers/${s.customer_id}`} className="text-accent hover:underline">
                {s.customer?.name ?? s.customer_id}
              </Link>
            </Row>
            <Row label="Текущий период">
              {formatDate(s.current_period_start, true)} — {formatDate(s.current_period_end, true)}
            </Row>
            {s.trial_ends_at && <Row label="Триал до">{formatDate(s.trial_ends_at, true)}</Row>}
            {s.canceled_at && <Row label="Отменена">{formatDate(s.canceled_at, true)}</Row>}
            <Row label="ID">
              <span className="font-mono text-xs">{s.id}</span>
            </Row>
            <Row label="Создана">{formatDate(s.created_at, true)}</Row>
          </dl>
        </Card>

        <Card title="Позиции" className="lg:col-span-2">
          <Table>
            <thead>
              <tr>
                <Th>Продукт</Th>
                <Th>Цена</Th>
                <Th className="text-right">Кол-во</Th>
                <Th className="text-right">Сумма</Th>
              </tr>
            </thead>
            <tbody>
              {s.items?.map((item) => (
                <tr key={item.id}>
                  <Td className="font-medium">{item.price?.product?.name ?? "—"}</Td>
                  <Td className="text-muted">
                    {item.price && (
                      <>
                        <Money formatted={formatMoney(item.price.unit_amount, item.price.currency)} /> {intervalText(item.price)}
                        {item.price.nickname ? ` · ${item.price.nickname}` : ""}
                      </>
                    )}
                  </Td>
                  <Td className="text-right font-mono">{item.quantity}</Td>
                  <Td className="text-right">{item.amount && <Money formatted={formatMoney(item.amount.amount, item.amount.currency)} />}</Td>
                </tr>
              ))}
              {s.period_amount && (
                <tr>
                  <Td className="font-medium" colSpan={3}>
                    Итого за период
                  </Td>
                  <Td className="text-right font-medium">
                    <Money formatted={formatMoney(s.period_amount.amount, s.period_amount.currency)} />
                  </Td>
                </tr>
              )}
            </tbody>
          </Table>
        </Card>
      </div>
    </>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs text-muted">{label}</dt>
      <dd className="mt-0.5">{children}</dd>
    </div>
  );
}
