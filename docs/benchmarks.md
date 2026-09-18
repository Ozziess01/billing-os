# Бенчмарки

Нагрузка снимается k6-скриптом `bench/api.js` с production-стека (`docker-compose.prod.yml`): собранные образы, opcache без проверки mtime, JIT, php-fpm static на 20 воркеров. k6 запускается контейнером в сети стека и ходит напрямую в nginx - TLS и Caddy из измерения исключены, чтобы цифры были про приложение.

## Что измеряется

Четыре параллельных потока с постоянной интенсивностью (`constant-arrival-rate`), 60 секунд:

| Сценарий | Интенсивность | Что делает один шаг |
|---|---|---|
| `reads` | 50 rps | `GET` одного из списков: клиенты, подписки, инвойсы, платежи, дашборд |
| `usage` | 20 rps | `POST /usage` с `idempotency_key` по metered-позиции |
| `subscribe` | 5 rps | `POST /customers` (с картой) + `POST /subscriptions`: внутри инвойс за период, автосписание через провайдера, две проводки в леджер |
| `pay` | 4 rps | `POST /invoices` (черновик с позицией) + `finalize` + `POST /payments` с `Idempotency-Key` |

Лимит `POST /payments` - 60 в минуту на пользователя, поэтому `setup` регистрирует пять аккаунтов, и итерации раздаются им по кругу. Перед стартом прогреваются все пути, чтобы в p95 не попал холодный opcache.

Пороги в скрипте: ошибок < 1 %, p95 чтения и usage < 400 мс, p95 подписки и оплаты < 2 с, проверки ответов > 99 %.

## Окружение

- Windows 10, Docker Desktop (WSL2), контейнерам видно 36 vCPU и 16 ГБ; PostgreSQL и Redis - в том же compose, на том же хосте.
- Образы `billingos/app` (PHP 8.4, php-fpm static 20) и `billingos/frontend`; фронтенд в нагрузке не участвует.
- k6 v1.x в `grafana/k6`, `--network billingos-prod_default`, `BASE=http://nginx/api/v1`.

## Результаты

Базовый профиль (суммарно ~83 rps, 5 577 запросов за 60 с, 0 ошибок, все проверки прошли):

| Сценарий | avg | med | p90 | p95 | max |
|---|---|---|---|---|---|
| `reads` (50 rps) | 78 мс | 79 мс | 97 мс | 104 мс | 146 мс |
| `usage` (20 rps) | 68 мс | 67 мс | 78 мс | 83 мс | 115 мс |
| `pay` (4 rps, 3 запроса на шаг) | 103 мс | 101 мс | 134 мс | 140 мс | 180 мс |
| `subscribe` (5 rps, 2 запроса на шаг) | 126 мс | 126 мс | 205 мс | 217 мс | 300 мс |

Занято было 7-10 виртуальных пользователей из 180 возможных - стек далёк от насыщения. Холодный прогон сразу после пересборки образа даёт те же цифры (p95 101 / 81 / 140 / 218 мс) благодаря прогреву в `setup`.

Тяжёлый профиль (`READ_RPS=200 SUBSCRIBE_RPS=15 USAGE_RPS=80`, 8 аккаунтов):

| Метрика | Значение |
|---|---|
| Пропускная способность | ~137 rps (9 834 запроса за 60 с) |
| Ошибки | 0 |
| p95 по сценариям | 0,95-1,1 с |
| Занято VU | до 142, k6 не успевал выдавать заданную интенсивность |

То есть потолок этой конфигурации - около 135-140 запросов в секунду: 20 воркеров php-fpm при ~100-150 мс на запрос. Дальше растёт очередь, а не ошибки. Масштабируется числом воркеров (`pm.max_children` в `docker/php/Dockerfile.prod`) и репликами `app`; PostgreSQL на этом объёме не был узким местом.

## Что нашлось под нагрузкой

- **Общий счётчик rate limit.** Безымянный `throttle:60,1` в Laravel считает все маршруты с одним ключом (пользователь) в один счётчик: 20 rps usage-событий выбирали лимит платежей того же пользователя, и `POST /payments` отвечал 429. Лимиты стали именованными с разными ключами (`AppServiceProvider::rateLimits`), на это есть тест `keeps rate limits of different endpoints apart`.
- **`pm = dynamic` у php-fpm.** Первые 10 секунд после старта p95 был ~600 мс при медиане 80: пул стартовал с двух воркеров и доращивал их под нагрузкой, а запросы ждали в очереди. В production-образе пул статический.

## Как повторить

```bash
sh scripts/local-prod-env.sh
docker compose --env-file .env.prod -f docker-compose.prod.yml -p billingos-prod up -d --build --wait

docker run --rm -i --network billingos-prod_default -v "$PWD/bench:/bench" grafana/k6 run \
  -e BASE=http://nginx/api/v1 /bench/api.js

# тяжёлый профиль
docker run --rm -i --network billingos-prod_default -v "$PWD/bench:/bench" grafana/k6 run \
  -e BASE=http://nginx/api/v1 -e READ_RPS=200 -e SUBSCRIBE_RPS=15 -e USAGE_RPS=80 -e ACCOUNTS=8 /bench/api.js
```

Переменные: `DURATION` (по умолчанию `60s`), `ACCOUNTS`, `READ_RPS`, `SUBSCRIBE_RPS`, `PAY_RPS`, `USAGE_RPS`. Скрипт не чистит за собой: созданные организации остаются в базе стенда.
