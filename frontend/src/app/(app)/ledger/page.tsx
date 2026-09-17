"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useState } from "react";
import { Badge, Card, Empty, Money, PageTitle, Pagination, Select, Table, Td, Th } from "@/components/ui";
import { formatDate } from "@/lib/format";
import { formatMoney } from "@/lib/money";
import { ledger } from "@/services/ledger";

const typeLabel: Record<string, string> = {
  invoice: "Инвойс",
  payment: "Платёж",
  refund: "Возврат",
  adjustment: "Списание",
};

const accountLabel: Record<string, string> = {
  cash: "Денежные средства",
  receivable: "Дебиторская задолженность",
  revenue: "Выручка",
  adjustments: "Списания",
};

function referenceLink(type: string, id: string) {
  return type === "invoice" ? `/invoices/${id}` : type === "payment" ? `/payments/${id}` : null;
}

export default function LedgerPage() {
  const [type, setType] = useState("");
  const [page, setPage] = useState(1);
  const accounts = useQuery({ queryKey: ["ledger", "accounts"], queryFn: ledger.accounts });
  const transactions = useQuery({ queryKey: ["ledger", "transactions", { type, page }], queryFn: () => ledger.transactions({ type: type || undefined, page }) });

  return (
    <>
      <PageTitle title="Леджер" subtitle="Двойная запись, append-only. Каждая проводка сбалансирована — это проверяет сама база на commit." />

      <div className="mb-6 grid gap-4 md:grid-cols-4">
        {accounts.data?.data.map((a) => (
          <Card key={a.id} className="px-5 py-4">
            <div className="text-xs font-medium text-muted">
              {accountLabel[a.type] ?? a.name} · {a.currency}
            </div>
            <div className="mt-2 font-mono text-xl tabular-nums">{formatMoney(a.balance.amount, a.currency)}</div>
            <div className="mt-1 text-[11px] text-muted">{a.type === "revenue" ? "кредитовый счёт" : "дебетовый счёт"}</div>
          </Card>
        ))}
        {accounts.data && accounts.data.data.length === 0 && (
          <Card className="px-5 py-4 md:col-span-4">
            <Empty>Счета появятся после первого финализированного инвойса.</Empty>
          </Card>
        )}
      </div>

      <Card title="Проводки" actions={
        <Select value={type} onChange={(e) => { setType(e.target.value); setPage(1); }} className="h-8 w-40">
          <option value="">Все типы</option>
          <option value="invoice">Инвойсы</option>
          <option value="payment">Платежи</option>
          <option value="refund">Возвраты</option>
          <option value="adjustment">Списания</option>
        </Select>
      }>
        <Table>
          <thead>
            <tr>
              <Th>Когда</Th>
              <Th>Тип</Th>
              <Th>Описание</Th>
              <Th>Счёт</Th>
              <Th className="text-right">Дебет</Th>
              <Th className="text-right">Кредит</Th>
            </tr>
          </thead>
          <tbody>
            {transactions.data?.data.flatMap((t) =>
              (t.entries ?? []).map((e, i) => (
                <tr key={`${t.id}-${i}`} className={i === 0 ? "border-t-2 border-line" : ""}>
                  <Td className="text-muted">{i === 0 ? formatDate(t.posted_at, true) : ""}</Td>
                  <Td>{i === 0 && <Badge tone={t.type === "refund" || t.type === "adjustment" ? "warn" : "muted"}>{typeLabel[t.type] ?? t.type}</Badge>}</Td>
                  <Td>
                    {i === 0 &&
                      (referenceLink(t.reference_type, t.reference_id) ? (
                        <Link href={referenceLink(t.reference_type, t.reference_id)!} className="hover:text-accent">{t.description}</Link>
                      ) : (
                        t.description
                      ))}
                  </Td>
                  <Td className={e.debit.amount ? "" : "pl-10"}>{accountLabel[e.account_type ?? ""] ?? e.account_name}</Td>
                  <Td className="text-right">{e.debit.amount ? <Money formatted={formatMoney(e.debit.amount, t.amount.currency)} /> : ""}</Td>
                  <Td className="text-right">{e.credit.amount ? <Money formatted={formatMoney(e.credit.amount, t.amount.currency)} /> : ""}</Td>
                </tr>
              )),
            )}
            {transactions.data && transactions.data.data.length === 0 && (
              <tr>
                <td colSpan={6}><Empty>Проводок пока нет.</Empty></td>
              </tr>
            )}
          </tbody>
        </Table>
        {transactions.data && <Pagination page={transactions.data.meta.current_page} lastPage={transactions.data.meta.last_page} onChange={setPage} />}
      </Card>
    </>
  );
}
