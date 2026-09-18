# Архитектура

BillingOS - монолит на Laravel с отдельным Next.js-фронтендом. Вся логика денег живёт в сервисах бэкенда и охраняется базой; фронтенд - тонкий клиент API.

## Компоненты

```mermaid
flowchart LR
    Browser["Браузер\n(Next.js, TanStack Query, Echo)"]
    Integr["Интеграции\n(bos_live_ ключ)"]
    Portal["Клиент\n(портал, bps_ токен)"]
    Provider["Платёжный провайдер\n(FakeProvider)"]

    subgraph edge["Вход"]
        Caddy["Caddy · TLS"] --> Nginx
    end

    subgraph app["Приложение"]
        Nginx -->|"/api /health /metrics"| API["Laravel · php-fpm"]
        Nginx -->|"/app"| Reverb["Reverb · WebSocket"]
        Nginx -->|"/"| Next["Next.js standalone"]
        API --> PG[("PostgreSQL\nтриггеры, CHECK, уникальные индексы")]
        API --> Redis[("Redis\nочереди, кеш, лимиты")]
        Worker["Worker\nqueue:work + schedule:work"] --> PG
        Worker --> Redis
        Worker -->|"события"| Reverb
    end

    Browser --> Caddy
    Integr --> Caddy
    Portal --> Caddy
    Provider -->|"вебхуки с подписью"| Caddy
    API -->|"createPayment / refund"| Provider
```

Один PHP-образ работает в трёх ролях (`CONTAINER_ROLE=app|worker|websocket`). Воркер обслуживает очереди `billing` и `default` и крутит планировщик; `/health/deep` следит, что он жив.

## Слои бэкенда

```text
app/Http        контроллеры (тонкие), FormRequest-валидация, ресурсы, middleware
app/Services    доменные операции: Subscription, Invoice, Payment, Refund, Ledger, Webhook, Collection, Usage, Coupon, Portal, ApiKey, Organization
app/Billing     Money, Currency, BillingInterval - value objects без float
app/Payments    контракт PaymentProvider, DTO, ProviderRegistry, FakeProvider
app/Enums       статусы как state machine: таблица переходов + атомарный UPDATE … WHERE status = :from
app/Tenancy     CurrentOrganization / CurrentCustomer - контекст запроса (scoped singleton)
app/Audit       Activity::record - журнал действий
app/Jobs        RenewSubscription, CollectInvoice, ProcessWebhookEvent, DeliverFakeWebhook
app/Listeners   реакции на доменные события: статус подписки, уведомления, broadcast
```

Контроллер проверяет политику, валидирует, зовёт сервис, отдаёт ресурс. Сервисы открывают транзакции сами и знают, где вызов провайдера должен быть *вне* транзакции. События (`InvoicePaid`, `PaymentFailed`, …) диспатчатся из сервисов; слушатели ставятся в очередь после commit.

## Запрос через систему

```mermaid
sequenceDiagram
    participant C as Клиент API
    participant M as Middleware
    participant S as Сервис
    participant DB as PostgreSQL
    participant P as Провайдер
    participant Q as Очередь

    C->>M: POST /api/v1/payments (Bearer, X-Organization, Idempotency-Key)
    M->>M: auth → ResolveOrganization → IdempotentRequest (ключ свободен? иначе replay / 409)
    M->>S: PaymentService::pay
    S->>DB: tx1: lock инвойса, проверка статуса и остатка, INSERT payment(pending)
    Note over DB: частичный уникальный индекс: один платёж в полёте на инвойс
    S->>P: createPayment (вне транзакции)
    P-->>S: succeeded | failed | processing | requires_action
    S->>DB: tx2: lock платежа, переход статуса, проводка cash/receivable, инвойс paid
    S-->>Q: InvoicePaid → уведомления, broadcast, статус подписки
    S-->>M: PaymentResource
    M->>DB: сохранить ответ под Idempotency-Key
    M-->>C: 201
```

Если провайдер ответил `processing`, итог придёт вебхуком: `POST /webhooks/fake` проверяет подпись, сохраняет событие (дубликат по `event_id` - 200 и выход), а `ProcessWebhookEvent` в очереди применяет результат через тот же `applyResult` - терминальный статус ставится ровно один раз, откуда бы ни пришёл.

## Изоляция организаций

- Организация запроса резолвится один раз в `ResolveOrganization`: из `X-Organization` (id или slug), из единственного членства, или из API-ключа. Middleware стоит в списке приоритетов раньше `SubstituteBindings`, поэтому биндинги уже знают контекст.
- `BelongsToOrganization` даёт scope `forOrganization` и переопределяет `resolveRouteBinding`: модель ищется только в текущей организации. Чужой id даёт 404, а не 403 - существование ресурса не раскрывается.
- Политики наследуют `TenantPolicy`: `viewAny/view` - viewer, `create/update` - developer, `delete` - admin; финансовые действия уточняются в политиках моделей (возврат - admin, финализация - developer).
- Роль API-ключа всегда `developer`, портал вообще не имеет пользователя - только `CurrentCustomer`.

## Деньги и состояния

