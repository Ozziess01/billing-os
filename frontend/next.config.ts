import { withSentryConfig } from "@sentry/nextjs";
import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // production-образ копирует только .next/standalone + static, без node_modules проекта
  output: "standalone",
  poweredByHeader: false,
};

// Sentry: без DSN SDK не инициализируется, source maps не загружаются (нет токена)
export default withSentryConfig(nextConfig, {
  silent: true,
  sourcemaps: { disable: true },
  telemetry: false,
});
