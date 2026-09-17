# BillingOS

Self-hosted биллинг и подписки для SaaS: клиенты, продукты и цены, подписки с триалами, инвойсы, платежи, возвраты, usage-based тарифы, купоны и проверяемый финансовый леджер. Один `docker compose up` - и всё работает у вас.

Проект строится вокруг одного правила:

> **Любая операция с деньгами должна быть безопасна при повторе.** Запрос, job или вебхук могут прийти дважды - финансовый эффект от этого не удваивается.

## Статус

| Фаза | Что | Состояние |
|---|---|---|
| 1. Core Billing | организации и роли, клиенты, продукты, цены, подписки, дашборд | готово |
| 2. Billing Engine | инвойсы, платежи, провайдер, вебхуки, идемпотентность, леджер, возвраты | готово |
| 3. SaaS Features | триалы и продления, usage, купоны, ретраи, уведомления, портал, API-ключи | в работе |
| 4. Production | безопасность, наблюдаемость, CI, E2E, prod-стек, документация | - |

## Стек

**Backend** - PHP 8.4, Laravel 13, PostgreSQL 16, Redis 7, Sanctum, очереди, Reverb. Pest, PHPStan (larastan, уровень 5), Pint.
**Frontend** - TypeScript, Next.js 16 (App Router), Tailwind 4, TanStack Query. Vitest, Playwright.
**Инфраструктура** - Docker Compose, nginx, Caddy для TLS в production, GitHub Actions.

## Запуск

```bash
cp .env.example .env            # порты на хосте; по умолчанию 8090 / 3001 / 5434 / 6382
docker compose up -d --build
```

- приложение: http://localhost:8090
- API: http://localhost:8090/api/v1
- Mailpit: http://localhost:8027

`app` при первом старте ставит зависимости, генерирует `APP_KEY` и накатывает миграции; `worker` ждёт, пока `app` станет healthy. Внутри контейнеров переопределяются только адреса сервисов, остальное Laravel читает из `backend/.env`.

## Как устроено

### Деньги

Никаких float. Сумма - целое число в минорных единицах валюты с явным кодом ISO 4217: `19.99 EUR` = `Money(1999, 'EUR')`, `1000 JPY` = `Money(1000, 'JPY')`. Экспонента валюты - из `config/billing.php`. Арифметика между валютами запрещена на уровне value object, процентные скидки округляются half-up в минорных единицах. В базе - `bigint` с `CHECK (unit_amount >= 0)`.

### Организации и изоляция

Пользователь состоит в организациях с ролью `owner > admin > developer > viewer`. Организация запроса задаётся заголовком `X-Organization` (id или slug); если она у пользователя одна, заголовок не нужен.

Все биллинговые сущности принадлежат организации и ищутся только внутри неё: маршрутный биндинг (`BelongsToOrganization::resolveRouteBinding`) не найдёт чужой `customer` даже если пользователь состоит и в той организации - будет 404, а не 403. Политики проверяют роль в организации запроса, а не «где-то».

### Подписки

`subscriptions` + `subscription_items` (цена × количество). Все позиции одной подписки - в одной валюте и с одним интервалом; период считается от старта по интервалу цены без переполнения месяца (31 января + 1 месяц = 28/29 февраля). Статусы `trialing / active / past_due / canceled / incomplete` живут в state machine с явной таблицей переходов; смена статуса - один атомарный `UPDATE … WHERE status = :from`. `canceled` - терминальное состояние: продлить отменённую подписку нельзя ни вручную, ни джобой.

Цены неизменяемы: у созданной цены нельзя поменять сумму, валюту и интервал, потому что под неё уже оформлены подписки. Нужна другая сумма - новая цена, старая деактивируется.

### Инвойсы

`draft → open → paid`, из `open` также `void` и `uncollectible`. Черновик собирается из позиций (цена из каталога или произвольная строка), финализация выдаёт сквозной номер `INV-000001` атомарным `UPDATE … RETURNING` и замораживает документ. Заморозку держит триггер PostgreSQL: после `draft` нельзя изменить суммы, валюту, номер и позиции, нельзя удалить инвойс, а из `paid` нельзя вернуться в `open`. Нулевой инвойс становится `paid` сразу при финализации - платить нечего.

### Платежи

```text
POST /payments {invoice_id, payment_method}      Idempotency-Key: …
   │
   ├─ транзакция 1: lock инвойса, проверка статуса и остатка, попытка pending
   │                (частичный уникальный индекс: один платёж «в полёте» на инвойс)
   ├─ вызов провайдера ВНЕ транзакции
   └─ транзакция 2: lock платежа, атомарный переход статуса, проводка, инвойс paid
```

Результат провайдера может прийти синхронно, вебхуком, или и так и так - `applyResult` применяет терминальный статус ровно один раз. Провайдер спрятан за интерфейсом `PaymentProvider` (`createPayment / refund / retrievePayment / verifyWebhook`); в комплекте `FakeProvider`, у которого исход задаёт платёжный метод: `tok_ok`, `tok_fail`, `tok_insufficient`, `tok_async` (успех придёт вебхуком через пару секунд - воркер честно стучится по HTTP в наш же эндпоинт), `tok_action` (аналог 3-D Secure: подтверждение через `/providers/fake/payments/{id}/confirm`, затем вебхук), `tok_error` (провайдер упал).

### Вебхуки