- `Money(int $amount, Currency $currency)`: минорные единицы, экспонента из `config/billing.php`, арифметика между валютами - исключение `CurrencyMismatch` (наружу 409). Проценты округляются half-up в минорных единицах, metered-цены считаются через bcmath и округляются один раз на строку инвойса.
- Статусы - enum'ы с таблицей переходов (`InvoiceStatus`, `PaymentStatus`, `SubscriptionStatus`, `RefundStatus`). Переход - `UPDATE … WHERE id = ? AND status = :from`; если строка не обновилась, переход уже сделал кто-то другой. Недопустимый переход - `InvalidTransition` (409).
- База держит инварианты сама: `invoices_guard` замораживает финализированный инвойс, `ledger_immutable` запрещает правку проводок, отложенный constraint `ledger_entries_balanced` не даст закоммитить несбалансированную проводку, `CHECK` на суммах и возвратах, частичные уникальные индексы на платёж в полёте и на `idempotency_key` usage-событий, `activity_logs_immutable` для журнала.

## Схема данных

```mermaid
erDiagram
    users ||--o{ organization_members : "состоит"
    organizations ||--o{ organization_members : "имеет"
    organizations ||--o{ customers : ""
    organizations ||--o{ products : ""
    organizations ||--o{ api_keys : ""
    organizations ||--o{ coupons : ""
    organizations ||--o{ ledger_accounts : "по валютам"
    organizations ||--o{ webhook_events : ""
    organizations ||--o{ idempotency_keys : ""
    organizations ||--o{ activity_logs : ""
    products ||--o{ prices : ""
    customers ||--o{ subscriptions : ""
    customers ||--o{ portal_sessions : ""
    subscriptions ||--o{ subscription_items : ""
    prices ||--o{ subscription_items : ""
    subscription_items ||--o{ usage_events : ""
    subscriptions ||--o{ invoices : "за период"
    customers ||--o{ invoices : ""
    invoices ||--o{ invoice_items : ""
    invoices ||--o{ payments : "попытки"
    payments ||--o{ refunds : ""
    coupons ||--o{ coupon_redemptions : ""
    subscriptions ||--o| coupon_redemptions : ""
    ledger_transactions ||--|{ ledger_entries : "дебет = кредит"
    ledger_accounts ||--o{ ledger_entries : ""
    users ||--o{ notification_preferences : ""
    users ||--o{ notifications : ""

    invoices {
        string number "INV-000001, выдаётся при финализации"
        enum status "draft open paid void uncollectible"
        bigint subtotal
        bigint discount
        bigint total "CHECK total = subtotal - discount"
        bigint amount_paid
        bigint amount_due
    }
    payments {
        enum status "pending processing succeeded failed canceled"
        bigint amount
        bigint amount_refunded "CHECK <= amount"
        string provider_payment_id
    }
    ledger_transactions {
        enum type "invoice payment refund adjustment"
        string reference_type
        string reference_id "UNIQUE (type, reference)"
    }
    ledger_entries {
        bigint debit
        bigint credit
    }
```

Идентификаторы биллинговых сущностей - ULID (сортируются по времени, не перебираются), у пользователей и организаций - целые.

## Фоновые процессы

| Что | Когда | Идемпотентность |
|---|---|---|
| `subscriptions:renew` → `RenewSubscription` | каждую минуту | период сдвигается `WHERE current_period_end = :expected`; инвойс за период создаётся один раз |
| `invoices:collect` → `CollectInvoice` | каждую минуту, по `next_payment_attempt_at` | счётчик попыток и дата следующей фиксируются до вызова провайдера |
| `ProcessWebhookEvent` | по приёму вебхука, `ShouldBeUnique` | событие переводится `received → processing` атомарно; терминальный платёж не трогается |
| `DeliverFakeWebhook` | отложенно после `tok_async` / `confirm` | провайдер ретраит доставку при 5xx |
| `idempotency:prune`, `activity:prune` | раз в час / раз в сутки | удаление по сроку |

Все команды планировщика - `onOneServer()`, поэтому воркеров может быть несколько.

## Realtime и уведомления

Доменное событие → два слушателя после commit: `NotifyAboutBillingEvent` рассылает участникам от developer и выше (in-app с `dedupe_key`, почта, broadcast в `user.{id}` - по настройкам каждого), `BroadcastBillingEvent` кладёт лёгкий сигнал в `private-organization.{id}`. Фронтенд по сигналу инвалидирует запросы TanStack Query - данные не гоняются через сокет, только факт изменения. Авторизация каналов - `POST /api/broadcasting/auth` с тем же Bearer-токеном.

## Фронтенд

Next.js App Router, всё клиентское: `src/services/*` - тонкие обёртки над `fetch` с токеном из `localStorage` и `X-Organization`, `src/hooks/useRealtime` - подписка на Echo, `src/lib/money.ts` - форматирование и разбор сумм без float (покрыто Vitest). Портал клиента живёт в `src/app/portal/[token]` и ходит только в `/api/v1/portal/*`.

## Границы

Не входит в проект и не планируется: налоги и НДС, мультирегион, реальные провайдеры (интерфейс есть, реализация одна - fake), бухгалтерская отчётность за пределами леджера, пользовательские роли тоньше четырёх встроенных.
