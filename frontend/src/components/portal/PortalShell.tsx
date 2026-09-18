"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { createContext, useContext } from "react";
import { ErrorNote } from "@/components/ui";
import { formatDate } from "@/lib/format";
import { portal, type PortalSession } from "@/services/portal";

const PortalContext = createContext<{ token: string; session: PortalSession } | null>(null);

export function usePortal() {
  const ctx = useContext(PortalContext);
  if (!ctx) throw new Error("usePortal outside of PortalShell");
  return ctx;
}

/** Каркас клиентского кабинета: токен из ссылки, сессия из API, простая навигация. */
export function PortalShell({ token, children }: { token: string; children: React.ReactNode }) {
  const pathname = usePathname();
  const { data, isLoading, error } = useQuery({ queryKey: ["portal", token, "session"], queryFn: () => portal.session(token), retry: false });

  if (isLoading) return <div className="grid min-h-screen place-items-center text-sm text-muted">Открываем кабинет…</div>;
  if (error || !data) {
    return (
      <div className="grid min-h-screen place-items-center px-4">
        <div className="w-full max-w-md">
          <ErrorNote error={new Error("Ссылка недействительна или истекла. Запросите новую у поставщика.")} />
        </div>
      </div>
    );
  }

  const base = `/portal/${token}`;
  const nav = [
    { href: base, label: "Подписки" },
    { href: `${base}/invoices`, label: "Инвойсы" },
    { href: `${base}/payments`, label: "Платежи" },
    { href: `${base}/billing`, label: "Реквизиты" },
  ];

  return (
    <PortalContext.Provider value={{ token, session: data.data }}>
      <div className="min-h-screen">
        <header className="border-b border-line bg-panel">
          <div className="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-3 px-6 py-4">
            <div>
              <div className="text-xs font-medium uppercase tracking-wider text-muted">{data.data.organization.name}</div>
              <div className="text-lg font-semibold">{data.data.customer.name}</div>
            </div>
            <nav className="flex gap-1">
              {nav.map((item) => {
                const active = item.href === base ? pathname === base || pathname.startsWith(`${base}/subscriptions`) : pathname.startsWith(item.href);
                return (
                  <Link key={item.href} href={item.href} className={`rounded-md px-3 py-1.5 text-sm ${active ? "bg-accent/10 font-medium text-accent" : "text-muted hover:bg-panel-2 hover:text-fg"}`}>
                    {item.label}
                  </Link>
                );
              })}
            </nav>
          </div>
        </header>
        <main className="mx-auto max-w-4xl px-6 py-8">{children}</main>
        <footer className="mx-auto max-w-4xl px-6 pb-8 text-xs text-muted">Ссылка действует до {formatDate(data.data.expires_at, true)}.</footer>
      </div>
    </PortalContext.Provider>
  );
}
