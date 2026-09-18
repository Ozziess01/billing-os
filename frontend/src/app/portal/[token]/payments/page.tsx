"use client";

import { useQuery } from "@tanstack/react-query";
import { PaymentStatusBadge } from "@/components/invoices/InvoiceStatusBadge";
import { usePortal } from "@/components/portal/PortalShell";
import { Card, Empty, Money, Table, Td, Th } from "@/components/ui";
import { formatDate } from "@/lib/format";
import { formatMoney } from "@/lib/money";
import { portal } from "@/services/portal";

export default function PortalPaymentsPage() {
  const { token } = usePortal();
  const list = useQuery({ queryKey: ["portal", token, "payments"], queryFn: () => portal.payments(token) });

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">История платежей</h1>
      <Card>
        <Table>
          <thead>
            <tr>
              <Th>Когда</Th>
              <Th>Инвойс</Th>
              <Th>Статус</Th>
              <Th className="text-right">Сумма</Th>
              <Th className="text-right">Возвращено</Th>
            </tr>
          </thead>
          <tbody>
            {list.data?.data.map((p) => (
              <tr key={p.id}>
                <Td className="text-muted">{formatDate(p.created_at, true)}</Td>
                <Td className="font-mono text-xs">{p.invoice?.number ?? p.invoice_id}</Td>
                <Td><PaymentStatusBadge status={p.status} /></Td>
                <Td className="text-right"><Money formatted={formatMoney(p.amount.amount, p.amount.currency)} /></Td>
                <Td className="text-right text-muted">{p.amount_refunded.amount > 0 ? formatMoney(p.amount_refunded.amount, p.amount.currency) : "—"}</Td>
              </tr>
            ))}
            {list.data && list.data.data.length === 0 && (
              <tr>
                <td colSpan={5}><Empty>Платежей ещё не было.</Empty></td>
              </tr>
            )}
          </tbody>
        </Table>
      </Card>
    </div>
  );
}
