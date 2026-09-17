"use client";

import { useQuery } from "@tanstack/react-query";
import { useState } from "react";
import { Button, ErrorNote, Input, Select } from "@/components/ui";
import { ApiError } from "@/lib/api";
import { parseAmount, priceLabel } from "@/lib/money";
import type { InvoiceItemInput } from "@/services/invoices";
import { prices as priceApi } from "@/services/products";

/** Позиция инвойса: из цены каталога или произвольная строка с суммой. */
export function InvoiceItemForm({ currency, onSubmit, pending, error }: { currency: string; onSubmit: (input: InvoiceItemInput) => void; pending: boolean; error: unknown }) {
  const [mode, setMode] = useState<"price" | "custom">("price");
  const [priceId, setPriceId] = useState("");
  const [description, setDescription] = useState("");
  const [amount, setAmount] = useState("");
  const [quantity, setQuantity] = useState("1");
  const [localError, setLocalError] = useState<string | null>(null);
  const apiError = error instanceof ApiError ? error : null;

  const priceList = useQuery({ queryKey: ["prices", { active: true, per_page: 100 }], queryFn: () => priceApi.list({ active: true, per_page: 100 }) });
  const compatible = (priceList.data?.data ?? []).filter((p) => p.currency === currency);

  return (
    <form
      className="space-y-3"
      onSubmit={(e) => {
        e.preventDefault();
        setLocalError(null);
        if (mode === "price") {
          onSubmit({ price_id: priceId, quantity: Number(quantity) || 1 });
          return;
        }
        const unit = parseAmount(amount, currency);
        if (unit === null) {
          setLocalError("Введите сумму числом, например 49.90");
          return;
        }
        onSubmit({ description, unit_amount: unit, quantity: Number(quantity) || 1 });
      }}
    >
      <div className="flex gap-2">
        <Button type="button" size="sm" variant={mode === "price" ? "primary" : "secondary"} onClick={() => setMode("price")}>
          Из каталога
        </Button>
        <Button type="button" size="sm" variant={mode === "custom" ? "primary" : "secondary"} onClick={() => setMode("custom")}>
          Произвольная
        </Button>
      </div>

      {mode === "price" ? (
        <Select label={`Цена (${currency})`} required value={priceId} onChange={(e) => setPriceId(e.target.value)} error={apiError?.field("items") ?? apiError?.field("price_id")}>
          <option value="">— выберите —</option>
          {compatible.map((p) => (
            <option key={p.id} value={p.id}>
              {p.product?.name}
              {p.nickname ? ` (${p.nickname})` : ""} — {priceLabel(p)}
            </option>
          ))}
        </Select>
      ) : (
        <>
          <Input label="Описание" required value={description} onChange={(e) => setDescription(e.target.value)} error={apiError?.field("description")} />
          <Input label={`Сумма за единицу, ${currency}`} inputMode="decimal" required value={amount} onChange={(e) => setAmount(e.target.value)} error={localError ?? apiError?.field("unit_amount")} />
        </>
      )}
      <Input label="Количество" type="number" min={1} value={quantity} onChange={(e) => setQuantity(e.target.value)} error={apiError?.field("quantity")} />
      {apiError && !Object.keys(apiError.errors).length && <ErrorNote error={apiError} />}
      <div className="flex justify-end">
        <Button type="submit" disabled={pending}>
          Добавить позицию
        </Button>
      </div>
    </form>
  );
}
