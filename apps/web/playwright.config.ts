import { defineConfig, devices } from "@playwright/test";

/**
 * Milestone 2 e2e: two-browser timer sync.
 *
 * The suite runs against the already-running local stack — it does not
 * start services itself, so the same config works on any machine:
 *
 *   cd apps/api  && php artisan serve --port=8000
 *   cd apps/api  && php artisan reverb:start
 *   cd apps/web  && npm run dev
 *
 * Ports are overridable for CI (see .github/workflows/ci.yml).
 */
const WEB_URL = process.env.E2E_WEB_URL ?? "http://localhost:3000";

export default defineConfig({
  testDir: "./e2e",
  timeout: 90_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [["github"], ["html", { open: "never" }]] : [["list"]],
  use: {
    baseURL: WEB_URL,
    trace: "retain-on-failure",
  },
  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
});
