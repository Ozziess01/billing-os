"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { PaymentStatusBadge } from "@/components/invoices/InvoiceStatusBadge";
import { Button, Dialog, ErrorNote, Select } from "@/components/ui";
import { idempotencyKey } from "@/lib/api";
import { formatMoney } from "@/lib/money";
import { paymentMethods, payments } from "@/services/payments";
import type { Invoice, Payment } from "@/types";

/**
 * Оплата инвойса fake-провайдером. Ключ идемпотентности генерируется на открытие диалога:
 * повторный клик «Оплатить» с тем же ключом вернёт первый платёж, а не создаст второй.
 */
export function PayDialog({ invoice, open, onClose }: { invoice: Invoice; open: boolean; onClose: () => void }) {
  const client = useQueryClient();
  const [method, setMethod] = useState("tok_ok");
  const [key, setKey] = useState(() => idempotencyKey());
  const [result, setResult] = useState<Payment | null>(null);

  const refresh = () => {
    client.invalidateQueries({ queryKey: ["invoices"] });
    client.invalidateQueries({ queryKey: ["payments"] });
    client.invalidateQueries({ queryKey: ["ledger"] });
  };

  const pay = useMutation({
    mutationFn: () => payments.pay({ invoice_id: invoice.id, payment_method: method }, key),
    onSuccess: (res) => {
      setResult(res.data);
      refresh();
    },
  });
  const confirm = useMutation({
    mutationFn: (success: boolean) => payments.confirmFake(result!.provider_payment_id!, success),
    onSuccess: refresh,
  });

  const close = () => {
    onClose();
    setResult(null);
    setKey(idempotencyKey());
    pay.reset();
    confirm.reset();
  };

  return (
    <Dialog open={open} onClose={close} title={`Оплата ${invoice.number ?? ""} · ${formatMoney(invoice.amount_due.amount, invoice.currency)}`}>
      {!result ? (
        <form
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault();
            pay.mutate();
          }}
        >
          <Select label="Платёжный метод" value={method} onChange={(e) => setMethod(e.target.value)}>
            {paymentMethods.map((m) => (
              <option key={m.value} value={m.value}>
                {m.label}
              </option>
            ))}
          </Select>
          <p className="text-xs text-muted">
            Idempotency-Key этой попытки: <span className="font-mono">{key.slice(0, 8)}…</span>. Повтор запроса с тем же ключом вернёт тот же результат.
          </p>
          <ErrorNote error={pay.error} />
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={close}>
              Отмена
            </Button>
            <Button type="submit" disabled={pay.isPending}>
              {pay.isPending ? "Проводим…" : `Оплатить ${formatMoney(invoice.amount_due.amount, invoice.currency)}`}
            </Button>
          </div>
        </form>
      ) : (
        <div className="space-y-3 text-sm">
          <div className="flex items-center gap-2">
            Платёж #{result.attempt_number}: <PaymentStatusBadge status={result.status} />
          </div>
          {result.failure_message && <div className="rounded-md border border-err/30 bg-err/5 px-3 py-2 text-err">{result.failure_message}</div>}
          {result.status === "processing" && result.next_action?.type === "confirm" && (
            <div className="rounded-md bg-panel-2 px-3 py-3">
              <p className="mb-2">Провайдер ждёт подтверждения клиента. В жизни это экран 3-D Secure; здесь — кнопка.</p>
              <div className="flex gap-2">
                <Button size="sm" disabled={confirm.isPending || confirm.isSuccess} onClick={() => confirm.mutate(true)}>
                  Подтвердить
                </Button>
                <Button size="sm" variant="danger" disabled={confirm.isPending || confirm.isSuccess} onClick={() => confirm.mutate(false)}>
                  Отклонить
                </Button>
              </div>
              {confirm.isSuccess && <p className="mt-2 text-xs text-muted">Провайдер отправит вебхук, статус обновится через пару секунд.</p>}
              <ErrorNote error={confirm.error} />
            </div>
          )}
          {result.status === "processing" && !result.next_action && <p className="text-muted">Провайдер принял платёж, результат придёт вебхуком.</p>}
          <div className="flex justify-end">
            <Button variant="secondary" onClick={close}>
              Закрыть
            </Button>
          </div>
        </div>
      )}
    </Dialog>
  );
}
