"use client";

import { useQuery } from "@tanstack/react-query";
import { Fragment, useState } from "react";
import { Badge, Card, Empty, PageTitle, Pagination, Table, Td, Th, type Tone } from "@/components/ui";
import { formatDate } from "@/lib/format";
import { webhooks } from "@/services/ledger";

const tones: Record<string, Tone> = { received: "muted", processing: "warn", processed: "ok", ignored: "muted", failed: "err" };

export default function WebhooksPage() {
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<string | null>(null);
  const list = useQuery({ queryKey: ["webhooks", { page }], queryFn: () => webhooks.events({ page }), refetchInterval: 5000 });

  return (
    <>
      <PageTitle
        title="Вебхуки"
        subtitle="События провайдера: подпись проверена, событие сохранено, дубликаты по event_id отброшены, обработка — в очереди."
      />

      <Card>
        <Table>
          <thead>
            <tr>
              <Th>Получен</Th>
              <Th>Провайдер</Th>
              <Th>Тип</Th>
              <Th>Event ID</Th>
              <Th>Статус</Th>
              <Th className="text-right">Попыток</Th>
            </tr>
          </thead>
          <tbody>
            {list.data?.data.map((e) => (
              <Fragment key={e.id}>
                <tr className="cursor-pointer hover:bg-panel-2/60" onClick={() => setOpen(open === e.id ? null : e.id)}>
                  <Td className="text-muted">{formatDate(e.created_at, true)}</Td>
                  <Td>{e.provider}</Td>
                  <Td className="font-mono text-xs">{e.type}</Td>
                  <Td className="font-mono text-xs text-muted">{e.event_id}</Td>
                  <Td>
                    <Badge tone={tones[e.status] ?? "muted"}>{e.status}</Badge>
                    {e.error && <div className="mt-1 text-xs text-err">{e.error}</div>}
                  </Td>
                  <Td className="text-right font-mono">{e.attempts}</Td>
                </tr>
                {open === e.id && (
                  <tr>
                    <td colSpan={6} className="border-b border-line/70 bg-panel-2/40 px-5 py-3">
                      <pre className="overflow-x-auto font-mono text-xs">{JSON.stringify(e.payload, null, 2)}</pre>
                    </td>
                  </tr>
                )}
              </Fragment>
            ))}
            {list.data && list.data.data.length === 0 && (
              <tr>
                <td colSpan={6}>
                  <Empty>Событий ещё не было. Оплатите инвойс методом «успех придёт вебхуком».</Empty>
                </td>
              </tr>
            )}
          </tbody>
        </Table>
        {list.data && <Pagination page={list.data.meta.current_page} lastPage={list.data.meta.last_page} onChange={setPage} />}
      </Card>
    </>
  );
}
