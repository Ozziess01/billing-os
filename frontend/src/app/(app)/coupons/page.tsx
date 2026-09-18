"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { Badge, Button, Card, Dialog, Empty, ErrorNote, Input, PageTitle, Pagination, Select, Table, Td, Th } from "@/components/ui";
import { useOrganization } from "@/hooks/useAuth";
import { ApiError } from "@/lib/api";
import { formatDate } from "@/lib/format";
import { formatMoney, parseAmount } from "@/lib/money";
import { coupons, type CouponInput } from "@/services/coupons";
import type { Coupon } from "@/types";

function describe(c: Coupon): string {
  const value = c.type === "percent" ? `${c.percent_off}%` : c.amount_off ? formatMoney(c.amount_off.amount, c.amount_off.currency) : "";
  return `${value} · ${c.duration === "once" ? "на первый инвойс" : "на каждый инвойс"}`;
}

export default function CouponsPage() {
  const client = useQueryClient();
  const { current } = useOrganization();
  const canManage = current?.role === "owner" || current?.role === "admin";
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);

  const list = useQuery({ queryKey: ["coupons", { page }], queryFn: () => coupons.list({ page }) });
  const invalidate = () => client.invalidateQueries({ queryKey: ["coupons"] });
  const toggle = useMutation({ mutationFn: ({ id, active }: { id: string; active: boolean }) => coupons.update(id, { active }), onSuccess: invalidate });

  return (
    <>
      <PageTitle
        title="Купоны"
        subtitle="Скидка в процентах или фиксированной суммой; лимит использований считается атомарно."
        actions={canManage && <Button onClick={() => setCreating(true)}>Новый купон</Button>}
      />
      {toggle.error && <div className="mb-4"><ErrorNote error={toggle.error} /></div>}

      <Card>
        <Table>
          <thead>
            <tr>
              <Th>Код</Th>
              <Th>Скидка</Th>
              <Th>Использований</Th>
              <Th>Действует до</Th>
              <Th>Статус</Th>
              <Th />
            </tr>
          </thead>
          <tbody>
            {list.data?.data.map((c) => (
              <tr key={c.id}>
                <Td>
                  <div className="font-mono font-medium">{c.code}</div>
                  <div className="text-xs text-muted">{c.name}</div>
                </Td>
                <Td>{describe(c)}</Td>
                <Td className="font-mono">
                  {c.times_redeemed}
                  {c.max_redemptions ? ` / ${c.max_redemptions}` : ""}
                </Td>
                <Td className="text-muted">{c.redeem_by ? formatDate(c.redeem_by) : "бессрочно"}</Td>
                <Td>{c.valid ? <Badge tone="ok">действует</Badge> : <Badge>{c.active ? "исчерпан или истёк" : "выключен"}</Badge>}</Td>
                <Td className="text-right">
                  {canManage && (
                    <Button variant="ghost" size="sm" onClick={() => toggle.mutate({ id: c.id, active: !c.active })}>
                      {c.active ? "выключить" : "включить"}
                    </Button>
                  )}
                </Td>
              </tr>
            ))}
            {list.data && list.data.data.length === 0 && (
              <tr>
                <td colSpan={6}>
                  <Empty>Купонов нет.</Empty>
                </td>
              </tr>
            )}
          </tbody>
        </Table>
        {list.data && <Pagination page={list.data.meta.current_page} lastPage={list.data.meta.last_page} onChange={setPage} />}
      </Card>

      <Dialog open={creating} onClose={() => setCreating(false)} title="Новый купон">
        <CouponForm onDone={() => { setCreating(false); invalidate(); }} />
      </Dialog>
    </>
  );
}

function CouponForm({ onDone }: { onDone: () => void }) {
  const { current } = useOrganization();
  const [form, setForm] = useState({ code: "", name: "", type: "percent" as "percent" | "fixed", percent: "20", amount: "", currency: current?.default_currency ?? "EUR", duration: "forever" as "once" | "forever", redeemBy: "", max: "" });
  const [localError, setLocalError] = useState<string | null>(null);
  const create = useMutation({ mutationFn: (input: CouponInput) => coupons.create(input), onSuccess: onDone });
  const apiError = create.error instanceof ApiError ? create.error : null;

  return (
    <form
      className="space-y-3"
      onSubmit={(e) => {
        e.preventDefault();
        setLocalError(null);
        const input: CouponInput = { code: form.code, name: form.name, type: form.type, duration: form.duration, redeem_by: form.redeemBy || null, max_redemptions: form.max ? Number(form.max) : null };
        if (form.type === "percent") input.percent_off = Number(form.percent);
        else {
          const minor = parseAmount(form.amount, form.currency);
          if (minor === null || minor <= 0) {
            setLocalError("Введите сумму скидки");
            return;
          }
          input.amount_off = minor;
          input.currency = form.currency;
        }
        create.mutate(input);
      }}
    >
      <div className="grid grid-cols-2 gap-3">
        <Input label="Код" required autoFocus placeholder="SAVE20" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} error={apiError?.field("code")} />
        <Input label="Название" required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={apiError?.field("name")} />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <Select label="Тип" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value as "percent" | "fixed" })}>
          <option value="percent">Процент</option>
          <option value="fixed">Фиксированная сумма</option>
        </Select>
        {form.type === "percent" ? (
          <Input label="Процент" type="number" min={1} max={100} value={form.percent} onChange={(e) => setForm({ ...form, percent: e.target.value })} error={apiError?.field("percent_off")} />
        ) : (
          <div className="grid grid-cols-[1fr_88px] gap-2">
            <Input label="Сумма" inputMode="decimal" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} error={localError ?? apiError?.field("amount_off")} />
            <Select label="Валюта" value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })}>
              {["EUR", "USD", "GBP", "RUB", "JPY"].map((c) => (
                <option key={c}>{c}</option>
              ))}
            </Select>
          </div>
        )}
      </div>
      <div className="grid grid-cols-3 gap-3">
        <Select label="Длительность" value={form.duration} onChange={(e) => setForm({ ...form, duration: e.target.value as "once" | "forever" })}>
          <option value="forever">Каждый инвойс</option>
          <option value="once">Только первый</option>
        </Select>
        <Input label="Действует до" type="date" value={form.redeemBy} onChange={(e) => setForm({ ...form, redeemBy: e.target.value })} error={apiError?.field("redeem_by")} />
        <Input label="Лимит использований" type="number" min={1} value={form.max} onChange={(e) => setForm({ ...form, max: e.target.value })} error={apiError?.field("max_redemptions")} />
      </div>
      {apiError && !Object.keys(apiError.errors).length && <ErrorNote error={apiError} />}
      <div className="flex justify-end">
        <Button type="submit" disabled={create.isPending}>
          Создать
        </Button>
      </div>
    </form>
  );
}
