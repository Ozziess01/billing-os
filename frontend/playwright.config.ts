import { defineConfig, devices } from "@playwright/test";

// Стек поднимается отдельно (docker compose up), тесты ходят по BOS_URL.
// Каждый спек регистрирует свой аккаунт и организацию, поэтому спеки независимы.
export default defineConfig({
  testDir: "./tests/e2e",
  timeout: 90_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: process.env.CI ? 1 : 2,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [["list"], ["html", { open: "never" }]] : "list",
  // скриншоты для README живут отдельным проектом и по умолчанию не гоняются
  projects: [
    { name: "e2e", testIgnore: /screenshots\.spec\.ts/ },
    { name: "screenshots", testMatch: /screenshots\.spec\.ts/ },
  ],
  use: {
    baseURL: process.env.BOS_URL ?? "http://localhost:8090",
    ignoreHTTPSErrors: true,
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
    locale: "ru-RU",
    ...devices["Desktop Chrome"],
    viewport: { width: 1280, height: 800 },
  },
});
