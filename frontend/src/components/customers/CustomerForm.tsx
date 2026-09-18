"use client";

import { useState } from "react";
import { Button, ErrorNote, Input, Select, Textarea } from "@/components/ui";
import { paymentMethods } from "@/services/payments";
import { ApiError } from "@/lib/api";
import type { CustomerInput } from "@/services/customers";
import type { Customer } from "@/types";

export function CustomerForm({
  initial,
  onSubmit,
  pending,
  error,
  submitLabel = "Сохранить",
}: {
  initial?: Customer;
  onSubmit: (input: CustomerInput) => void;
  pending: boolean;
  error: unknown;
  submitLabel?: string;
}) {
  const [form, setForm] = useState({
    name: initial?.name ?? "",
    email: initial?.email ?? "",
    external_id: initial?.external_id ?? "",
    description: initial?.description ?? "",
    default_payment_method: initial?.default_payment_method ?? "",
  });
  const apiError = error instanceof ApiError ? error : null;
  const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => setForm({ ...form, [key]: e.target.value });

  return (
    <form
      className="space-y-3"
      onSubmit={(e) => {
        e.preventDefault();
        onSubmit({
          name: form.name,
          email: form.email || null,
          external_id: form.external_id || null,
          description: form.description || null,
          default_payment_method: form.default_payment_method || null,
        });
      }}
    >
      <Input label="Название" required autoFocus value={form.name} onChange={set("name")} error={apiError?.field("name")} />
      <Input label="Почта" type="email" value={form.email} onChange={set("email")} error={apiError?.field("email")} />
      <Input label="Внешний ID" placeholder="id клиента в вашей системе" value={form.external_id} onChange={set("external_id")} error={apiError?.field("external_id")} hint="Уникален в организации; по нему удобно искать" />
      <Textarea label="Описание" value={form.description} onChange={set("description")} error={apiError?.field("description")} />
      <Select label="Платёжный метод для автосписания" value={form.default_payment_method} onChange={set("default_payment_method")} error={apiError?.field("default_payment_method")} hint="Им списываются инвойсы подписок; без него инвойсы ждут ручной оплаты">
        <option value="">— нет —</option>
        {paymentMethods.map((m) => (
          <option key={m.value} value={m.value}>
            {m.label}
          </option>
        ))}
      </Select>
      {apiError && !Object.keys(apiError.errors).length && <ErrorNote error={apiError} />}
      <div className="flex justify-end gap-2 pt-1">
        <Button type="submit" disabled={pending}>
          {pending ? "Сохраняем…" : submitLabel}
        </Button>
      </div>
    </form>
  );
}
