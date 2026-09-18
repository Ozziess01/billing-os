"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { InvoiceStatusBadge } from "@/components/invoices/InvoiceStatusBadge";
import { usePortal } from "@/components/portal/PortalShell";
import { Card, Empty, Money, Table, Td, Th } from "@/components/ui";
import { formatDate } from "@/lib/format";
import { formatMoney } from "@/lib/money";
import { portal } from "@/services/portal";

export default function PortalInvoicesPage() {
  const { token } = usePortal();
  const list = useQuery({ queryKey: ["portal", token, "invoices"], queryFn: () => portal.invoices(token) });

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold">Инвойсы</h1>
      <Card>
        <Table>
          <thead>
            <tr>
              <Th>Номер</Th>
              <Th>Статус</Th>
              <Th className="text-right">Сумма</Th>
              <Th className="text-right">К оплате</Th>
              <Th>Срок</Th>
            </tr>
          </thead>
          <tbody>
            {list.data?.data.map((inv) => (
              <tr key={inv.id} className="hover:bg-panel-2/60">
                <Td>
                  <Link href={`/portal/${token}/invoices/${inv.id}`} className="font-mono text-xs font-medium hover:text-accent">{inv.number}</Link>
                </Td>
                <Td><InvoiceStatusBadge status={inv.status} /></Td>
                <Td className="text-right"><Money formatted={formatMoney(inv.total.amount, inv.currency)} /></Td>
                <Td className="text-right"><Money formatted={formatMoney(inv.amount_due.amount, inv.currency)} /></Td>
                <Td className="text-muted">{formatDate(inv.due_at)}</Td>
              </tr>
            ))}
            {list.data && list.data.data.length === 0 && (
              <tr>
                <td colSpan={5}><Empty>Инвойсов нет.</Empty></td>
              </tr>
            )}
          </tbody>
        </Table>
      </Card>
    </div>
  );
}
