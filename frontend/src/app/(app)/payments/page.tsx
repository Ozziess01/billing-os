"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useState } from "react";
import { PaymentStatusBadge } from "@/components/invoices/InvoiceStatusBadge";
import { Card, Empty, Money, PageTitle, Pagination, Select, Table, Td, Th } from "@/components/ui";
import { formatDate } from "@/lib/format";
import { formatMoney } from "@/lib/money";
import { payments } from "@/services/payments";
import type { PaymentStatus } from "@/types";

export default function PaymentsPage() {
  const [status, setStatus] = useState<PaymentStatus | "">("");
  const [page, setPage] = useState(1);
  const list = useQuery({
    queryKey: ["payments", { status, page }],
    queryFn: () => payments.list({ status, page }),
    refetchInterval: (q) => (q.state.data?.data.some((p) => p.status === "processing" || p.status === "pending") ? 3000 : false),
  });

  return (
    <>
      <PageTitle title="Платежи" subtitle="Каждая попытка оплаты — отдельная запись; успешная закрывает инвойс и проводится в леджер." />

      <Card>
        <div className="border-b border-line px-5 py-3">
          <Select value={status} onChange={(e) => { setStatus(e.target.value as PaymentStatus | ""); setPage(1); }} className="w-44">
            <option value="">Все статусы</option>
            <option value="processing">В обработке</option>
            <option value="succeeded">Успешные</option>
            <option value="failed">Отказы</option>
            <option value="canceled">Отменённые</option>
          </Select>
        </div>
        <Table>
          <thead>
            <tr>
              <Th>Платёж</Th>
              <Th>Инвойс</Th>
              <Th>Клиент</Th>
              <Th>Статус</Th>
              <Th className="text-right">Сумма</Th>
              <Th className="text-right">Возвращено</Th>
              <Th>Создан</Th>
            </tr>
          </thead>
          <tbody>
            {list.data?.data.map((p) => (
              <tr key={p.id} className="hover:bg-panel-2/60">
                <Td>
                  <Link href={`/payments/${p.id}`} className="font-mono text-xs font-medium hover:text-accent">
                    {p.id.slice(-8).toLowerCase()} · #{p.attempt_number}
                  </Link>
                </Td>
                <Td>
                  <Link href={`/invoices/${p.invoice_id}`} className="font-mono text-xs hover:text-accent">{p.invoice?.number ?? p.invoice_id}</Link>
                </Td>
                <Td>{p.customer?.name ?? p.customer_id}</Td>
                <Td>
                  <PaymentStatusBadge status={p.status} />
                  {p.failure_code && <div className="mt-1 font-mono text-[11px] text-err">{p.failure_code}</div>}
                </Td>
                <Td className="text-right"><Money formatted={formatMoney(p.amount.amount, p.amount.currency)} /></Td>
                <Td className="text-right text-muted">{p.amount_refunded.amount > 0 ? formatMoney(p.amount_refunded.amount, p.amount.currency) : "—"}</Td>
                <Td className="text-muted">{formatDate(p.created_at, true)}</Td>
              </tr>
            ))}
            {list.data && list.data.data.length === 0 && (
              <tr>
                <td colSpan={7}><Empty>Платежей нет.</Empty></td>
              </tr>
            )}
          </tbody>
        </Table>
        {list.data && <Pagination page={list.data.meta.current_page} lastPage={list.data.meta.last_page} onChange={setPage} />}
      </Card>
    </>
  );
}
