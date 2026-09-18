import * as Sentry from "@sentry/nextjs";

// Без NEXT_PUBLIC_SENTRY_DSN инициализация ничего не делает: SDK выключен.
Sentry.init({
  dsn: process.env.NEXT_PUBLIC_SENTRY_DSN || undefined,
  enabled: !!process.env.NEXT_PUBLIC_SENTRY_DSN,
  tracesSampleRate: 0.1,
  sendDefaultPii: false,
});

export const onRouterTransitionStart = Sentry.captureRouterTransitionStart;
