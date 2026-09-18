"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { usePortal } from "@/components/portal/PortalShell";
import { Button, Card, ErrorNote, Input, Select } from "@/components/ui";
import { paymentMethods } from "@/services/payments";
import { portal, PortalError } from "@/services/portal";

export default function PortalBillingPage() {
  const { token, session } = usePortal();
  const client = useQueryClient();
  const [form, setForm] = useState({
    name: session.customer.name,
    email: session.customer.email ?? "",
    default_payment_method: session.customer.default_payment_method ?? "",
  });
  const save = useMutation({
    mutationFn: () => portal.updateBilling(token, { name: form.name, email: form.email || null, default_payment_method: form.default_payment_method || null }),
    onSuccess: () => client.invalidateQueries({ queryKey: ["portal", token, "session"] }),
  });
  const apiError = save.error instanceof PortalError ? save.error : null;

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Реквизиты и оплата</h1>
      <Card className="px-5 py-4">
        <form
          className="max-w-md space-y-3"
          onSubmit={(e) => {
            e.preventDefault();
            save.mutate();
          }}
        >
          <Input label="Название" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={apiError?.errors.name?.[0]} />
          <Input label="Почта для инвойсов" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} error={apiError?.errors.email?.[0]} />
          <Select label="Способ оплаты подписок" value={form.default_payment_method} onChange={(e) => setForm({ ...form, default_payment_method: e.target.value })} hint="Списывается автоматически при продлении">
            <option value="">— не задан —</option>
            {paymentMethods.map((m) => (
              <option key={m.value} value={m.value}>{m.label}</option>
            ))}
          </Select>
          {apiError && !Object.keys(apiError.errors).length && <ErrorNote error={apiError} />}
          <div className="flex items-center gap-3">
            <Button type="submit" disabled={save.isPending}>Сохранить</Button>
            {save.isSuccess && <span className="text-xs text-ok">Сохранено</span>}
          </div>
        </form>
      </Card>
    </div>
  );
}
