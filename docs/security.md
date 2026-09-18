# Безопасность

Модель угроз и что ей противопоставлено. Каждый пункт привязан к тесту в `backend/tests`, который его держит: если контроль сломается, упадёт конкретный тест, а не «что-то в проде».

## Что защищаем

- **Деньги и финансовые записи**: инвойсы, платежи, возвраты, леджер. Ценность - корректность: ни одна сумма не должна измениться задним числом, ни одна операция не должна исполниться дважды.
- **Данные организаций**: клиенты, подписки, ключи. Ценность - изоляция: организация не видит и не трогает чужое.
- **Секреты**: пароли, токены API и портала, секреты вебхуков, `APP_KEY`.

## Кто действует

| Актор | Как попадает | Права |
|---|---|---|
| Пользователь | `Authorization: Bearer <sanctum>` + `X-Organization` | роль в организации: `owner > admin > developer > viewer` |
| Интеграция | `Authorization: Bearer bos_live_…` | всегда `developer` в организации ключа, кем бы ни был выпущен |
| Клиент организации | `Authorization: Bearer bps_…` из ссылки портала | только свои подписки, инвойсы, платежи |
| Платёжный провайдер | `POST /webhooks/{provider}` без токена | только подписанные события своей организации |
| Аноним | `/auth/*`, `/health*`, `/api/docs` | регистрация и вход под лимитами |

## Угрозы и контроли

### Доступ к чужим данным (IDOR, утечка между организациями)

| Угроза | Контроль | Тест |
|---|---|---|
| Подстановка чужого id в URL | Маршрутный биндинг ищет только внутри организации запроса (`BelongsToOrganization::resolveRouteBinding`): чужой id - 404, даже если пользователь состоит и в той организации | `CustomerTest` › never shows customers of another organization; `InvoiceTest` › validates draft items and keeps invoices inside the organization; `ProductTest` › does not attach prices to products of other organizations |
| Списки без фильтра | Все запросы через scope `forOrganization`, `X-Organization` резолвится до биндингов (приоритет middleware) | `SubscriptionTest` › lists and filters subscriptions within the organization only; `OrganizationTest` › resolves the organization from the header or the only membership |
| Действие не по роли | Политики на каждой модели: viewer читает, developer пишет, admin удаляет и возвращает, owner передаёт владение | `CustomerTest` › applies roles; `RefundTest` › lets only admins refund; `OrganizationTest` › transfers ownership only by the owner; `ProductionReadinessTest` › lets the maintenance command prune old activity but nobody else |
| Ключ интеграции с правами создателя | Роль ключа зафиксирована как `developer` в `ResolveOrganization`, а не берётся у пользователя | `PortalAndApiKeyTest` › authenticates integrations with hashed api keys acting as developer |
| Клиент видит другого клиента | Портал живёт в `CurrentCustomer`, все выборки фильтруются по `customer_id` | `PortalAndApiKeyTest` › opens a customer portal by a one-time link and shows only that customer |
| 404 раскрывает внутренности | JSON-404 всегда `{"message":"Не найдено."}`, без имени класса модели | `ProductionReadinessTest` › does not leak model class names in 404 responses |

### Двойное исполнение и подделка финансовых операций

| Угроза | Контроль | Тест |
|---|---|---|
| Повтор `POST /payments` (сеть, ретрай клиента) | `Idempotency-Key` в организации на 24 часа: тот же ответ байт в байт, другое тело - 409, в полёте - 409 | `IdempotencyTest` (все) |
| Два платежа по одному инвойсу параллельно | Частичный уникальный индекс `payments_one_in_flight` + lock инвойса | `PaymentTest` › allows only one payment in flight per invoice |
| Вебхук применён дважды | Уникальный `(provider, event_id)`, терминальный статус платежа не перезаписывается | `PaymentTest` › completes an async payment when the provider webhook arrives, and ignores duplicates |
| Поддельный или старый вебхук | HMAC-SHA256 от `timestamp.body` секретом организации, `hash_equals`, окно 5 минут | `WebhookTest` › accepts only correctly signed, fresh events for a known account |
| Правка суммы или номера инвойса после выставления | Триггер `invoices_guard`: после `draft` финансовые поля заморожены, удаление запрещено, переходы - только из таблицы | `InvoiceTest` › freezes a finalized invoice at the database level; › never lets a paid invoice go back to open |
| Правка или удаление проводок | Триггер `ledger_immutable` (append-only), отложенный constraint `ledger_entries_balanced`, уникальная ссылка на событие | `LedgerTest` (все) |
| Возврат больше списанного | Lock платежа, резерв под незавершённые возвраты, `CHECK payments_refunded_within_amount` | `RefundTest` › never refunds more than was captured |
| Двойное списание при повторе job | Продление сдвигает период `UPDATE … WHERE current_period_end = :expected`; счётчик попыток фиксируется до вызова провайдера | `RenewalTest` › ends a trial with an invoice and does not renew twice; `InvoiceTest` › issues an invoice for the current subscription period exactly once |
| Превышение лимита купона гонкой | `UPDATE … WHERE times_redeemed < max_redemptions` под lock | `UsageAndCouponTest` › applies percent and fixed coupons with limits and expiry |

### Аутентификация и секреты

