"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { Badge, Button, Card, Empty, ErrorNote, Input, PageTitle, Select, Table, Td, Th } from "@/components/ui";
import { useOrganization, useUser } from "@/hooks/useAuth";
import { formatDate } from "@/lib/format";
import { ApiKeysCard } from "@/components/settings/ApiKeysCard";
import { organizations } from "@/services/organizations";
import type { Role } from "@/types";

export default function SettingsPage() {
  const client = useQueryClient();
  const { current } = useOrganization();
  const { data: me } = useUser();
  const canManage = current?.role === "owner" || current?.role === "admin";
  const [invite, setInvite] = useState({ email: "", role: "developer" as Role });
  const [name, setName] = useState<string | null>(null);

  const members = useQuery({ queryKey: ["members", current?.id], queryFn: () => organizations.members(current!.id), enabled: !!current });
  const refresh = () => {
    client.invalidateQueries({ queryKey: ["members"] });
    client.invalidateQueries({ queryKey: ["me"] });
  };
  const add = useMutation({
    mutationFn: () => organizations.addMember(current!.id, invite),
    onSuccess: () => {
      refresh();
      setInvite({ email: "", role: "developer" });
    },
  });
  const change = useMutation({ mutationFn: ({ id, role }: { id: number; role: Role }) => organizations.changeRole(current!.id, id, role), onSuccess: refresh });
  const remove = useMutation({ mutationFn: (id: number) => organizations.removeMember(current!.id, id), onSuccess: refresh });
  const rename = useMutation({
    mutationFn: (value: string) => organizations.update(current!.id, { name: value }),
    onSuccess: () => {
      refresh();
      setName(null);
    },
  });

  if (!current) return null;

  return (
    <>
      <PageTitle title="Организация" subtitle={`${current.name} · slug ${current.slug} · валюта по умолчанию ${current.default_currency}`} />

      <div className="space-y-6">
        {canManage && (
          <Card title="Название">
            <form
              className="flex items-end gap-2 px-5 py-4"
              onSubmit={(e) => {
                e.preventDefault();
                rename.mutate(name ?? current.name);
              }}
            >
              <Input label="Название" value={name ?? current.name} onChange={(e) => setName(e.target.value)} className="max-w-sm" />
              <Button type="submit" variant="secondary" disabled={rename.isPending || name === null || name === current.name}>
                Сохранить
              </Button>
            </form>
          </Card>
        )}

        <Card title="Участники">
          {(add.error || change.error || remove.error) && (
            <div className="px-5 pt-4">
              <ErrorNote error={add.error ?? change.error ?? remove.error} />
            </div>
          )}
          <Table>
            <thead>
              <tr>
                <Th>Имя</Th>
                <Th>Почта</Th>
                <Th>Роль</Th>
                <Th>В организации с</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {members.data?.data.map((m) => (
                <tr key={m.id}>
                  <Td className="font-medium">{m.name}</Td>
                  <Td className="text-muted">{m.email}</Td>
                  <Td>
                    {canManage && m.role !== "owner" ? (
                      <Select value={m.role} onChange={(e) => change.mutate({ id: m.id, role: e.target.value as Role })} className="h-8 w-36">
                        <option value="admin">admin</option>
                        <option value="developer">developer</option>
                        <option value="viewer">viewer</option>
                      </Select>
                    ) : (
                      <Badge tone={m.role === "owner" ? "accent" : "muted"}>{m.role}</Badge>
                    )}
                  </Td>
                  <Td className="text-muted">{formatDate(m.joined_at)}</Td>
                  <Td className="text-right">
                    {m.role !== "owner" && (canManage || m.user_id === me?.data.id) && (
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => confirm(m.user_id === me?.data.id ? "Выйти из организации?" : `Удалить ${m.name}?`) && remove.mutate(m.id)}
                      >
                        {m.user_id === me?.data.id ? "выйти" : "удалить"}
                      </Button>
                    )}
                  </Td>
                </tr>
              ))}
              {members.data && members.data.data.length === 0 && (
                <tr>
                  <td colSpan={5}>
                    <Empty>Участников нет.</Empty>
                  </td>
                </tr>
              )}
            </tbody>
          </Table>
          {canManage && (
            <form
              className="flex flex-wrap items-end gap-2 border-t border-line px-5 py-4"
              onSubmit={(e) => {
                e.preventDefault();
                add.mutate();
              }}
            >
              <Input label="Почта зарегистрированного пользователя" type="email" required value={invite.email} onChange={(e) => setInvite({ ...invite, email: e.target.value })} className="w-72" />
              <Select label="Роль" value={invite.role} onChange={(e) => setInvite({ ...invite, role: e.target.value as Role })} className="w-36">
                <option value="admin">admin</option>
                <option value="developer">developer</option>
                <option value="viewer">viewer</option>
              </Select>
              <Button type="submit" disabled={add.isPending}>
                Добавить
              </Button>
            </form>
          )}
        </Card>

        {canManage && <ApiKeysCard />}

        <Card title="Роли">
          <div className="grid gap-4 px-5 py-4 text-sm md:grid-cols-4">
            <div>
              <Badge tone="accent">owner</Badge>
              <p className="mt-2 text-muted">Всё, плюс передача владения и удаление организации.</p>
            </div>
            <div>
              <Badge>admin</Badge>
              <p className="mt-2 text-muted">Участники, возвраты, аннулирование инвойсов, удаление записей.</p>
            </div>
            <div>
              <Badge>developer</Badge>
              <p className="mt-2 text-muted">Клиенты, продукты, цены, подписки, API-ключи.</p>
            </div>
            <div>
              <Badge>viewer</Badge>
              <p className="mt-2 text-muted">Только чтение.</p>
            </div>
          </div>
        </Card>
      </div>
    </>
  );
}
