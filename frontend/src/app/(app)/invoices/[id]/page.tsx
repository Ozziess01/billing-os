"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useState } from "react";
import { InvoiceItemForm } from "@/components/invoices/InvoiceItemForm";
import { InvoiceStatusBadge, PaymentStatusBadge } from "@/components/invoices/InvoiceStatusBadge";
import { PayDialog } from "@/components/payments/PayDialog";
import { BackLink, Button, Card, Dialog, Empty, ErrorNote, Money, PageTitle, Table, Td, Th } from "@/components/ui";
import { useOrganization } from "@/hooks/useAuth";
import { formatDate } from "@/lib/format";
import { formatMoney } from "@/lib/money";
import { invoices } from "@/services/invoices";

export default function InvoicePage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const client = useQueryClient();
  const { current } = useOrganization();
  const role = current?.role ?? "viewer";
  const canEdit = role !== "viewer";
  const canWriteOff = role === "owner" || role === "admin";
  const [addingItem, setAddingItem] = useState(false);
  const [paying, setPaying] = useState(false);

  const { data, isLoading, error } = useQuery({
    queryKey: ["invoices", id],
    queryFn: () => invoices.get(id),
    // пока платёж в обработке, ждём вебхук провайдера
    refetchInterval: (q) => (q.state.data?.data.payments?.some((p) => p.status === "processing" || p.status === "pending") ? 2000 : false),
  });
  const refresh = () => {
    client.invalidateQueries({ queryKey: ["invoices"] });
    client.invalidateQueries({ queryKey: ["ledger"] });
    client.invalidateQueries({ queryKey: ["payments"] });
  };
  const addItem = useMutation({ mutationFn: (input: Parameters<typeof invoices.addItem>[1]) => invoices.addItem(id, input), onSuccess: () => { refresh(); setAddingItem(false); } });
  const removeItem = useMutation({ mutationFn: (itemId: string) => invoices.removeItem(id, itemId), onSuccess: refresh });
  const finalize = useMutation({ mutationFn: () => invoices.finalize(id), onSuccess: refresh });
  const voidIt = useMutation({ mutationFn: () => invoices.void(id), onSuccess: refresh });
  const uncollectible = useMutation({ mutationFn: () => invoices.uncollectible(id), onSuccess: refresh });
  const remove = useMutation({ mutationFn: () => invoices.remove(id), onSuccess: () => { refresh(); router.replace("/invoices"); } });

  if (isLoading) return <div className="text-sm text-muted">Загрузка…</div>;
  if (error || !data) return <ErrorNote error={error ?? new Error("Инвойс не найден.")} />;

  const inv = data.data;
  const actionError = addItem.error ?? removeItem.error ?? finalize.error ?? voidIt.error ?? uncollectible.error ?? remove.error;
  const inFlight = inv.payments?.some((p) => p.status === "processing" || p.status === "pending");

  return (
    <>
      <BackLink href="/invoices">Инвойсы</BackLink>
      <PageTitle
        title={inv.number ?? "Черновик инвойса"}
        subtitle={`${inv.customer?.name ?? ""} · ${formatMoney(inv.total.amount, inv.currency)}`}
        actions={
          <>
            {canEdit && inv.status === "draft" && (
              <>
                <Button variant="secondary" onClick={() => setAddingItem(true)}>Добавить позицию</Button>
                <Button disabled={finalize.isPending || !(inv.items?.length)} onClick={() => finalize.mutate()}>Финализировать</Button>
                <Button variant="danger" disabled={remove.isPending} onClick={() => confirm("Удалить черновик?") && remove.mutate()}>Удалить</Button>
              </>
            )}
            {canEdit && inv.status === "open" && (
              <Button disabled={!!inFlight} onClick={() => setPaying(true)}>
                {inFlight ? "Платёж в обработке…" : "Оплатить"}
              </Button>
            )}
            {canWriteOff && inv.status === "open" && (
              <>
                <Button variant="secondary" disabled={voidIt.isPending || !!inFlight} onClick={() => confirm("Аннулировать инвойс?") && voidIt.mutate()}>Аннулировать</Button>
                <Button variant="danger" disabled={uncollectible.isPending || !!inFlight} onClick={() => confirm("Признать безнадёжным?") && uncollectible.mutate()}>Безнадёжный</Button>
              </>
            )}
          </>
        }
      />
      {actionError && <div className="mb-4"><ErrorNote error={actionError} /></div>}

      <div className="grid gap-6 lg:grid-cols-3">
        <Card title="Состояние" className="lg:col-span-1">
          <dl className="space-y-3 px-5 py-4 text-sm">
            <Row label="Статус"><InvoiceStatusBadge status={inv.status} /></Row>
            <Row label="Клиент">
              <Link href={`/customers/${inv.customer_id}`} className="text-accent hover:underline">{inv.customer?.name ?? inv.customer_id}</Link>
            </Row>
            {inv.subscription_id && (
              <Row label="Подписка">
                <Link href={`/subscriptions/${inv.subscription_id}`} className="font-mono text-xs text-accent hover:underline">{inv.subscription_id}</Link>
              </Row>
            )}
            {inv.period_start && <Row label="Период">{formatDate(inv.period_start)} — {formatDate(inv.period_end)}</Row>}
            <Row label="Итого"><Money formatted={formatMoney(inv.total.amount, inv.currency)} /></Row>
            <Row label="Оплачено"><Money formatted={formatMoney(inv.amount_paid.amount, inv.currency)} /></Row>
            <Row label="К оплате"><Money formatted={formatMoney(inv.amount_due.amount, inv.currency)} className={inv.amount_due.amount > 0 ? "font-medium" : ""} /></Row>
            {inv.due_at && <Row label="Срок оплаты">{formatDate(inv.due_at, true)}</Row>}
            {inv.auto_collect && (
              <Row label="Автосписание">
                попыток: {inv.collection_attempts}
                {inv.next_payment_attempt_at && <span className="text-muted"> · следующая {formatDate(inv.next_payment_attempt_at, true)}</span>}
              </Row>
            )}
            {inv.discount.amount > 0 && <Row label="Скидка"><Money formatted={"−" + formatMoney(inv.discount.amount, inv.currency)} /></Row>}
            {inv.finalized_at && <Row label="Финализирован">{formatDate(inv.finalized_at, true)}</Row>}
            {inv.paid_at && <Row label="Оплачен">{formatDate(inv.paid_at, true)}</Row>}
            {inv.voided_at && <Row label="Аннулирован">{formatDate(inv.voided_at, true)}</Row>}
            {inv.uncollectible_at && <Row label="Списан">{formatDate(inv.uncollectible_at, true)}</Row>}
            <Row label="ID"><span className="font-mono text-xs">{inv.id}</span></Row>
          </dl>
        </Card>

        <div className="space-y-6 lg:col-span-2">
          <Card title="Позиции">
            <Table>
              <thead>
                <tr>
                  <Th>Описание</Th>
                  <Th className="text-right">Кол-во</Th>
                  <Th className="text-right">Цена</Th>
                  <Th className="text-right">Сумма</Th>
                  {inv.status === "draft" && canEdit && <Th />}
                </tr>
              </thead>
              <tbody>
                {inv.items?.map((item) => (
                  <tr key={item.id}>
                    <Td>
                      {item.description}
                      {item.period_start && <div className="text-xs text-muted">{formatDate(item.period_start)} — {formatDate(item.period_end)}</div>}
                    </Td>
                    <Td className="text-right font-mono">{item.quantity}</Td>
                    <Td className="text-right"><Money formatted={formatMoney(item.unit_amount.amount, inv.currency)} /></Td>
                    <Td className="text-right"><Money formatted={formatMoney(item.amount.amount, inv.currency)} /></Td>
                    {inv.status === "draft" && canEdit && (
                      <Td className="text-right">
                        <Button variant="ghost" size="sm" onClick={() => removeItem.mutate(item.id)}>убрать</Button>
                      </Td>
                    )}
                  </tr>
                ))}
                {(inv.items?.length ?? 0) === 0 && (
                  <tr>
                    <td colSpan={5}><Empty>Позиций нет — без них финализировать нельзя.</Empty></td>
                  </tr>
                )}
                {(inv.items?.length ?? 0) > 0 && (
                  <tr>
                    <Td className="font-medium" colSpan={3}>Итого</Td>
                    <Td className="text-right font-medium"><Money formatted={formatMoney(inv.total.amount, inv.currency)} /></Td>
                    {inv.status === "draft" && canEdit && <Td />}
                  </tr>
                )}
              </tbody>
            </Table>
          </Card>

          <Card title="Платежи">
            <Table>
              <thead>
                <tr>
                  <Th>#</Th>
                  <Th>Статус</Th>
                  <Th>Метод</Th>
                  <Th className="text-right">Сумма</Th>
                  <Th>Когда</Th>
                </tr>
              </thead>
              <tbody>
                {inv.payments?.map((p) => (
                  <tr key={p.id} className="hover:bg-panel-2/60">
                    <Td>
                      <Link href={`/payments/${p.id}`} className="font-mono text-xs hover:text-accent">#{p.attempt_number}</Link>
                    </Td>
                    <Td>
                      <PaymentStatusBadge status={p.status} />
                      {p.failure_message && <div className="mt-1 text-xs text-err">{p.failure_message}</div>}
                    </Td>
                    <Td className="font-mono text-xs text-muted">{p.payment_method}</Td>
                    <Td className="text-right"><Money formatted={formatMoney(p.amount.amount, p.amount.currency)} /></Td>
                    <Td className="text-muted">{formatDate(p.created_at, true)}</Td>
                  </tr>
                ))}
                {(inv.payments?.length ?? 0) === 0 && (
                  <tr>
                    <td colSpan={5}><Empty>Платежей ещё не было.</Empty></td>
                  </tr>
                )}
              </tbody>
            </Table>
          </Card>
        </div>
      </div>

      <Dialog open={addingItem} onClose={() => setAddingItem(false)} title="Новая позиция">
        <InvoiceItemForm currency={inv.currency} onSubmit={(input) => addItem.mutate(input)} pending={addItem.isPending} error={addItem.error} />
      </Dialog>
      <PayDialog invoice={inv} open={paying} onClose={() => setPaying(false)} />
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
