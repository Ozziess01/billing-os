"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";
import { InvoiceStatusBadge, PaymentStatusBadge } from "@/components/invoices/InvoiceStatusBadge";
import { usePortal } from "@/components/portal/PortalShell";
import { Button, Card, ErrorNote, Money, Select, Table, Td, Th } from "@/components/ui";
import { formatDate } from "@/lib/format";
import { formatMoney } from "@/lib/money";
import { paymentMethods } from "@/services/payments";
import { portal } from "@/services/portal";

export default function PortalInvoicePage() {
  const { token } = usePortal();
  const { id } = useParams<{ id: string }>();
  const client = useQueryClient();
  const [method, setMethod] = useState("tok_ok");

  const { data, isLoading, error } = useQuery({
    queryKey: ["portal", token, "invoices", id],
    queryFn: () => portal.invoice(token, id),
    refetchInterval: (q) => (q.state.data?.data.payments?.some((p) => p.status === "processing" || p.status === "pending") ? 2000 : false),
  });
  const pay = useMutation({ mutationFn: () => portal.pay(token, id, method), onSuccess: () => client.invalidateQueries({ queryKey: ["portal", token] }) });

  if (isLoading) return <div className="text-sm text-muted">Загрузка…</div>;
  if (error || !data) return <ErrorNote error={error ?? new Error("Инвойс не найден.")} />;

  const inv = data.data;
  const inFlight = inv.payments?.some((p) => p.status === "processing" || p.status === "pending");

  return (
    <div className="space-y-4">
      <Link href={`/portal/${token}/invoices`} className="text-xs text-muted hover:text-fg">← Инвойсы</Link>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">{inv.number}</h1>
          <div className="mt-1 flex items-center gap-2 text-sm text-muted">
            <InvoiceStatusBadge status={inv.status} />
            {inv.period_start && <span>{formatDate(inv.period_start)} — {formatDate(inv.period_end)}</span>}
          </div>
        </div>
        <div className="flex gap-2">
          <a href={portal.exportUrl(inv.id, "csv")} onClick={(e) => { e.preventDefault(); download(token, inv.id, "csv"); }} className="text-sm text-accent hover:underline">CSV</a>
          <a href={portal.exportUrl(inv.id, "json")} onClick={(e) => { e.preventDefault(); download(token, inv.id, "json"); }} className="text-sm text-accent hover:underline">JSON</a>
        </div>
      </div>

      <Card>
        <Table>
          <thead>
            <tr>
              <Th>Описание</Th>
              <Th className="text-right">Кол-во</Th>
              <Th className="text-right">Сумма</Th>
            </tr>
          </thead>
          <tbody>
            {inv.items?.map((item) => (
              <tr key={item.id}>
                <Td>{item.description}</Td>
                <Td className="text-right font-mono">{item.quantity}</Td>
                <Td className="text-right"><Money formatted={formatMoney(item.amount.amount, inv.currency)} /></Td>
              </tr>
            ))}
            {inv.discount.amount > 0 && (
              <tr>
                <Td className="text-muted" colSpan={2}>Скидка</Td>
                <Td className="text-right"><Money formatted={"−" + formatMoney(inv.discount.amount, inv.currency)} /></Td>
              </tr>
            )}
            <tr>
              <Td className="font-medium" colSpan={2}>Итого</Td>
              <Td className="text-right font-medium"><Money formatted={formatMoney(inv.total.amount, inv.currency)} /></Td>
            </tr>
            {inv.amount_paid.amount > 0 && (
              <tr>
                <Td className="text-muted" colSpan={2}>Оплачено</Td>
                <Td className="text-right"><Money formatted={formatMoney(inv.amount_paid.amount, inv.currency)} /></Td>
              </tr>
            )}
          </tbody>
        </Table>
      </Card>

      {inv.status === "open" && (
        <Card className="px-5 py-4">
          <div className="mb-3 text-sm">К оплате <span className="font-mono font-medium">{formatMoney(inv.amount_due.amount, inv.currency)}</span>{inv.due_at && <span className="text-muted"> · до {formatDate(inv.due_at)}</span>}</div>
          {inFlight ? (
            <p className="text-sm text-muted">Платёж в обработке…</p>
          ) : (
            <form
              className="flex flex-wrap items-end gap-2"
              onSubmit={(e) => {
                e.preventDefault();
                pay.mutate();
              }}
            >
              <Select label="Платёжный метод" value={method} onChange={(e) => setMethod(e.target.value)} className="w-80">
                {paymentMethods.map((m) => (
                  <option key={m.value} value={m.value}>{m.label}</option>
                ))}
              </Select>
              <Button type="submit" disabled={pay.isPending}>Оплатить</Button>
            </form>
          )}
          {pay.error && <div className="mt-2"><ErrorNote error={pay.error} /></div>}
          {pay.data && <div className="mt-2 text-sm">Результат: <PaymentStatusBadge status={pay.data.data.status} /> {pay.data.data.failure_message && <span className="text-err">{pay.data.data.failure_message}</span>}</div>}
        </Card>
      )}

      {(inv.payments?.length ?? 0) > 0 && (
        <Card title="Платежи">
          <Table>
            <thead>
              <tr>
                <Th>#</Th>
                <Th>Статус</Th>
                <Th className="text-right">Сумма</Th>
                <Th>Когда</Th>
              </tr>
            </thead>
            <tbody>
              {inv.payments?.map((p) => (
                <tr key={p.id}>
                  <Td className="font-mono text-xs">#{p.attempt_number}</Td>
                  <Td><PaymentStatusBadge status={p.status} />{p.failure_message && <div className="text-xs text-err">{p.failure_message}</div>}</Td>
                  <Td className="text-right"><Money formatted={formatMoney(p.amount.amount, p.amount.currency)} /></Td>
                  <Td className="text-muted">{formatDate(p.created_at, true)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
      )}
    </div>
  );
}

// выгрузка идёт с токеном в заголовке, поэтому не обычной ссылкой, а через fetch + blob
async function download(token: string, id: string, format: "csv" | "json") {
  const res = await fetch(portal.exportUrl(id, format), { headers: { Authorization: `Bearer ${token}`, Accept: "application/json" } });
  if (!res.ok) return;
  const blob = await res.blob();
  const name = res.headers.get("Content-Disposition")?.match(/filename="([^"]+)"/)?.[1] ?? `invoice.${format}`;
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = name;
  a.click();
  URL.revokeObjectURL(url);
}
