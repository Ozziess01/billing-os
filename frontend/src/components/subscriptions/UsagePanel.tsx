"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { Button, Card, Empty, ErrorNote, Input, Money, Table, Td, Th } from "@/components/ui";
import { idempotencyKey } from "@/lib/api";
import { formatDate } from "@/lib/format";
import { formatMoney } from "@/lib/money";
import { usage } from "@/services/usage";
import type { Subscription } from "@/types";

/** Использование по metered-позициям за текущий период и форма отчёта (с ключом идемпотентности). */
export function UsagePanel({ subscription, canReport }: { subscription: Subscription; canReport: boolean }) {
  const client = useQueryClient();
  const metered = (subscription.items ?? []).filter((i) => i.price?.usage_type === "metered");
  const [itemId, setItemId] = useState(metered[0]?.id ?? "");
  const [quantity, setQuantity] = useState("100");

  const summary = useQuery({ queryKey: ["usage", "summary", subscription.id], queryFn: () => usage.summary(subscription.id), enabled: metered.length > 0 });
  const events = useQuery({ queryKey: ["usage", "events", itemId], queryFn: () => usage.list({ subscription_item_id: itemId }), enabled: !!itemId });
  const report = useMutation({
    mutationFn: () => usage.record({ subscription_item_id: itemId, quantity: Number(quantity), idempotency_key: idempotencyKey() }),
    onSuccess: () => client.invalidateQueries({ queryKey: ["usage"] }),
  });

  if (metered.length === 0) return null;

  return (
    <Card title="Использование" actions={<span className="text-xs text-muted">период {formatDate(subscription.current_period_start)} — {formatDate(subscription.current_period_end)}</span>}>
      <Table>
        <thead>
          <tr>
            <Th>Позиция</Th>
            <Th className="text-right">Единиц</Th>
            <Th className="text-right">Не выставлено</Th>
            <Th className="text-right">Оценка</Th>
          </tr>
        </thead>
        <tbody>
          {summary.data?.data.items.map((row) => {
            const item = metered.find((i) => i.id === row.subscription_item_id);
            return (
              <tr key={row.subscription_item_id}>
                <Td>
                  {item?.price?.product?.name ?? "—"}
                  {item?.price?.nickname ? ` (${item.price.nickname})` : ""}
                  <div className="font-mono text-[11px] text-muted">{item?.price?.unit_amount_decimal} {subscription.currency}/100 за единицу</div>
                </Td>
                <Td className="text-right font-mono">{row.units.toLocaleString("ru-RU")}</Td>
                <Td className="text-right font-mono">{row.unbilled_units.toLocaleString("ru-RU")}</Td>
                <Td className="text-right">
                  <Money formatted={formatMoney(row.estimated_amount, subscription.currency)} />
                </Td>
              </tr>
            );
          })}
        </tbody>
      </Table>

      {canReport && subscription.status !== "canceled" && (
        <form
          className="flex flex-wrap items-end gap-2 border-t border-line px-5 py-4"
          onSubmit={(e) => {
            e.preventDefault();
            report.mutate();
          }}
        >
          {metered.length > 1 && (
            <select value={itemId} onChange={(e) => setItemId(e.target.value)} className="h-9 rounded-md border border-line bg-panel px-3 text-sm">
              {metered.map((i) => (
                <option key={i.id} value={i.id}>
                  {i.price?.product?.name} {i.price?.nickname ? `(${i.price.nickname})` : ""}
                </option>
              ))}
            </select>
          )}
          <Input label="Отчёт об использовании, единиц" type="number" min={1} value={quantity} onChange={(e) => setQuantity(e.target.value)} className="w-56" />
          <Button type="submit" variant="secondary" disabled={report.isPending || !itemId}>
            Записать
          </Button>
          {report.error && <ErrorNote error={report.error} />}
        </form>
      )}

      <div className="border-t border-line">
        <Table>
          <thead>
            <tr>
              <Th>Когда</Th>
              <Th className="text-right">Единиц</Th>
              <Th>Ключ</Th>
              <Th>Выставлено</Th>
            </tr>
          </thead>
          <tbody>
            {events.data?.data.slice(0, 10).map((e) => (
              <tr key={e.id}>
                <Td className="text-muted">{formatDate(e.timestamp, true)}</Td>
                <Td className="text-right font-mono">{e.quantity.toLocaleString("ru-RU")}</Td>
                <Td className="font-mono text-[11px] text-muted">{e.idempotency_key ? e.idempotency_key.slice(0, 8) + "…" : "—"}</Td>
                <Td className="text-xs text-muted">{e.invoice_item_id ? "да" : "ещё нет"}</Td>
              </tr>
            ))}
            {events.data && events.data.data.length === 0 && (
              <tr>
                <td colSpan={4}>
                  <Empty>Отчётов ещё не было.</Empty>
                </td>
              </tr>
            )}
          </tbody>
        </Table>
      </div>
    </Card>
  );
}
