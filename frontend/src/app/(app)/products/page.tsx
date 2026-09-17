"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import { PriceForm } from "@/components/products/PriceForm";
import { ProductForm } from "@/components/products/ProductForm";
import { Badge, Button, Card, Dialog, Empty, ErrorNote, Money, PageTitle } from "@/components/ui";
import { formatMoney, intervalText } from "@/lib/money";
import { prices, products } from "@/services/products";
import type { Product } from "@/types";

export default function ProductsPage() {
  const client = useQueryClient();
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<Product | null>(null);
  const [pricing, setPricing] = useState<Product | null>(null);
  const [showInactive, setShowInactive] = useState(false);

  const list = useQuery({ queryKey: ["products", { showInactive }], queryFn: () => products.list(showInactive ? {} : { active: true, per_page: 100 }) });
  const invalidate = () => {
    client.invalidateQueries({ queryKey: ["products"] });
    client.invalidateQueries({ queryKey: ["prices"] });
  };

  const create = useMutation({ mutationFn: products.create, onSuccess: () => { invalidate(); setCreating(false); } });
  const update = useMutation({
    mutationFn: ({ id, input }: { id: string; input: Parameters<typeof products.update>[1] }) => products.update(id, input),
    onSuccess: () => { invalidate(); setEditing(null); },
  });
  const addPrice = useMutation({ mutationFn: prices.create, onSuccess: () => { invalidate(); setPricing(null); } });
  const togglePrice = useMutation({ mutationFn: ({ id, active }: { id: string; active: boolean }) => prices.update(id, { active }), onSuccess: invalidate });

  return (
    <>
      <PageTitle
        title="Продукты"
        subtitle="Что вы продаёте и по каким ценам."
        actions={
          <>
            <label className="flex items-center gap-2 text-xs text-muted">
              <input type="checkbox" checked={showInactive} onChange={(e) => setShowInactive(e.target.checked)} /> показывать неактивные
            </label>
            <Button onClick={() => setCreating(true)}>Новый продукт</Button>
          </>
        }
      />
      {(update.error || togglePrice.error) && <div className="mb-4"><ErrorNote error={update.error ?? togglePrice.error} /></div>}

      <div className="space-y-4">
        {list.data?.data.map((p) => (
          <Card
            key={p.id}
            title={p.name}
            actions={
              <div className="flex items-center gap-2">
                {!p.active && <Badge>неактивен</Badge>}
                <Button variant="ghost" size="sm" onClick={() => setEditing(p)}>Изменить</Button>
                <Button variant="ghost" size="sm" onClick={() => update.mutate({ id: p.id, input: { active: !p.active } })}>
                  {p.active ? "Деактивировать" : "Активировать"}
                </Button>
                <Button variant="secondary" size="sm" onClick={() => setPricing(p)}>Добавить цену</Button>
              </div>
            }
          >
            {p.description && <p className="border-b border-line px-5 py-3 text-sm text-muted">{p.description}</p>}
            {p.prices && p.prices.length > 0 ? (
              <ul className="divide-y divide-line/70">
                {p.prices.map((price) => (
                  <li key={price.id} className="flex items-center justify-between gap-4 px-5 py-2.5 text-sm">
                    <div className="flex items-center gap-3">
                      <Money formatted={formatMoney(price.unit_amount, price.currency)} className="w-28" />
                      <span className="text-muted">{intervalText(price)}</span>
                      {price.nickname && <span className="text-muted">· {price.nickname}</span>}
                      {!price.active && <Badge>неактивна</Badge>}
                    </div>
                    <div className="flex items-center gap-3">
                      <span className="font-mono text-[11px] text-muted">{price.id}</span>
                      <Button variant="ghost" size="sm" onClick={() => togglePrice.mutate({ id: price.id, active: !price.active })}>
                        {price.active ? "выключить" : "включить"}
                      </Button>
                    </div>
                  </li>
                ))}
              </ul>
            ) : (
              <Empty>Цен пока нет — без цены продукт нельзя подключить к подписке.</Empty>
            )}
          </Card>
        ))}
        {list.data && list.data.data.length === 0 && (
          <Card>
            <Empty>Продуктов пока нет.</Empty>
          </Card>
        )}
      </div>

      <Dialog open={creating} onClose={() => setCreating(false)} title="Новый продукт">
        <ProductForm onSubmit={(input) => create.mutate(input)} pending={create.isPending} error={create.error} submitLabel="Создать" />
      </Dialog>
      <Dialog open={!!editing} onClose={() => setEditing(null)} title="Изменить продукт">
        {editing && <ProductForm initial={editing} onSubmit={(input) => update.mutate({ id: editing.id, input })} pending={update.isPending} error={update.error} />}
      </Dialog>
      <Dialog open={!!pricing} onClose={() => setPricing(null)} title={`Новая цена · ${pricing?.name ?? ""}`}>
        {pricing && <PriceForm productId={pricing.id} onSubmit={(input) => addPrice.mutate(input)} pending={addPrice.isPending} error={addPrice.error} />}
      </Dialog>
    </>
  );
}
