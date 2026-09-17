"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";
import { InvoiceStatusBadge } from "@/components/invoices/InvoiceStatusBadge";
import { Button, Card, Dialog, Empty, ErrorNote, Money, PageTitle, Pagination, Select, Table, Td, Th } from "@/components/ui";
import { useOrganization } from "@/hooks/useAuth";
import { formatDate } from "@/lib/format";
import { formatMoney } from "@/lib/money";
import { customers } from "@/services/customers";
import { invoices } from "@/services/invoices";
import type { InvoiceStatus } from "@/types";

export default function InvoicesPage() {
  return (
    <Suspense>
      <InvoicesView />
    </Suspense>
  );
}

function InvoicesView() {
  const router = useRouter();
  const client = useQueryClient();
  const params = useSearchParams();
  const { current } = useOrganization();
  const customerId = params.get("customer_id") ?? undefined;
  const [status, setStatus] = useState<InvoiceStatus | "">("");
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const [customer, setCustomer] = useState(customerId ?? "");
  const [currency, setCurrency] = useState(current?.default_currency ?? "EUR");

  const list = useQuery({ queryKey: ["invoices", { status, page, customerId }], queryFn: () => invoices.list({ status, page, customer_id: customerId }) });
  const customerList = useQuery({ queryKey: ["customers", { per_page: 100 }], queryFn: () => customers.list({ per_page: 100 }), enabled: creating });
  const create = useMutation({
    mutationFn: () => invoices.create({ customer_id: customer, currency }),
    onSuccess: (res) => {
      client.invalidateQueries({ queryKey: ["invoices"] });
      setCreating(false);
      router.push(`/invoices/${res.data.id}`);
    },
  });

  return (
    <>
      <PageTitle title="Инвойсы" subtitle="Черновик → открыт → оплачен. После финализации суммы и позиции заморожены." actions={<Button onClick={() => setCreating(true)}>Новый инвойс</Button>} />

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
          <Select value={status} onChange={(e) => { setStatus(e.target.value as InvoiceStatus | ""); setPage(1); }} className="w-44">
            <option value="">Все статусы</option>
            <option value="draft">Черновики</option>
            <option value="open">Открытые</option>
            <option value="paid">Оплаченные</option>
            <option value="void">Аннулированные</option>
            <option value="uncollectible">Безнадёжные</option>
          </Select>
          {customerId && (
            <Link href="/invoices" className="text-xs text-accent hover:underline">
              сбросить фильтр по клиенту
            </Link>
          )}
        </div>
        <Table>
          <thead>
            <tr>
              <Th>Номер</Th>
              <Th>Клиент</Th>
              <Th>Статус</Th>
              <Th className="text-right">Сумма</Th>
              <Th className="text-right">К оплате</Th>
              <Th>Срок</Th>
              <Th>Создан</Th>
            </tr>
          </thead>
          <tbody>
            {list.data?.data.map((inv) => (
              <tr key={inv.id} className="hover:bg-panel-2/60">
                <Td>
                  <Link href={`/invoices/${inv.id}`} className="font-mono text-xs font-medium hover:text-accent">
                    {inv.number ?? `draft · ${inv.id.slice(-6).toLowerCase()}`}
                  </Link>
                </Td>
                <Td>{inv.customer?.name ?? inv.customer_id}</Td>
                <Td>
                  <InvoiceStatusBadge status={inv.status} />
                </Td>
                <Td className="text-right">
                  <Money formatted={formatMoney(inv.total.amount, inv.currency)} />
                </Td>
                <Td className="text-right">
                  <Money formatted={formatMoney(inv.amount_due.amount, inv.currency)} />
                </Td>
                <Td className="text-muted">{formatDate(inv.due_at)}</Td>
                <Td className="text-muted">{formatDate(inv.created_at)}</Td>
              </tr>
            ))}
            {list.data && list.data.data.length === 0 && (
              <tr>
                <td colSpan={7}>
                  <Empty>Инвойсов нет.</Empty>
                </td>
              </tr>
            )}
          </tbody>
        </Table>
        {list.data && <Pagination page={list.data.meta.current_page} lastPage={list.data.meta.last_page} onChange={setPage} />}
      </Card>

      <Dialog open={creating} onClose={() => setCreating(false)} title="Новый инвойс (черновик)">
        <form
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault();
            create.mutate();
          }}
        >
          <Select label="Клиент" required value={customer} onChange={(e) => setCustomer(e.target.value)}>
            <option value="">— выберите клиента —</option>
            {customerList.data?.data.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </Select>
          <Select label="Валюта" value={currency} onChange={(e) => setCurrency(e.target.value)}>
            {["EUR", "USD", "GBP", "RUB", "JPY"].map((c) => (
              <option key={c}>{c}</option>
            ))}
          </Select>
          <p className="text-xs text-muted">Позиции добавляются на странице черновика, после финализации они заморожены.</p>
          <ErrorNote error={create.error} />
          <div className="flex justify-end">
            <Button type="submit" disabled={create.isPending || !customer}>
              Создать черновик
            </Button>
          </div>
        </form>
      </Dialog>
    </>
  );
}
