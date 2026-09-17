"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useLogout, useOrganization, useUser } from "@/hooks/useAuth";

const nav = [
  { href: "/dashboard", label: "Обзор" },
  { href: "/customers", label: "Клиенты" },
  { href: "/products", label: "Продукты" },
  { href: "/prices", label: "Цены" },
  { href: "/subscriptions", label: "Подписки" },
  { href: "/invoices", label: "Инвойсы" },
  { href: "/payments", label: "Платежи" },
  { href: "/ledger", label: "Леджер" },
  { href: "/webhooks", label: "Вебхуки" },
  { href: "/settings", label: "Организация" },
];

export function Sidebar() {
  const pathname = usePathname();
  const { data } = useUser();
  const { organizations, current, select } = useOrganization();
  const logout = useLogout();

  return (
    <aside className="flex h-screen w-60 shrink-0 flex-col border-r border-line bg-panel">
      <div className="flex h-14 items-center gap-2 border-b border-line px-5">
        <span className="grid size-6 place-items-center rounded bg-accent font-mono text-xs font-bold text-white">B</span>
        <span className="text-sm font-semibold tracking-tight">BillingOS</span>
      </div>

      <div className="border-b border-line p-3">
        <label className="block">
          <span className="mb-1 block px-1 text-[11px] font-medium uppercase tracking-wider text-muted">Организация</span>
          <select
            value={current?.id ?? ""}
            onChange={(e) => select(Number(e.target.value))}
            className="h-8 w-full rounded-md border border-line bg-panel-2 px-2 text-sm outline-none focus:border-accent"
          >
            {organizations.map((o) => (
              <option key={o.id} value={o.id}>
                {o.name}
              </option>
            ))}
          </select>
        </label>
        {current?.role && <div className="mt-1 px-1 text-[11px] text-muted">роль: {current.role}</div>}
      </div>

      <nav className="flex-1 space-y-0.5 p-3">
        {nav.map((item) => {
          const active = pathname === item.href || pathname.startsWith(item.href + "/");
          return (
            <Link
              key={item.href}
              href={item.href}
              className={`flex h-8 items-center rounded-md px-2.5 text-sm transition ${
                active ? "bg-accent/10 font-medium text-accent" : "text-muted hover:bg-panel-2 hover:text-fg"
              }`}
            >
              {item.label}
            </Link>
          );
        })}
      </nav>

      <div className="border-t border-line p-3">
        <div className="mb-2 px-2">
          <div className="truncate text-sm">{data?.data.name ?? "…"}</div>
          <div className="truncate text-xs text-muted">{data?.data.email}</div>
        </div>
        <button
          onClick={() => logout.mutate()}
          className="h-8 w-full rounded-md px-2.5 text-left text-sm text-muted transition hover:bg-panel-2 hover:text-fg"
        >
          Выйти
        </button>
      </div>
    </aside>
  );
}
