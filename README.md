# BillingOS

Self-hosted биллинг и подписки для SaaS: клиенты, продукты и цены, подписки с триалами, инвойсы, платежи, возвраты, usage-based тарифы, купоны и проверяемый финансовый леджер. Один `docker compose up` - и всё работает у вас.

Проект строится вокруг одного правила:

> **Любая операция с деньгами должна быть безопасна при повторе.** Запрос, job или вебхук могут прийти дважды - финансовый эффект от этого не удваивается.

## Статус

| Фаза | Что | Состояние |
|---|---|---|
| 1. Core Billing | организации и роли, клиенты, продукты, цены, подписки, дашборд | готово |
| 2. Billing Engine | инвойсы, платежи, провайдер, вебхуки, идемпотентность, леджер, возвраты | в работе |
| 3. SaaS Features | триалы и продления, usage, купоны, ретраи, уведомления, портал, API-ключи | - |
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
```

Аутентификация - `Authorization: Bearer <token>` (Sanctum), контекст - `X-Organization`. Ошибки валидации - 422, нарушение доменных правил (недопустимый переход состояния, смешение валют) - 409.

## Проверки

```bash
docker compose exec app php artisan test                       # Pest
docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec app vendor/bin/pint --test
cd frontend && npm run lint && npm run typecheck && npm run test:unit
```

Что покрыто тестами в Phase 1: изоляция организаций, RBAC по всем ресурсам, CRUD клиентов и продуктов, валидация цен (только целые неотрицательные суммы, валюта из списка, интервалы), жизненный цикл подписки (триал, отмена в конце периода, немедленная отмена, возобновление, невозможность оживить отменённую), арифметика денег.

## Структура

```text
backend/    Laravel: app/Billing (Money, Currency), app/Tenancy, app/Services, app/Policies, app/Http
frontend/   Next.js: src/app (страницы), src/components, src/services (API), src/lib (деньги, форматирование)
docker/     php (образ + entrypoint), nginx, postgres
docs/       архитектура, безопасность, деплой, бенчмарки, OpenAPI - появятся по мере фаз
```
