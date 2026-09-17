"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Sidebar } from "@/components/layout/Sidebar";
import { Button, Card, ErrorNote, Input } from "@/components/ui";
import { useLogout, useOrganization, useUser } from "@/hooks/useAuth";
import { session } from "@/lib/api";
import { organizations as organizationApi } from "@/services/organizations";

export default function AppLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const { isError, isLoading, data } = useUser();
  const { organizations, ready, select } = useOrganization();

  useEffect(() => {
    if (!session.token() || isError) router.replace("/login");
  }, [isError, router]);

  if (isLoading || !data || !ready) {
    return <div className="grid min-h-screen place-items-center text-sm text-muted">Загрузка…</div>;
  }

  if (organizations.length === 0) {
    return <FirstOrganization onCreated={select} />;
  }

  return (
    <div className="flex min-h-screen">
      <Sidebar />
      <main className="flex-1 overflow-y-auto">
        <div className="mx-auto max-w-6xl px-8 py-8">{children}</div>
      </main>
    </div>
  );
}

// пользователь без организации: без неё в приложении нечего делать
function FirstOrganization({ onCreated }: { onCreated: (id: number) => void }) {
  const client = useQueryClient();
  const logout = useLogout();
  const [name, setName] = useState("");
  const create = useMutation({
    mutationFn: () => organizationApi.create({ name }),
    onSuccess: async (res) => {
      await client.invalidateQueries({ queryKey: ["me"] });
      onCreated(res.data.id);
    },
  });

  return (
    <div className="grid min-h-screen place-items-center px-4">
      <Card className="w-full max-w-sm p-6">
        <h1 className="text-base font-semibold">Создайте организацию</h1>
        <p className="mt-1 text-sm text-muted">Клиенты, продукты и подписки живут внутри организации.</p>
        <form
          className="mt-4 space-y-3"
          onSubmit={(e) => {
            e.preventDefault();
            create.mutate();
          }}
        >
          <Input label="Название" required autoFocus value={name} onChange={(e) => setName(e.target.value)} />
          <ErrorNote error={create.error} />
          <Button type="submit" className="w-full" disabled={create.isPending}>
            Создать
          </Button>
        </form>
        <button type="button" onClick={() => logout.mutate()} className="mt-4 w-full text-center text-xs text-muted hover:text-fg">
          Выйти
        </button>
      </Card>
    </div>
  );
}
