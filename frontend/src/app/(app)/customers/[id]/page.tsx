"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useState } from "react";
import { CustomerForm } from "@/components/customers/CustomerForm";
import { StatusBadge } from "@/components/subscriptions/StatusBadge";
import { BackLink, Button, Card, Dialog, Empty, ErrorNote, Money, PageTitle, Table, Td, Th } from "@/components/ui";
import { formatDate } from "@/lib/format";
import { formatMoney, itemLabel } from "@/lib/money";
import { customers } from "@/services/customers";

export default function CustomerPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const client = useQueryClient();
  const [editing, setEditing] = useState(false);

  const { data, isLoading, error } = useQuery({ queryKey: ["customers", id], queryFn: () => customers.get(id) });
  const update = useMutation({
    mutationFn: (input: Parameters<typeof customers.update>[1]) => customers.update(id, input),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: ["customers"] });
      setEditing(false);
    },
  });
  const remove = useMutation({
    mutationFn: () => customers.remove(id),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: ["customers"] });
      router.replace("/customers");
    },
  });

  if (isLoading) return <div className="text-sm text-muted">Загрузка…</div>;
  if (error || !data) return <ErrorNote error={error ?? new Error("Клиент не найден.")} />;

  const c = data.data;

  return (
    <>
      <BackLink href="/customers">Клиенты</BackLink>
      <PageTitle
        title={c.name}
        subtitle={c.email ?? undefined}
        actions={
          <>
            <Link href={`/subscriptions?customer_id=${c.id}&new=1`}>
              <Button variant="secondary">Оформить подписку</Button>
            </Link>
            <Button variant="secondary" onClick={() => setEditing(true)}>
              Изменить
            </Button>
            <Button variant="danger" disabled={remove.isPending || (c.subscriptions?.length ?? 0) > 0} title={(c.subscriptions?.length ?? 0) > 0 ? "У клиента есть подписки" : undefined} onClick={() => confirm("Удалить клиента?") && remove.mutate()}>
              Удалить
            </Button>
          </>
        }
      />
      {remove.error && <div className="mb-4"><ErrorNote error={remove.error} /></div>}

      <div className="grid gap-6 lg:grid-cols-3">
        <Card title="Карточка" className="lg:col-span-1">
          <dl className="space-y-3 px-5 py-4 text-sm">
            <Row label="ID">
              <span className="font-mono text-xs">{c.id}</span>
            </Row>
            <Row label="Внешний ID">{c.external_id ? <span className="font-mono text-xs">{c.external_id}</span> : "—"}</Row>
            <Row label="Описание">{c.description ?? "—"}</Row>
            <Row label="Создан">{formatDate(c.created_at, true)}</Row>
            {Object.keys(c.metadata ?? {}).length > 0 && (
              <Row label="Metadata">
                <pre className="rounded bg-panel-2 p-2 font-mono text-xs">{JSON.stringify(c.metadata, null, 2)}</pre>
              </Row>
            )}
          </dl>
        </Card>

        <Card title="Подписки" className="lg:col-span-2">
          <Table>
            <thead>
              <tr>
                <Th>Статус</Th>
                <Th>Позиции</Th>
                <Th className="text-right">За период</Th>
                <Th>Период</Th>
              </tr>
            </thead>
            <tbody>
              {c.subscriptions?.map((s) => (
                <tr key={s.id} className="hover:bg-panel-2/60">
                  <Td>
                    <Link href={`/subscriptions/${s.id}`} className="hover:text-accent">
                      <StatusBadge status={s.status} cancelAtPeriodEnd={s.cancel_at_period_end} />
                    </Link>
                  </Td>
                  <Td className="text-muted">{s.items?.map(itemLabel).join(", ")}</Td>
                  <Td className="text-right">{s.period_amount && <Money formatted={formatMoney(s.period_amount.amount, s.period_amount.currency)} />}</Td>
                  <Td className="text-muted">
                    {formatDate(s.current_period_start)} — {formatDate(s.current_period_end)}
                  </Td>
                </tr>
              ))}
              {(c.subscriptions?.length ?? 0) === 0 && (
                <tr>
                  <td colSpan={4}>
                    <Empty>Подписок нет.</Empty>
                  </td>
                </tr>
              )}
            </tbody>
          </Table>
        </Card>
      </div>

      <Dialog open={editing} onClose={() => setEditing(false)} title="Изменить клиента">
        <CustomerForm initial={c} onSubmit={(input) => update.mutate(input)} pending={update.isPending} error={update.error} />
      </Dialog>
    </>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs text-muted">{label}</dt>
      <dd className="mt-0.5">{children}</dd>
    </div>
  );
}
