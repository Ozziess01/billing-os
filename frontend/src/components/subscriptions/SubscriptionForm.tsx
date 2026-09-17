"use client";

import { useQuery } from "@tanstack/react-query";
import { useMemo, useState } from "react";
import { Button, ErrorNote, Input, Select } from "@/components/ui";
import { ApiError } from "@/lib/api";
import { formatMoney, intervalText, priceLabel } from "@/lib/money";
import { customers } from "@/services/customers";
import { prices as priceApi } from "@/services/products";
import type { SubscriptionInput } from "@/services/subscriptions";

interface Line {
  price_id: string;
  quantity: string;
}

export function SubscriptionForm({ customerId, onSubmit, pending, error }: { customerId?: string; onSubmit: (input: SubscriptionInput) => void; pending: boolean; error: unknown }) {
  const [customer, setCustomer] = useState(customerId ?? "");
  const [lines, setLines] = useState<Line[]>([{ price_id: "", quantity: "1" }]);
  const [trialDays, setTrialDays] = useState("0");
  const apiError = error instanceof ApiError ? error : null;

  const customerList = useQuery({ queryKey: ["customers", { per_page: 100 }], queryFn: () => customers.list({ per_page: 100 }) });
  const priceList = useQuery({ queryKey: ["prices", { active: true, per_page: 100 }], queryFn: () => priceApi.list({ active: true, per_page: 100 }) });

  const selected = useMemo(() => {
    const byId = new Map((priceList.data?.data ?? []).map((p) => [p.id, p]));
    return lines.map((l) => byId.get(l.price_id)).filter((p) => p !== undefined);
  }, [lines, priceList.data]);

  // цены одной подписки должны совпадать по валюте и интервалу - подсказываем до отправки
  const first = selected[0];
  const compatible = (p: { currency: string; billing_interval: string; interval_count: number }) =>
    !first || (p.currency === first.currency && p.billing_interval === first.billing_interval && p.interval_count === first.interval_count);
  const periodTotal = first
    ? lines.reduce((sum, line) => {
        const p = selected.find((s) => s.id === line.price_id);
        return p && compatible(p) ? sum + p.unit_amount * (Number(line.quantity) || 1) : sum;
      }, 0)
    : 0;

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault();
        onSubmit({
          customer_id: customer,
          items: lines.filter((l) => l.price_id).map((l) => ({ price_id: l.price_id, quantity: Number(l.quantity) || 1 })),
          trial_days: Number(trialDays) || 0,
        });
      }}
    >
      <Select label="Клиент" required value={customer} onChange={(e) => setCustomer(e.target.value)} error={apiError?.field("customer_id")}>
        <option value="">— выберите клиента —</option>
        {customerList.data?.data.map((c) => (
          <option key={c.id} value={c.id}>
            {c.name}
            {c.email ? ` · ${c.email}` : ""}
          </option>
        ))}
      </Select>

      <div className="space-y-2">
        <div className="text-xs font-medium text-muted">Позиции</div>
        {lines.map((line, index) => (
          <div key={index} className="grid grid-cols-[1fr_88px_32px] items-end gap-2">
            <Select value={line.price_id} onChange={(e) => setLines(lines.map((l, i) => (i === index ? { ...l, price_id: e.target.value } : l)))} required>
              <option value="">— цена —</option>
              {priceList.data?.data.map((p) => (
                <option key={p.id} value={p.id} disabled={!compatible(p) && p.id !== line.price_id}>
                  {p.product?.name}
                  {p.nickname ? ` (${p.nickname})` : ""} — {priceLabel(p)}
                </option>
              ))}
            </Select>
            <Input type="number" min={1} value={line.quantity} onChange={(e) => setLines(lines.map((l, i) => (i === index ? { ...l, quantity: e.target.value } : l)))} aria-label="Количество" />
            <Button type="button" variant="ghost" size="sm" disabled={lines.length === 1} onClick={() => setLines(lines.filter((_, i) => i !== index))} aria-label="Убрать">
              ×
            </Button>
          </div>
        ))}
        {apiError?.field("items") && <div className="text-xs text-err">{apiError.field("items")}</div>}
        <Button type="button" variant="ghost" size="sm" onClick={() => setLines([...lines, { price_id: "", quantity: "1" }])}>
          + ещё позиция
        </Button>
      </div>

      <Input label="Триал, дней" type="number" min={0} max={365} value={trialDays} onChange={(e) => setTrialDays(e.target.value)} error={apiError?.field("trial_days")} hint="0 — без триала, подписка сразу активна" />

      {first && (
        <div className="rounded-md bg-panel-2 px-3 py-2 text-sm">
          За период: <span className="font-mono font-medium">{formatMoney(periodTotal, first.currency)}</span>
          <span className="text-muted"> · {intervalText(first)}</span>
        </div>
      )}

      {apiError && !Object.keys(apiError.errors).length && <ErrorNote error={apiError} />}
      <div className="flex justify-end pt-1">
        <Button type="submit" disabled={pending || !customer}>
          {pending ? "Оформляем…" : "Оформить подписку"}
        </Button>
      </div>
    </form>
  );
}
