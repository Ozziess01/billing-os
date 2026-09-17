"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { useState } from "react";
import { Badge, Button, Card, Empty, Money, PageTitle, Pagination, Select, Table, Td, Th } from "@/components/ui";
import { formatDate } from "@/lib/format";
import { formatMoney, intervalText } from "@/lib/money";
import { prices, products } from "@/services/products";

export default function PricesPage() {
  const client = useQueryClient();
  const [page, setPage] = useState(1);
  const [productId, setProductId] = useState("");
  const [active, setActive] = useState<"" | "1" | "0">("");

  const list = useQuery({
    queryKey: ["prices", { page, productId, active }],
    queryFn: () => prices.list({ page, product_id: productId || undefined, active: active === "" ? undefined : active === "1" }),
  });
  const allProducts = useQuery({ queryKey: ["products", "all"], queryFn: () => products.list({ per_page: 100 }) });
  const toggle = useMutation({
    mutationFn: ({ id, value }: { id: string; value: boolean }) => prices.update(id, { active: value }),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: ["prices"] });
      client.invalidateQueries({ queryKey: ["products"] });
    },
  });

  return (
    <>
      <PageTitle title="Цены" subtitle="Все цены организации. Суммы — целые минорные единицы, здесь показаны в валюте." actions={<Link href="/products"><Button variant="secondary">К продуктам</Button></Link>} />

      <Card>
        <div className="flex flex-wrap gap-3 border-b border-line px-5 py-3">
          <Select value={productId} onChange={(e) => { setProductId(e.target.value); setPage(1); }} className="w-56">
            <option value="">Все продукты</option>
            {allProducts.data?.data.map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </Select>
          <Select value={active} onChange={(e) => { setActive(e.target.value as "" | "1" | "0"); setPage(1); }} className="w-40">
            <option value="">Все</option>
            <option value="1">Активные</option>
            <option value="0">Неактивные</option>
          </Select>
        </div>
        <Table>
          <thead>
            <tr>
              <Th>Продукт</Th>
              <Th>Название</Th>
              <Th className="text-right">Сумма</Th>
              <Th>Интервал</Th>
              <Th>Статус</Th>
              <Th>Создана</Th>
              <Th />
            </tr>
          </thead>
          <tbody>
            {list.data?.data.map((price) => (
              <tr key={price.id} className="hover:bg-panel-2/60">
                <Td className="font-medium">{price.product?.name ?? price.product_id}</Td>
                <Td className="text-muted">{price.nickname ?? "—"}</Td>
                <Td className="text-right"><Money formatted={formatMoney(price.unit_amount, price.currency)} /></Td>
                <Td className="text-muted">{intervalText(price)}</Td>
                <Td>{price.active ? <Badge tone="ok">активна</Badge> : <Badge>неактивна</Badge>}</Td>
                <Td className="text-muted">{formatDate(price.created_at)}</Td>
                <Td className="text-right">
                  <Button variant="ghost" size="sm" onClick={() => toggle.mutate({ id: price.id, value: !price.active })}>
                    {price.active ? "выключить" : "включить"}
                  </Button>
                </Td>
              </tr>
            ))}
            {list.data && list.data.data.length === 0 && (
              <tr>
                <td colSpan={7}>
                  <Empty>Цен нет. Добавьте их на странице продуктов.</Empty>
                </td>
              </tr>
            )}
          </tbody>
        </Table>
        {list.data && <Pagination page={list.data.meta.current_page} lastPage={list.data.meta.last_page} onChange={setPage} />}
      </Card>
    </>
  );
}
