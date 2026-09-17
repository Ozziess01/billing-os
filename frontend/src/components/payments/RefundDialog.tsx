"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { RefundStatusBadge } from "@/components/invoices/InvoiceStatusBadge";
import { Button, Dialog, ErrorNote, Input } from "@/components/ui";
import { ApiError, idempotencyKey } from "@/lib/api";
import { amountToInput, formatMoney, parseAmount } from "@/lib/money";
import { payments } from "@/services/payments";
import type { Payment, Refund } from "@/types";

export function RefundDialog({ payment, open, onClose }: { payment: Payment; open: boolean; onClose: () => void }) {
  const client = useQueryClient();
  const currency = payment.amount.currency;
  const [amount, setAmount] = useState(() => amountToInput(payment.refundable_amount.amount, currency));
  const [reason, setReason] = useState("");
  const [key, setKey] = useState(() => idempotencyKey());
  const [localError, setLocalError] = useState<string | null>(null);
  const [result, setResult] = useState<Refund | null>(null);

  const refund = useMutation({
    mutationFn: (minor: number) => payments.refund(payment.id, { amount: minor, reason: reason || null }, key),
    onSuccess: (res) => {
      setResult(res.data);
      client.invalidateQueries({ queryKey: ["payments"] });
      client.invalidateQueries({ queryKey: ["invoices"] });
      client.invalidateQueries({ queryKey: ["ledger"] });
    },
  });
  const apiError = refund.error instanceof ApiError ? refund.error : null;

  const close = () => {
    onClose();
    setResult(null);
    setKey(idempotencyKey());
    refund.reset();
  };

  return (
    <Dialog open={open} onClose={close} title={`Возврат по платежу · доступно ${formatMoney(payment.refundable_amount.amount, currency)}`}>
      {!result ? (
        <form
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault();
            const minor = parseAmount(amount, currency);
            if (minor === null || minor <= 0) {
              setLocalError("Введите сумму больше нуля");
              return;
            }
            setLocalError(null);
            refund.mutate(minor);
          }}
        >
          <Input label={`Сумма, ${currency}`} inputMode="decimal" required value={amount} onChange={(e) => setAmount(e.target.value)} error={localError ?? apiError?.field("amount")} hint="Не больше остатка; оригинальный платёж не меняется, возврат идёт отдельной проводкой" />
          <Input label="Причина" value={reason} onChange={(e) => setReason(e.target.value)} error={apiError?.field("reason")} />
          {apiError && !Object.keys(apiError.errors).length && <ErrorNote error={apiError} />}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" onClick={close}>
              Отмена
            </Button>
            <Button type="submit" variant="danger" disabled={refund.isPending}>
              {refund.isPending ? "Возвращаем…" : "Вернуть"}
            </Button>
          </div>
        </form>
      ) : (
        <div className="space-y-3 text-sm">
          <div className="flex items-center gap-2">
            Возврат {formatMoney(result.amount.amount, currency)}: <RefundStatusBadge status={result.status} />
          </div>
          {result.failure_message && <div className="rounded-md border border-err/30 bg-err/5 px-3 py-2 text-err">{result.failure_message}</div>}
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
