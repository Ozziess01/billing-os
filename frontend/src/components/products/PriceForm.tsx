"use client";

import { useState } from "react";
import { Button, ErrorNote, Input, Select } from "@/components/ui";
import { useOrganization } from "@/hooks/useAuth";
import { ApiError } from "@/lib/api";
import { parseAmount } from "@/lib/money";
import type { PriceInput } from "@/services/products";
import type { BillingInterval } from "@/types";

const CURRENCIES = ["EUR", "USD", "GBP", "CHF", "PLN", "CZK", "SEK", "NOK", "DKK", "CAD", "AUD", "RUB", "TRY", "INR", "BRL", "MXN", "JPY", "KRW", "HUF", "KWD", "BHD"];

export function PriceForm({ productId, onSubmit, pending, error }: { productId: string; onSubmit: (input: PriceInput) => void; pending: boolean; error: unknown }) {
  const { current } = useOrganization();
  const [form, setForm] = useState({
    nickname: "",
    currency: current?.default_currency ?? "EUR",
    amount: "",
    billing_interval: "month" as BillingInterval,
    interval_count: "1",
    usage_type: "licensed" as "licensed" | "metered",
    unit_amount_decimal: "0.1",
  });
  const [localError, setLocalError] = useState<string | null>(null);
  const apiError = error instanceof ApiError ? error : null;

  return (
    <form
      className="space-y-3"
      onSubmit={(e) => {
        e.preventDefault();
        if (form.usage_type === "metered") {
          if (!/^\d+(\.\d+)?$/.test(form.unit_amount_decimal)) {
            setLocalError("Введите цену за единицу, например 0.1");
            return;
          }
          setLocalError(null);
          onSubmit({
            product_id: productId,
            nickname: form.nickname || null,
            currency: form.currency,
            unit_amount: 0,
            usage_type: "metered",
            unit_amount_decimal: form.unit_amount_decimal,
            billing_interval: form.billing_interval,
            interval_count: Number(form.interval_count) || 1,
          });
          return;
        }
        const amount = parseAmount(form.amount, form.currency);
        if (amount === null) {
          setLocalError("Введите сумму числом, например 19.99");
          return;
        }
        setLocalError(null);
        onSubmit({
          product_id: productId,
          nickname: form.nickname || null,
          currency: form.currency,
          unit_amount: amount,
          billing_interval: form.billing_interval,
          interval_count: Number(form.interval_count) || 1,
        });
      }}
    >
      <Input label="Название цены" placeholder="Pro monthly" value={form.nickname} onChange={(e) => setForm({ ...form, nickname: e.target.value })} error={apiError?.field("nickname")} />
      <Select label="Тип" value={form.usage_type} onChange={(e) => setForm({ ...form, usage_type: e.target.value as "licensed" | "metered" })}>
        <option value="licensed">Фиксированная — сумма за период × количество</option>
        <option value="metered">По использованию — единицы × цена, по факту за период</option>
      </Select>
      <div className="grid grid-cols-2 gap-3">
        {form.usage_type === "metered" ? (
          <Input label="Цена за единицу, в сотых долях валюты" inputMode="decimal" placeholder="0.1" required autoFocus value={form.unit_amount_decimal} onChange={(e) => setForm({ ...form, unit_amount_decimal: e.target.value })} error={localError ?? apiError?.field("unit_amount_decimal")} hint="0.1 = €0.001 за единицу; округление один раз на весь период" />
        ) : (
          <Input label="Сумма за единицу" inputMode="decimal" placeholder="19.99" required autoFocus value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} error={localError ?? apiError?.field("unit_amount")} hint="Хранится в минорных единицах: 19.99 → 1999" />
        )}
        <Select label="Валюта" value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })} error={apiError?.field("currency")}>
          {CURRENCIES.map((c) => (
            <option key={c}>{c}</option>
          ))}
        </Select>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <Select label="Интервал" value={form.billing_interval} onChange={(e) => setForm({ ...form, billing_interval: e.target.value as BillingInterval })} error={apiError?.field("billing_interval")}>
          <option value="day">день</option>
          <option value="week">неделя</option>
          <option value="month">месяц</option>
          <option value="year">год</option>
        </Select>
        <Input label="Каждые N интервалов" type="number" min={1} max={365} value={form.interval_count} onChange={(e) => setForm({ ...form, interval_count: e.target.value })} error={apiError?.field("interval_count")} />
      </div>
      <p className="text-xs text-muted">Сумму, валюту и интервал у созданной цены изменить нельзя — под неё оформляются подписки. Понадобится другая цена — создайте новую и деактивируйте старую.</p>
      {apiError && !Object.keys(apiError.errors).length && <ErrorNote error={apiError} />}
      <div className="flex justify-end pt-1">
        <Button type="submit" disabled={pending}>
          {pending ? "Создаём…" : "Создать цену"}
        </Button>
      </div>
    </form>
  );
}
