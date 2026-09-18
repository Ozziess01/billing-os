"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { Badge, Button, Card, Empty, ErrorNote, Input, Table, Td, Th } from "@/components/ui";
import { formatDate } from "@/lib/format";
import { apiKeys } from "@/services/apiKeys";

/** Ключи интеграции: показываются целиком один раз, дальше только префикс. Работают с правами developer. */
export function ApiKeysCard() {
  const client = useQueryClient();
  const [name, setName] = useState("");
  const [plain, setPlain] = useState<string | null>(null);
  const list = useQuery({ queryKey: ["api-keys"], queryFn: apiKeys.list });
  const invalidate = () => client.invalidateQueries({ queryKey: ["api-keys"] });
  const create = useMutation({
    mutationFn: () => apiKeys.create({ name }),
    onSuccess: (res) => {
      setPlain(res.plain_key);
      setName("");
      invalidate();
    },
  });
  const revoke = useMutation({ mutationFn: (id: string) => apiKeys.revoke(id), onSuccess: invalidate });

  return (
    <Card title="API-ключи">
      {plain && (
        <div className="border-b border-line bg-warn/5 px-5 py-4 text-sm">
          <div className="text-xs font-medium text-muted">Новый ключ — скопируйте сейчас, больше он не покажется</div>
          <div className="mt-1 flex items-center gap-2">
            <code className="rounded bg-panel-2 px-2 py-1 font-mono text-xs">{plain}</code>
            <Button variant="ghost" size="sm" onClick={() => navigator.clipboard?.writeText(plain)}>копировать</Button>
            <Button variant="ghost" size="sm" onClick={() => setPlain(null)}>скрыть</Button>
          </div>
          <p className="mt-2 text-xs text-muted">
            Пример: <code className="font-mono">curl -H &quot;Authorization: Bearer {plain.slice(0, 16)}…&quot; {process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8090/api/v1"}/customers</code>
          </p>
        </div>
      )}
      <Table>
        <thead>
          <tr>
            <Th>Название</Th>
            <Th>Префикс</Th>
            <Th>Создал</Th>
            <Th>Использован</Th>
            <Th>Статус</Th>
            <Th />
          </tr>
        </thead>
        <tbody>
          {list.data?.data.map((k) => (
            <tr key={k.id}>
              <Td className="font-medium">{k.name}</Td>
              <Td className="font-mono text-xs">{k.prefix}…</Td>
              <Td className="text-muted">{k.created_by ?? "—"}</Td>
              <Td className="text-muted">{k.last_used_at ? formatDate(k.last_used_at, true) : "ещё нет"}</Td>
              <Td>{k.active ? <Badge tone="ok">активен</Badge> : <Badge>{k.revoked_at ? "отозван" : "истёк"}</Badge>}</Td>
              <Td className="text-right">
                {k.active && (
                  <Button variant="ghost" size="sm" onClick={() => confirm(`Отозвать ключ «${k.name}»?`) && revoke.mutate(k.id)}>
                    отозвать
                  </Button>
                )}
              </Td>
            </tr>
          ))}
          {list.data && list.data.data.length === 0 && (
            <tr>
              <td colSpan={6}>
                <Empty>Ключей нет. Ключ нужен вашему приложению, чтобы создавать клиентов, подписки и слать usage.</Empty>
              </td>
            </tr>
          )}
        </tbody>
      </Table>
      <form
        className="flex flex-wrap items-end gap-2 border-t border-line px-5 py-4"
        onSubmit={(e) => {
          e.preventDefault();
          create.mutate();
        }}
      >
        <Input label="Название ключа" required placeholder="production backend" value={name} onChange={(e) => setName(e.target.value)} className="w-72" />
        <Button type="submit" disabled={create.isPending || !name}>
          Выпустить ключ
        </Button>
        {(create.error || revoke.error) && <ErrorNote error={create.error ?? revoke.error} />}
      </form>
    </Card>
  );
}
