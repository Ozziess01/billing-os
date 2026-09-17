"use client";

import { useState } from "react";
import { Button, ErrorNote, Input, Textarea } from "@/components/ui";
import { ApiError } from "@/lib/api";
import type { ProductInput } from "@/services/products";
import type { Product } from "@/types";

export function ProductForm({ initial, onSubmit, pending, error, submitLabel = "Сохранить" }: { initial?: Product; onSubmit: (input: ProductInput) => void; pending: boolean; error: unknown; submitLabel?: string }) {
  const [form, setForm] = useState({ name: initial?.name ?? "", description: initial?.description ?? "" });
  const apiError = error instanceof ApiError ? error : null;

  return (
    <form
      className="space-y-3"
      onSubmit={(e) => {
        e.preventDefault();
        onSubmit({ name: form.name, description: form.description || null });
      }}
    >
      <Input label="Название" required autoFocus value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={apiError?.field("name")} />
      <Textarea label="Описание" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} error={apiError?.field("description")} />
      {apiError && !Object.keys(apiError.errors).length && <ErrorNote error={apiError} />}
      <div className="flex justify-end pt-1">
        <Button type="submit" disabled={pending}>
          {pending ? "Сохраняем…" : submitLabel}
        </Button>
      </div>
    </form>
  );
}