`POST /webhooks/{provider}` - без токена, доверие только по подписи: HMAC-SHA256 от `timestamp.body` с секретом организации и допуском по времени 5 минут (replay старого события не проходит). Событие сохраняется до обработки, дубликат по `(provider, event_id)` отбрасывается уникальным индексом, обработка идёт в очереди `billing` и безопасна к повтору: платёж, который уже терминален, не трогается, а вторая проводка не пройдёт по уникальной ссылке.

### Идемпотентность

Финансовые `POST` (`/payments`, `/payments/{id}/refund`, `/invoices`, `/invoices/{id}/finalize`, `/subscriptions/{id}/invoice`) принимают `Idempotency-Key`. Ключ живёт в организации 24 часа: повтор того же запроса отдаёт сохранённый ответ байт в байт с заголовком `Idempotent-Replayed: true`; тот же ключ с другим телом - 409; повтор, пока первый запрос ещё выполняется, - 409. Ответы 5xx не запоминаются, чтобы повтор прошёл заново.

### Леджер

Упрощённая двойная запись на четырёх счетах организации (на каждую валюту свои): `cash`, `receivable`, `revenue`, `adjustments`.

| Событие | Дебет | Кредит |
|---|---|---|
| финализация инвойса | receivable | revenue |
| успешный платёж | cash | receivable |
| возврат | revenue | cash |
| void / uncollectible | adjustments | receivable |

Проводки только добавляются: триггер запрещает `UPDATE` и `DELETE`. Баланс каждой проводки (`sum(debit) = sum(credit)`) проверяет отложенный constraint-триггер - несбалансированная проводка не зафиксируется. Одно событие проводится один раз: уникальный индекс на `(type, reference)`.

### Возвраты

Возврат - компенсирующая операция: оригинальный платёж не меняется, растёт только `amount_refunded`, в леджер уходит обратная проводка. `total_refunded ≤ total_captured` держат lock платежа, резерв под незавершённые возвраты и `CHECK` в базе. Повторный запрос с тем же `Idempotency-Key` возвращает тот же refund.

## API

```text
POST   /api/v1/auth/register            POST /api/v1/auth/login   POST /api/v1/auth/logout   GET /api/v1/auth/me
GET    /api/v1/organizations            POST /api/v1/organizations
GET    /api/v1/organizations/{id}/members   POST … /members   PATCH … /members/{member}   DELETE … /members/{member}
POST   /api/v1/organizations/{id}/transfer

GET    /api/v1/dashboard
GET    /api/v1/customers                POST /api/v1/customers      GET|PATCH|DELETE /api/v1/customers/{id}
GET    /api/v1/products                 POST /api/v1/products       GET|PATCH|DELETE /api/v1/products/{id}
GET    /api/v1/prices                   POST /api/v1/prices         GET|PATCH|DELETE /api/v1/prices/{id}
GET    /api/v1/subscriptions            POST /api/v1/subscriptions  GET /api/v1/subscriptions/{id}
POST   /api/v1/subscriptions/{id}/cancel    { "at_period_end": true|false }
POST   /api/v1/subscriptions/{id}/resume
POST   /api/v1/subscriptions/{id}/invoice   инвойс за текущий период (идемпотентно)

GET    /api/v1/invoices                 POST /api/v1/invoices       GET|DELETE /api/v1/invoices/{id}
POST   /api/v1/invoices/{id}/items      DELETE /api/v1/invoices/{id}/items/{item}
POST   /api/v1/invoices/{id}/finalize   POST /api/v1/invoices/{id}/void   POST /api/v1/invoices/{id}/uncollectible
GET    /api/v1/payments                 POST /api/v1/payments       GET /api/v1/payments/{id}
POST   /api/v1/payments/{id}/refund     POST /api/v1/payments/{id}/cancel   GET /api/v1/refunds
GET    /api/v1/ledger/accounts          GET /api/v1/ledger/transactions
POST   /api/v1/webhooks/{provider}      GET /api/v1/webhooks/events
POST   /api/v1/providers/fake/payments/{providerPaymentId}/confirm
```

Аутентификация - `Authorization: Bearer <token>` (Sanctum), контекст - `X-Organization`, идемпотентность - `Idempotency-Key`. Ошибки валидации - 422, нарушение доменных правил (недопустимый переход состояния, смешение валют, второй платёж в полёте) - 409.

## Проверки

```bash
docker compose exec app php artisan test                       # Pest
docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec app vendor/bin/pint --test
cd frontend && npm run lint && npm run typecheck && npm run test:unit
```

Что покрыто тестами: изоляция организаций и RBAC по всем ресурсам; CRUD клиентов и продуктов; валидация цен (целые неотрицательные суммы, валюта из списка, интервалы); жизненный цикл подписки; арифметика денег; финализация инвойса с номером и проводкой, заморозка на уровне базы, `paid` не возвращается в `open`; платёж успех/отказ/повторная попытка, один платёж в полёте, async-платёж через вебхук, дубликаты вебхуков, replay старой подписи, `requires_action` через confirm; идемпотентность (тот же ответ, другой payload - 409, в полёте - 409, ключи per-organization, истечение); возвраты частичные и полные, запрет превышения (сервис и `CHECK`), компенсирующие проводки; леджер append-only, несбалансированная проводка отклоняется базой, событие проводится один раз.

## Структура

```text
backend/    Laravel: app/Billing (Money, Currency), app/Payments (провайдер, FakeProvider), app/Tenancy, app/Services, app/Policies, app/Http
frontend/   Next.js: src/app (страницы), src/components, src/services (API), src/lib (деньги, форматирование)
docker/     php (образ + entrypoint), nginx, postgres
docs/       архитектура, безопасность, деплой, бенчмарки, OpenAPI - появятся по мере фаз
```
