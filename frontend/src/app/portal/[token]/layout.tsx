"use client";

import { useParams } from "next/navigation";
import { PortalShell } from "@/components/portal/PortalShell";

export default function PortalLayout({ children }: { children: React.ReactNode }) {
  const { token } = useParams<{ token: string }>();

  return <PortalShell token={token}>{children}</PortalShell>;
}
