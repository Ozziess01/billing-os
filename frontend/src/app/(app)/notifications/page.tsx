"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useState } from "react";
import { Button, Card, Empty, ErrorNote, PageTitle, Pagination, Table, Td, Th } from "@/components/ui";
import { formatDate } from "@/lib/format";
import { notifications } from "@/services/notifications";
import type { NotificationPreference } from "@/types";

function resourceHref(resource: { type: string; id: string } | null): string | null {
  if (!resource) return null;
  return resource.type === "invoice" ? `/invoices/${resource.id}` : resource.type === "payment" ? `/payments/${resource.id}` : resource.type === "subscription" ? `/subscriptions/${resource.id}` : null;
}

export default function NotificationsPage() {
  const client = useQueryClient();
  const [page, setPage] = useState(1);
  const list = useQuery({ queryKey: ["notifications", { page }], queryFn: () => notifications.list({ page }) });
  const prefs = useQuery({ queryKey: ["notifications", "preferences"], queryFn: notifications.preferences });
  const [draft, setDraft] = useState<NotificationPreference[] | null>(null);
  const rows = draft ?? prefs.data?.data ?? [];

  const invalidate = () => client.invalidateQueries({ queryKey: ["notifications"] });
  const read = useMutation({ mutationFn: (id: string) => notifications.read(id), onSuccess: invalidate });
  const readAll = useMutation({ mutationFn: notifications.readAll, onSuccess: invalidate });
  const save = useMutation({
    mutationFn: () => notifications.updatePreferences(rows.map(({ event, in_app, mail }) => ({ event, in_app, mail }))),
    onSuccess: () => {
      setDraft(null);
      invalidate();
    },
  });

  const toggle = (event: string, key: "in_app" | "mail") => setDraft(rows.map((r) => (r.event === event ? { ...r, [key]: !r[key] } : r)));

  return (
    <>
      <PageTitle
        title="Уведомления"
        subtitle="События биллинга для участников организации от developer и выше."
        actions={<Button variant="secondary" disabled={readAll.isPending || (list.data?.meta.unread ?? 0) === 0} onClick={() => readAll.mutate()}>Прочитать все</Button>}
      />

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <Table>
            <thead>
              <tr>
                <Th>Событие</Th>
                <Th>Когда</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {list.data?.data.map((n) => {
                const href = resourceHref(n.resource);
                return (
                  <tr key={n.id} className={n.read_at ? "text-muted" : ""}>
                    <Td>
                      <div className="flex items-start gap-2">
                        {!n.read_at && <span className="mt-1.5 size-2 shrink-0 rounded-full bg-accent" />}
                        <div>
                          <div className="font-medium text-fg">{href ? <Link href={href} className="hover:text-accent">{n.title}</Link> : n.title}</div>
                          <div className="text-xs">{n.body}</div>
                        </div>
                      </div>
                    </Td>
                    <Td className="whitespace-nowrap text-xs">{formatDate(n.created_at, true)}</Td>
                    <Td className="text-right">
                      {!n.read_at && (
                        <Button variant="ghost" size="sm" onClick={() => read.mutate(n.id)}>
                          прочитано
                        </Button>
                      )}
                    </Td>
                  </tr>
                );
              })}
              {list.data && list.data.data.length === 0 && (
                <tr>
                  <td colSpan={3}>
                    <Empty>Уведомлений пока нет.</Empty>
                  </td>
                </tr>
              )}
            </tbody>
          </Table>
          {list.data && <Pagination page={list.data.meta.current_page} lastPage={list.data.meta.last_page} onChange={setPage} />}
        </Card>

        <Card title="Каналы" actions={draft && <Button size="sm" disabled={save.isPending} onClick={() => save.mutate()}>Сохранить</Button>}>
          <Table>
            <thead>
              <tr>
                <Th>Событие</Th>
                <Th className="text-center">In-app</Th>
                <Th className="text-center">Почта</Th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.event}>
                  <Td>
                    {r.label}
                    <div className="font-mono text-[11px] text-muted">{r.event}</div>
                  </Td>
                  <Td className="text-center">
                    <input type="checkbox" checked={r.in_app} onChange={() => toggle(r.event, "in_app")} />
                  </Td>
                  <Td className="text-center">
                    <input type="checkbox" checked={r.mail} onChange={() => toggle(r.event, "mail")} />
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
          {save.error && <div className="px-5 py-3"><ErrorNote error={save.error} /></div>}
          <p className="px-5 py-3 text-xs text-muted">In-app включает и realtime по сокету. Письма уходят на почту аккаунта.</p>
        </Card>
      </div>
    </>
  );
}
