# syntax=docker/dockerfile:1

FROM node:22-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# NEXT_PUBLIC_* вшиваются в бандл на сборке - образ собирается под конкретный домен
FROM node:22-alpine AS build
WORKDIR /app
ARG NEXT_PUBLIC_API_URL
ARG NEXT_PUBLIC_WS_HOST
ARG NEXT_PUBLIC_WS_PORT=443
ARG NEXT_PUBLIC_WS_TLS=true
ARG NEXT_PUBLIC_REVERB_KEY
ARG NEXT_PUBLIC_SENTRY_DSN=
ENV NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL \
    NEXT_PUBLIC_WS_HOST=$NEXT_PUBLIC_WS_HOST \
    NEXT_PUBLIC_WS_PORT=$NEXT_PUBLIC_WS_PORT \
    NEXT_PUBLIC_WS_TLS=$NEXT_PUBLIC_WS_TLS \
    NEXT_PUBLIC_REVERB_KEY=$NEXT_PUBLIC_REVERB_KEY \
    NEXT_PUBLIC_SENTRY_DSN=$NEXT_PUBLIC_SENTRY_DSN \
    NEXT_TELEMETRY_DISABLED=1
COPY --from=deps /app/node_modules ./node_modules
COPY . .
RUN npm run build

# standalone-сборка: только server.js с нужными модулями, без node_modules проекта
FROM node:22-alpine AS runtime
WORKDIR /app
ENV NODE_ENV=production HOSTNAME=0.0.0.0 PORT=3000 NEXT_TELEMETRY_DISABLED=1
COPY --from=build --chown=node:node /app/.next/standalone ./
COPY --from=build --chown=node:node /app/.next/static ./.next/static
COPY --from=build --chown=node:node /app/public ./public
USER node
EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget -qO- http://127.0.0.1:3000/login >/dev/null || exit 1
CMD ["node", "server.js"]
