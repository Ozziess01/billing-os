"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { CustomerForm } from "@/components/customers/CustomerForm";
import { Button, Card, Dialog, Empty, Input, PageTitle, Pagination, Table, Td, Th } from "@/components/ui";
import { formatDate } from "@/lib/format";
import { customers } from "@/services/customers";

export default function CustomersPage() {
  const router = useRouter();
  const client = useQueryClient();
  const [q, setQ] = useState("");
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState(false);

  const list = useQuery({ queryKey: ["customers", { q, page }], queryFn: () => customers.list({ q, page }) });
  const create = useMutation({
    mutationFn: customers.create,
    onSuccess: (res) => {
      client.invalidateQueries({ queryKey: ["customers"] });
      setOpen(false);
      router.push(`/customers/${res.data.id}`);
    },
  });

  return (
    <>
      <PageTitle
        title="Клиенты"
        subtitle="Кому вы выставляете счета."
        actions={<Button onClick={() => setOpen(true)}>Новый клиент</Button>}
      />

      <Card>
        <div className="border-b border-line px-5 py-3">
          <Input
            placeholder="Поиск по имени, почте или внешнему id…"
            value={q}
            onChange={(e) => {
              setQ(e.target.value);
              setPage(1);
            }}
            className="max-w-sm"
          />
        </div>
        <Table>
          <thead>
            <tr>
              <Th>Клиент</Th>
              <Th>Почта</Th>
              <Th>Внешний ID</Th>
              <Th className="text-right">Подписки</Th>
              <Th>Создан</Th>
            </tr>
          </thead>
          <tbody>
            {list.data?.data.map((c) => (
              <tr key={c.id} className="hover:bg-panel-2/60">
                <Td>
                  <Link href={`/customers/${c.id}`} className="font-medium hover:text-accent">
                    {c.name}
                  </Link>
                </Td>
                <Td className="text-muted">{c.email ?? "—"}</Td>
                <Td className="font-mono text-xs text-muted">{c.external_id ?? "—"}</Td>
                <Td className="text-right font-mono">{c.subscriptions_count ?? 0}</Td>
                <Td className="text-muted">{formatDate(c.created_at)}</Td>
              </tr>
            ))}
            {list.data && list.data.data.length === 0 && (
              <tr>
                <td colSpan={5}>
                  <Empty>{q ? "Ничего не найдено." : "Клиентов пока нет."}</Empty>
                </td>
              </tr>
            )}
          </tbody>
        </Table>
        {list.data && <Pagination page={list.data.meta.current_page} lastPage={list.data.meta.last_page} onChange={setPage} />}
      </Card>

      <Dialog open={open} onClose={() => setOpen(false)} title="Новый клиент">
        <CustomerForm onSubmit={(input) => create.mutate(input)} pending={create.isPending} error={create.error} submitLabel="Создать" />
      </Dialog>
    </>
  );
}
