"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";
import { PaymentStatusBadge, RefundStatusBadge } from "@/components/invoices/InvoiceStatusBadge";
import { RefundDialog } from "@/components/payments/RefundDialog";
import { BackLink, Button, Card, Empty, ErrorNote, Money, PageTitle, Table, Td, Th } from "@/components/ui";
import { useOrganization } from "@/hooks/useAuth";
import { formatDate } from "@/lib/format";
import { formatMoney } from "@/lib/money";
import { ledger } from "@/services/ledger";
import { payments } from "@/services/payments";

export default function PaymentPage() {
  const { id } = useParams<{ id: string }>();
  const client = useQueryClient();
  const { current } = useOrganization();
  const role = current?.role ?? "viewer";
  const [refunding, setRefunding] = useState(false);

  const { data, isLoading, error } = useQuery({
    queryKey: ["payments", id],
    queryFn: () => payments.get(id),
    refetchInterval: (q) => (q.state.data?.data.status === "processing" || q.state.data?.data.status === "pending" ? 2000 : false),
  });
  const postings = useQuery({ queryKey: ["ledger", "transactions", { reference_id: id }], queryFn: () => ledger.transactions({ reference_id: id }) });
  const refresh = () => {
    client.invalidateQueries({ queryKey: ["payments"] });
    client.invalidateQueries({ queryKey: ["invoices"] });
    client.invalidateQueries({ queryKey: ["ledger"] });
  };
  const cancel = useMutation({ mutationFn: () => payments.cancel(id), onSuccess: refresh });
  const confirmAction = useMutation({ mutationFn: (success: boolean) => payments.confirmFake(data!.data.provider_payment_id!, success), onSuccess: refresh });

  if (isLoading) return <div className="text-sm text-muted">Загрузка…</div>;
  if (error || !data) return <ErrorNote error={error ?? new Error("Платёж не найден.")} />;

  const p = data.data;
  const currency = p.amount.currency;

  return (
    <>
      <BackLink href="/payments">Платежи</BackLink>
      <PageTitle
        title={`Платёж #${p.attempt_number} · ${formatMoney(p.amount.amount, currency)}`}
        subtitle={`${p.customer?.name ?? ""} · инвойс ${p.invoice?.number ?? p.invoice_id}`}
        actions={
          <>
            {(role === "owner" || role === "admin") && p.status === "succeeded" && p.refundable_amount.amount > 0 && (
              <Button variant="danger" onClick={() => setRefunding(true)}>Вернуть</Button>
            )}
            {role !== "viewer" && (p.status === "processing" || p.status === "pending") && (
              <Button variant="secondary" disabled={cancel.isPending} onClick={() => confirm("Отменить платёж в обработке?") && cancel.mutate()}>Отменить платёж</Button>
            )}
          </>
        }
      />
      {(cancel.error || confirmAction.error) && <div className="mb-4"><ErrorNote error={cancel.error ?? confirmAction.error} /></div>}

      <div className="grid gap-6 lg:grid-cols-3">
        <Card title="Состояние" className="lg:col-span-1">
          <dl className="space-y-3 px-5 py-4 text-sm">
            <Row label="Статус"><PaymentStatusBadge status={p.status} /></Row>
            {p.failure_message && <Row label="Причина отказа"><span className="text-err">{p.failure_message}</span> <span className="font-mono text-xs text-muted">({p.failure_code})</span></Row>}
            <Row label="Инвойс">
              <Link href={`/invoices/${p.invoice_id}`} className="text-accent hover:underline">{p.invoice?.number ?? p.invoice_id}</Link>
            </Row>
            <Row label="Клиент">
              <Link href={`/customers/${p.customer_id}`} className="text-accent hover:underline">{p.customer?.name ?? p.customer_id}</Link>
            </Row>
            <Row label="Сумма"><Money formatted={formatMoney(p.amount.amount, currency)} /></Row>
            <Row label="Возвращено"><Money formatted={formatMoney(p.amount_refunded.amount, currency)} /></Row>
            <Row label="Доступно к возврату"><Money formatted={formatMoney(p.refundable_amount.amount, currency)} /></Row>
            <Row label="Провайдер">{p.provider} · <span className="font-mono text-xs">{p.provider_payment_id ?? "—"}</span></Row>
            <Row label="Метод"><span className="font-mono text-xs">{p.payment_method}</span></Row>
            {p.succeeded_at && <Row label="Успешен">{formatDate(p.succeeded_at, true)}</Row>}
            {p.failed_at && <Row label="Отказ">{formatDate(p.failed_at, true)}</Row>}
            {p.canceled_at && <Row label="Отменён">{formatDate(p.canceled_at, true)}</Row>}
            <Row label="ID"><span className="font-mono text-xs">{p.id}</span></Row>
          </dl>
          {p.status === "processing" && p.next_action?.type === "confirm" && role !== "viewer" && (
            <div className="border-t border-line px-5 py-4 text-sm">
              <p className="mb-2">Провайдер ждёт подтверждения клиента (3-D Secure).</p>
              <div className="flex gap-2">
                <Button size="sm" disabled={confirmAction.isPending || confirmAction.isSuccess} onClick={() => confirmAction.mutate(true)}>Подтвердить</Button>
                <Button size="sm" variant="danger" disabled={confirmAction.isPending || confirmAction.isSuccess} onClick={() => confirmAction.mutate(false)}>Отклонить</Button>
              </div>
              {confirmAction.isSuccess && <p className="mt-2 text-xs text-muted">Ждём вебхук провайдера…</p>}
            </div>
          )}
        </Card>

        <div className="space-y-6 lg:col-span-2">
          <Card title="Возвраты">
            <Table>
              <thead>
                <tr>
                  <Th>Статус</Th>
                  <Th className="text-right">Сумма</Th>
                  <Th>Причина</Th>
                  <Th>Когда</Th>
                </tr>
              </thead>
              <tbody>
                {p.refunds?.map((r) => (
                  <tr key={r.id}>
                    <Td>
                      <RefundStatusBadge status={r.status} />
                      {r.failure_message && <div className="mt-1 text-xs text-err">{r.failure_message}</div>}
                    </Td>
                    <Td className="text-right"><Money formatted={formatMoney(r.amount.amount, currency)} /></Td>
                    <Td className="text-muted">{r.reason ?? "—"}</Td>
                    <Td className="text-muted">{formatDate(r.created_at, true)}</Td>
                  </tr>
                ))}
                {(p.refunds?.length ?? 0) === 0 && (
                  <tr>
                    <td colSpan={4}><Empty>Возвратов не было.</Empty></td>
                  </tr>
                )}
              </tbody>
            </Table>
          </Card>

          <Card title="Проводки в леджере">
            <Table>
              <thead>
                <tr>
                  <Th>Тип</Th>
                  <Th>Счёт</Th>
                  <Th className="text-right">Дебет</Th>
                  <Th className="text-right">Кредит</Th>
                </tr>
              </thead>
              <tbody>
                {postings.data?.data.flatMap((t) =>
                  (t.entries ?? []).map((e, i) => (
                    <tr key={`${t.id}-${i}`}>
                      <Td className="text-muted">{i === 0 ? `${t.type} · ${formatDate(t.posted_at, true)}` : ""}</Td>
                      <Td>{e.account_name ?? e.account_type}</Td>
                      <Td className="text-right">{e.debit.amount ? <Money formatted={formatMoney(e.debit.amount, currency)} /> : ""}</Td>
                      <Td className="text-right">{e.credit.amount ? <Money formatted={formatMoney(e.credit.amount, currency)} /> : ""}</Td>
                    </tr>
                  )),
                )}
                {postings.data && postings.data.data.length === 0 && (
                  <tr>
                    <td colSpan={4}><Empty>Проводок нет — платёж не был успешным.</Empty></td>
                  </tr>
                )}
              </tbody>
            </Table>
          </Card>
        </div>
      </div>

      <RefundDialog payment={p} open={refunding} onClose={() => setRefunding(false)} />
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