| Угроза | Контроль | Тест |
|---|---|---|
| Перебор пароля | bcrypt (12 раундов), 5 неудач на почту+ip - пауза 5 минут, общий лимит `login` 20/мин с адреса, сообщение не говорит, что именно неверно | `AuthTest` › rejects wrong credentials without telling which part is wrong and rate limits attempts |
| Украден токен API-ключа из базы | Хранится только sha256, plain показывается один раз; отзыв и срок действия | `PortalAndApiKeyTest` › revokes api keys and rejects expired or garbage keys |
| Ссылка в портал утекла | Токен 48 случайных символов, в базе sha256, живёт 24 часа, лимит `portal` 120/мин | `PortalAndApiKeyTest` › rejects expired, unknown and missing portal tokens |
| Секрет вебхука в дампе базы | `organizations.webhook_secret` зашифрован `APP_KEY` (cast `encrypted`) и скрыт из ответов API | `WebhookTest` › signs deliveries so that the endpoint verifies them end to end |
| Метрики читает кто угодно | `/metrics` только с `METRICS_TOKEN`; без токена endpoint выключен (404) | `ProductionReadinessTest` › serves prometheus metrics only with the token |
| Токен в логах Sentry | `send_default_pii=false` на бэкенде и фронтенде, SDK включается только при DSN | - |

### Транспорт и браузер

| Угроза | Контроль | Тест |
|---|---|---|
| Downgrade на http | `Strict-Transport-Security` на всех https-ответах (не на http, чтобы не сломать локальный стенд); Caddy делает редирект и выпускает сертификат | `ProductionReadinessTest` › sets secure headers and hsts only over https |
| XSS / инъекция скриптов в API | `Content-Security-Policy: default-src 'none'` на всех ответах Laravel; для `/api/docs` разрешён только пинованный CDN Scalar, обращения на сторонние хосты режутся | `DocsTest` › serves the openapi spec and the docs page |
| Clickjacking, MIME sniffing | `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy` | там же |
| CSRF | Аутентификация только по `Authorization: Bearer`, cookie-сессий у API нет, поэтому запрос с чужого сайта без токена ничего не сделает. CORS - дефолт Laravel для `api/*` без credentials; в production фронтенд и API на одном origin | `AuthTest` › requires authentication for the api |
| DoS дорогими запросами | Именованные лимиты с отдельными ключами: `payments` 60/мин на пользователя, `usage` 600/мин, `register` 10/мин с адреса, `login` 20/мин, `webhooks` 120/мин на провайдера+адрес, `portal` 120/мин | `ProductionReadinessTest` › rate limits payment requests per user, not per ip; › keeps rate limits of different endpoints apart |

### Ввод и модели

| Угроза | Контроль | Тест |
|---|---|---|
| Массовое присваивание | Модели объявляют поля через `#[Fillable]`, контроллеры пишут только `validated()` из FormRequest | `CustomerTest` › validates customer payloads; `ProductTest` › validates currency and interval; `UsageAndCouponTest` › validates usage reports |
| Float в деньгах | `Money` принимает только int, валюта из списка, `CHECK` на суммах | `MoneyTest`; `ProductTest` › stores money as integer minor units only |
| Тихие ошибки в ORM | `Model::shouldBeStrict()` вне production: ленивая загрузка и неизвестные атрибуты - исключение | весь набор тестов работает в strict |
| Уязвимые зависимости | `composer audit` и `npm audit --audit-level=high` на каждом прогоне CI | `.github/workflows/ci.yml` |

### Наблюдаемость злоупотреблений

| Что | Контроль | Тест |
|---|---|---|
| Кто и что сделал | `activity_logs`: действие, актор (user / api_key / customer / system), ресурс, ip, метаданные. Триггер запрещает `UPDATE`/`DELETE`; чистка старше `BILLING_ACTIVITY_RETENTION_DAYS` возможна только командой `activity:prune` через `SET LOCAL billingos.audit_maintenance` | `ProductionReadinessTest` › keeps an append-only audit trail of who did what; › records api key and portal actors |
| Журнал читают все подряд | `GET /activity` - admin и выше | там же |

## Инфраструктура

- Production-контейнеры работают не от root (`www-data`, `node`); в образе бэкенда нет `.env`, composer и dev-зависимостей; спецификация смонтирована read-only.
- PostgreSQL и Redis в `docker-compose.prod.yml` не публикуют порты наружу; единственная точка входа - Caddy на 80/443.
- `.env`, `.env.prod` и `backend/.env*` игнорируются git и docker-контекстом (`.dockerignore`), в репозитории только `*.example`.
- `APP_DEBUG=false`, `expose_php=Off`, `server_tokens off` в nginx, заголовок `Server` убран Caddy.

## Известные ограничения

- Токены Sanctum не истекают по времени (`sanctum.expiration = null`): отзыв - через `POST /auth/logout` или удаление записи. Для публичной установки стоит задать срок.
- Нет второго фактора и allow-list адресов для API-ключей.
- `FakeProvider` - единственный провайдер; секрет вебхуков общий на организацию, а не на endpoint.
- `/health/ready` и `/health/deep` открыты: они сообщают состояние компонентов (без секретов), при желании закрываются на уровне Caddy.
