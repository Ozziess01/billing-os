#!/bin/sh
# Собирает .env.prod для проверки production-стека на этой машине:
# домен localhost (Caddy выпустит self-signed сертификат), порты 8880/8443,
# случайные ключи, почта в лог. Для настоящего деплоя файл заполняется руками.
set -e
cd "$(dirname "$0")/.."

if [ -f .env.prod ] && [ "$1" != "--force" ]; then
    echo ".env.prod уже есть, добавь --force чтобы перезаписать" >&2
    exit 1
fi

sed \
    -e "s|^DOMAIN=.*|DOMAIN=localhost|" \
    -e "s|^HTTP_PORT=.*|HTTP_PORT=${HTTP_PORT:-8880}|" \
    -e "s|^HTTPS_PORT=.*|HTTPS_PORT=${HTTPS_PORT:-8443}|" \
    -e "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" \
    -e "s|^APP_URL=.*|APP_URL=https://localhost:${HTTPS_PORT:-8443}|" \
    -e "s|^FRONTEND_URL=.*|FRONTEND_URL=https://localhost:${HTTPS_PORT:-8443}|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -hex 16)|" \
    -e "s|^REVERB_APP_KEY=.*|REVERB_APP_KEY=$(openssl rand -hex 16)|" \
    -e "s|^REVERB_APP_SECRET=.*|REVERB_APP_SECRET=$(openssl rand -hex 32)|" \
    -e "s|^METRICS_TOKEN=.*|METRICS_TOKEN=$(openssl rand -hex 16)|" \
    -e "s|^MAIL_MAILER=.*|MAIL_MAILER=log|" \
    -e "s|^NEXT_PUBLIC_API_URL=.*|NEXT_PUBLIC_API_URL=https://localhost:${HTTPS_PORT:-8443}/api/v1|" \
    -e "s|^NEXT_PUBLIC_WS_HOST=.*|NEXT_PUBLIC_WS_HOST=localhost|" \
    -e "s|^NEXT_PUBLIC_WS_PORT=.*|NEXT_PUBLIC_WS_PORT=${HTTPS_PORT:-8443}|" \
    .env.prod.example > .env.prod

echo ".env.prod готов: https://localhost:${HTTPS_PORT:-8443}"
echo "docker compose --env-file .env.prod -f docker-compose.prod.yml -p billingos-prod up -d --build --wait"
