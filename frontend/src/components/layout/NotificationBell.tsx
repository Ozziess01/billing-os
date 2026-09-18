"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { notifications } from "@/services/notifications";

export function NotificationBell() {
  const pathname = usePathname();
  const { data } = useQuery({ queryKey: ["notifications", "bell"], queryFn: () => notifications.list({ page: 1 }), refetchInterval: 60_000 });
  const unread = data?.meta.unread ?? 0;
  const active = pathname.startsWith("/notifications");

  return (
    <Link
      href="/notifications"
      className={`flex h-8 items-center justify-between rounded-md px-2.5 text-sm transition ${active ? "bg-accent/10 font-medium text-accent" : "text-muted hover:bg-panel-2 hover:text-fg"}`}
    >
      <span>Уведомления</span>
      {unread > 0 && <span className="rounded-full bg-accent px-1.5 text-[11px] font-semibold text-white">{unread}</span>}
    </Link>
  );
}
