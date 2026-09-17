"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useState } from "react";
import { StatusBadge } from "@/components/subscriptions/StatusBadge";
import { SubscriptionForm } from "@/components/subscriptions/SubscriptionForm";
import { Button, Card, Dialog, Empty, Money, PageTitle, Pagination, Select, Table, Td, Th } from "@/components/ui";
import { formatDate } from "@/lib/format";
import { formatMoney, itemLabel } from "@/lib/money";
import { subscriptions } from "@/services/subscriptions";
import type { SubscriptionStatus } from "@/types";

export default function SubscriptionsPage() {
  return (
    <Suspense>
      <SubscriptionsView />
    </Suspense>
  );
}

function SubscriptionsView() {
  const router = useRouter();
  const client = useQueryClient();
  const params = useSearchParams();
  const customerId = params.get("customer_id") ?? undefined;
  const [status, setStatus] = useState<SubscriptionStatus | "">("");
  const [page, setPage] = useState(1);
  // /subscriptions?customer_id=…&new=1 открывает форму сразу с выбранным клиентом
  const [open, setOpen] = useState(() => params.get("new") === "1");

  const list = useQuery({ queryKey: ["subscriptions", { status, page, customerId }], queryFn: () => subscriptions.list({ status, page, customer_id: customerId }) });
  const create = useMutation({
    mutationFn: subscriptions.create,
    onSuccess: (res) => {
      client.invalidateQueries({ queryKey: ["subscriptions"] });
      client.invalidateQueries({ queryKey: ["dashboard"] });
      client.invalidateQueries({ queryKey: ["customers"] });
      setOpen(false);
      router.push(`/subscriptions/${res.data.id}`);
    },
  });

  return (
    <>
      <PageTitle title="Подписки" subtitle="Регулярные списания клиентов." actions={<Button onClick={() => setOpen(true)}>Оформить подписку</Button>} />

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
          <Select value={status} onChange={(e) => { setStatus(e.target.value as SubscriptionStatus | ""); setPage(1); }} className="w-44">
            <option value="">Все статусы</option>
            <option value="trialing">Триал</option>
            <option value="active">Активные</option>
            <option value="past_due">Просроченные</option>
            <option value="incomplete">Не оплаченные</option>
            <option value="canceled">Отменённые</option>
          </Select>
          {customerId && (
            <Link href="/subscriptions" className="text-xs text-accent hover:underline">
              сбросить фильтр по клиенту
            </Link>
          )}
        </div>
        <Table>
          <thead>
            <tr>
              <Th>Клиент</Th>
              <Th>Статус</Th>
              <Th>Позиции</Th>
              <Th className="text-right">За период</Th>
              <Th>Текущий период</Th>
            </tr>
          </thead>
          <tbody>
            {list.data?.data.map((s) => (
              <tr key={s.id} className="hover:bg-panel-2/60">
                <Td>
                  <Link href={`/subscriptions/${s.id}`} className="font-medium hover:text-accent">
                    {s.customer?.name ?? s.customer_id}
                  </Link>
                </Td>
                <Td>
                  <StatusBadge status={s.status} cancelAtPeriodEnd={s.cancel_at_period_end} />
                </Td>
                <Td className="text-muted">{s.items?.map(itemLabel).join(", ")}</Td>
                <Td className="text-right">{s.period_amount && <Money formatted={formatMoney(s.period_amount.amount, s.period_amount.currency)} />}</Td>
                <Td className="text-muted">
                  {formatDate(s.current_period_start)} — {formatDate(s.current_period_end)}
                </Td>
              </tr>
            ))}
            {list.data && list.data.data.length === 0 && (
              <tr>
                <td colSpan={5}>
                  <Empty>Подписок нет.</Empty>
                </td>
              </tr>
            )}
          </tbody>
        </Table>
        {list.data && <Pagination page={list.data.meta.current_page} lastPage={list.data.meta.last_page} onChange={setPage} />}
      </Card>

      <Dialog open={open} onClose={() => setOpen(false)} title="Новая подписка" width="max-w-2xl">
        <SubscriptionForm customerId={customerId} onSubmit={(input) => create.mutate(input)} pending={create.isPending} error={create.error} />
      </Dialog>
    </>
  );
}
