# Деплой

Production-стек - один `docker-compose.prod.yml` на одной машине. Снаружи только Caddy с автоматическим TLS, внутри nginx раскидывает запросы по контейнерам.

```text
интернет ──443──► caddy (TLS, Let's Encrypt)
                    │
                    ▼
                  nginx
        ┌───────────┼──────────────┐
   /api /health   /app (ws)        /  (всё остальное)
   /metrics         │               │
        ▼           ▼               ▼
   app (php-fpm)  websocket      frontend (next standalone)
        │         (reverb)
        ├── postgres
        ├── redis
        └── worker (очереди billing/default + планировщик)
```

## Что в образах

- `billingos/app` (`docker/php/Dockerfile.prod`): PHP 8.4 fpm-alpine, расширения pdo_pgsql/intl/bcmath/redis/opcache, `composer install --no-dev`, opcache без проверки mtime + JIT, php-fpm static 20 воркеров, процесс от `www-data`, `.env` внутри нет. Один образ для трёх ролей: `app`, `worker`, `websocket` (переключает `CONTAINER_ROLE`, см. `docker/php/entrypoint.sh`).
- `billingos/frontend` (`frontend/Dockerfile`): `next build` со `standalone`, в рантайме только `server.js` со своими модулями, от `node`. `NEXT_PUBLIC_*` вшиваются на сборке, поэтому образ собирается под домен.

При старте `app` в production ждёт базу, накатывает миграции (`migrate --force`) и кеширует конфиг, маршруты, события и шаблоны (`artisan optimize`). `worker` и `websocket` стартуют после того, как `app` станет healthy.

## Первый запуск

Нужны Docker Engine 24+ с compose v2, домен с A-записью на машину и открытые 80/443.

```bash
git clone https://github.com/Ozziess01/billing-os.git && cd billing-os
cp .env.prod.example .env.prod
```

Заполнить `.env.prod`:

| Переменная | Что |
|---|---|
| `DOMAIN`, `ACME_EMAIL` | домен для Caddy и почта для Let's Encrypt |
| `APP_KEY` | `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"` |
| `APP_URL`, `FRONTEND_URL`, `NEXT_PUBLIC_API_URL`, `NEXT_PUBLIC_WS_HOST` | всё на `https://<домен>` |
| `DB_PASSWORD` | пароль PostgreSQL, compose подставит его и в базу, и в приложение |
| `REVERB_APP_KEY`, `REVERB_APP_SECRET` | `openssl rand -hex 16` / `32`; ключ попадает и во фронтенд |
| `MAIL_*` | SMTP для уведомлений; `MAIL_MAILER=log`, если почты нет |
| `METRICS_TOKEN` | токен для `/metrics`; пусто - endpoint выключен |
| `SENTRY_LARAVEL_DSN`, `NEXT_PUBLIC_SENTRY_DSN` | пусто - Sentry выключен |

Дальше:

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml -p billingos-prod up -d --build --wait
curl https://<домен>/health/deep
```

`--wait` вернёт управление, когда все healthcheck'и зелёные. Файл `.env.prod` читают и compose (интерполяция `${…}` в yml), и php-контейнеры (`env_file`), поэтому один файл описывает весь стек.

## Обновление

```bash
git pull
docker compose --env-file .env.prod -f docker-compose.prod.yml -p billingos-prod up -d --build --wait
```

Пересобираются только изменившиеся слои; `app` при старте накатывает новые миграции. Миграции пишутся так, чтобы старый код переживал новую схему (добавление колонок и таблиц), поэтому порядок перезапуска контейнеров не важен. Откат - `git checkout <tag>` и та же команда; миграции назад не катятся, схема совместима вперёд.

Теги образов задаёт `IMAGE_TAG` (по умолчанию `latest`) - удобно держать предыдущий образ для быстрого отката без пересборки.

## Проверка стека локально

Тот же compose поднимается на ноутбуке без домена:

```bash
sh scripts/local-prod-env.sh          # .env.prod с localhost, портами 8880/8443 и случайными ключами
docker compose --env-file .env.prod -f docker-compose.prod.yml -p billingos-prod up -d --build --wait
curl -k https://localhost:8443/health/deep
```

Caddy для `localhost` выпускает сертификат своим внутренним CA - браузер предупредит, `curl -k` пройдёт. Именно так E2E в CI (`.github/workflows/e2e.yml`) проверяет production-путь: Playwright ходит через Caddy и TLS.

## Наблюдение

| Что | Где |
|---|---|
| Жив ли процесс | `GET /health/live` - для restart-политик, без зависимостей |
| Можно ли слать трафик | `GET /health/ready` - база и Redis |
| Всё ли работает | `GET /health/deep` - очереди (размер и возраст самой старой job), heartbeat планировщика, сокет Reverb, зависшие платежи; 503 при проблеме |
| Метрики | `GET /metrics` с `Authorization: Bearer <METRICS_TOKEN>`: rps и латентность по классам статусов, размеры очередей, failed jobs, инвойсы/подписки/платежи по статусам, просроченные инвойсы |
| Ошибки | Sentry при заданном DSN; логи - `docker compose -p billingos-prod logs -f app worker` (json-file с ротацией 5 × 20 МБ) |

Планировщик (`schedule:work` внутри `worker`) каждую минуту продлевает подписки и собирает открытые инвойсы, раз в час чистит истёкшие ключи идемпотентности, раз в сутки - журнал действий старше `BILLING_ACTIVITY_RETENTION_DAYS`. Если `worker` упал, `/health/deep` покажет `scheduler: fail` через `SCHEDULER_MAX_AGE` секунд.

## Бэкапы

Состояние живёт в томах `pgdata` (база), `redisdata` (очереди и кеш), `caddy_data` (сертификаты), `app_storage`.

```bash
docker compose -p billingos-prod exec postgres pg_dump -U billingos -Fc billingos > billingos-$(date +%F).dump
docker compose -p billingos-prod exec -T postgres pg_restore -U billingos -d billingos --clean < billingos-2026-09-18.dump
```

Redis хранит только очереди и кеш: после потери тома платежи в `processing` доведёт `/health/deep` до глаз, а провайдер - повторной доставкой вебхука.

## Масштабирование

- Пул php-fpm - `pm.max_children` в `docker/php/Dockerfile.prod` (20 по умолчанию, ~135 rps на ноутбуке, см. [benchmarks.md](benchmarks.md)); `app` можно поднять в несколько реплик за nginx.
- Воркеров очередей можно несколько: `docker compose … up -d --scale worker=3` - job'ы идемпотентны, планировщик защищён `withoutOverlapping`.
- База и Redis выносятся на управляемые сервисы простым изменением `DB_HOST` / `REDIS_HOST` в `.env.prod` (и удалением сервисов из compose).
