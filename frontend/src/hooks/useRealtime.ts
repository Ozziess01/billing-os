"use client";

import { useQueryClient } from "@tanstack/react-query";
import { useEffect } from "react";
import { useOrganization, useUser } from "@/hooks/useAuth";
import { echo } from "@/lib/echo";

interface BillingEvent {
  type: string;
  invoice_id?: string;
  payment_id?: string;
  subscription_id?: string;
}

// событие → какие кэши устарели; данных в событии нет, перезапрашиваем сами
const affected: Record<string, string[]> = {
  "invoice.finalized": ["invoices", "subscriptions", "ledger", "dashboard"],
  "invoice.paid": ["invoices", "payments", "subscriptions", "ledger", "dashboard"],
  "invoice.voided": ["invoices", "ledger"],
  "payment.succeeded": ["payments", "invoices", "ledger"],
  "payment.failed": ["payments", "invoices", "subscriptions"],
  "refund.succeeded": ["payments", "ledger"],
  "subscription.created": ["subscriptions", "customers", "invoices", "dashboard"],
  "subscription.renewed": ["subscriptions", "invoices", "usage", "dashboard"],
  "subscription.canceled": ["subscriptions", "dashboard"],
};

/** Подписка на realtime организации и личные уведомления; живёт, пока смонтирован каркас приложения. */
export function useRealtime() {
  const client = useQueryClient();
  const { current } = useOrganization();
  const { data: me } = useUser();
  const organizationId = current?.id;
  const userId = me?.data.id;

  useEffect(() => {
    if (!organizationId) return;
    const name = `organization.${organizationId}`;
    echo()
      .private(name)
      .listen(".billing.event", (event: BillingEvent) => {
        for (const key of affected[event.type] ?? ["invoices", "payments", "subscriptions"]) {
          client.invalidateQueries({ queryKey: [key] });
        }
      });
    return () => echo().leave(name);
  }, [organizationId, client]);

  useEffect(() => {
    if (!userId) return;
    const name = `user.${userId}`;
    echo()
      .private(name)
      .notification(() => client.invalidateQueries({ queryKey: ["notifications"] }));
    return () => echo().leave(name);
  }, [userId, client]);
}
