"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useState } from "react";
import { StatusBadge } from "@/components/subscriptions/StatusBadge";
import { UsagePanel } from "@/components/subscriptions/UsagePanel";
import { BackLink, Button, Card, ErrorNote, Input, Money, PageTitle, Table, Td, Th } from "@/components/ui";
import { useOrganization } from "@/hooks/useAuth";
import { formatDate } from "@/lib/format";
import { formatMoney, intervalText } from "@/lib/money";
import { coupons } from "@/services/coupons";
import { invoices } from "@/services/invoices";
import { subscriptions } from "@/services/subscriptions";

export default function SubscriptionPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
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
  const issue = useMutation({
    mutationFn: () => invoices.forSubscription(id),
    onSuccess: (res) => {
      client.invalidateQueries({ queryKey: ["invoices"] });
      router.push(`/invoices/${res.data.id}`);
    },
  });
  const [couponCode, setCouponCode] = useState("");
  const applyCoupon = useMutation({
    mutationFn: () => coupons.apply(id, couponCode),
    onSuccess: () => {
      setCouponCode("");
      refresh();
    },
  });

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
              <Button variant="secondary" disabled={issue.isPending} onClick={() => issue.mutate()}>
                Выставить инвойс за период
              </Button>
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
      {(cancel.error || resume.error || issue.error || applyCoupon.error) && <div className="mb-4"><ErrorNote error={cancel.error ?? resume.error ?? issue.error ?? applyCoupon.error} /></div>}

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
            {s.canceled_at && (
              <Row label="Отменена">
                {formatDate(s.canceled_at, true)}
                {s.cancel_reason && <span className="text-muted"> · {cancelReasons[s.cancel_reason] ?? s.cancel_reason}</span>}
              </Row>
            )}
            <Row label="Купон">
              {s.coupon ? (
                <span>
                  <span className="font-mono">{s.coupon.code}</span> · {s.coupon.type === "percent" ? `${s.coupon.percent_off}%` : s.coupon.amount_off ? formatMoney(s.coupon.amount_off.amount, s.coupon.amount_off.currency) : ""}{" "}
                  <span className="text-muted">{s.coupon.duration === "once" ? "на первый инвойс" : "на каждый инвойс"}</span>
                </span>
              ) : canManage && live ? (
                <form
                  className="mt-1 flex gap-2"
                  onSubmit={(e) => {
                    e.preventDefault();
                    applyCoupon.mutate();
                  }}
                >
                  <Input placeholder="КОД" value={couponCode} onChange={(e) => setCouponCode(e.target.value.toUpperCase())} className="h-8 w-32" />
                  <Button type="submit" size="sm" variant="secondary" disabled={!couponCode || applyCoupon.isPending}>
                    Применить
                  </Button>
                </form>
              ) : (
                "—"
              )}
            </Row>
            <Row label="ID">
              <span className="font-mono text-xs">{s.id}</span>
            </Row>
            <Row label="Создана">{formatDate(s.created_at, true)}</Row>
          </dl>
        </Card>

        <div className="space-y-6 lg:col-span-2">
        <Card title="Позиции">
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
                    {item.price && item.price.usage_type === "metered" && (
                      <>
                        <span className="font-mono">{item.price.unit_amount_decimal} {item.price.currency}/100</span> за единицу, по факту
                        {item.price.nickname ? ` · ${item.price.nickname}` : ""}
                      </>
                    )}
                    {item.price && item.price.usage_type !== "metered" && (
                      <>
                        <Money formatted={formatMoney(item.price.unit_amount, item.price.currency)} /> {intervalText(item.price)}
                        {item.price.nickname ? ` · ${item.price.nickname}` : ""}
                      </>
                    )}
                  </Td>
                  <Td className="text-right font-mono">{item.price?.usage_type === "metered" ? "по факту" : item.quantity}</Td>
                  <Td className="text-right">{item.price?.usage_type === "metered" ? <span className="text-muted">по использованию</span> : item.amount && <Money formatted={formatMoney(item.amount.amount, item.amount.currency)} />}</Td>
                </tr>
              ))}
              {s.period_amount && (
                <tr>
                  <Td className="font-medium" colSpan={3}>
                    Итого за период{s.items?.some((i) => i.price?.usage_type === "metered") ? " (без использования)" : ""}
                  </Td>
                  <Td className="text-right font-medium">
                    <Money formatted={formatMoney(s.period_amount.amount, s.period_amount.currency)} />
                  </Td>
                </tr>
              )}
            </tbody>
          </Table>
        </Card>

        <UsagePanel subscription={s} canReport={canManage} />
        </div>
      </div>
    </>
  );
}

const cancelReasons: Record<string, string> = {
  requested: "по запросу",
  period_end: "по окончании периода",
  payment_failed: "не удалось списать оплату",
  portal: "клиент отменил в портале",
};

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs text-muted">{label}</dt>
      <dd className="mt-0.5">{children}</dd>
    </div>
  );
}
