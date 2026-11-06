import { test, expect } from "@playwright/test";
import { USERS, loginContext, registerUser } from "./helpers";

/**
 * Milestone 5 acceptance (PRD 14): "a full (or shortened) session credits
 * the expected minutes and shows in stats."
 *
 * The crediting math itself — accrual caps, the 5-minute floor, the 12-hour
 * cap, timezone buckets, streaks and the stats payloads — is owned by
 * `tests/Feature/FocusCreditTest.php` (20 tests), which drives the real
 * heartbeat → attendance → CreditFocusForPhase pipeline in isolation. This
 * suite covers what only a browser can: the pages render their data, the
 * nav links exist, and the empty state speaks to a brand-new account.
 *
 * Requires the local stack (see playwright.config.ts):
 *
 *   cd apps/api && php artisan serve --port=8000
 *   cd apps/api && php artisan reverb:start
 *   cd apps/web && npm run dev
 */

/** The leaderboard is a public page: guests read the same weekly top. */
test("guest sees the public weekly leaderboard", async ({ page }) => {
  await page.goto("/leaderboard");

  await expect(page.getByRole("heading", { name: "Weekly leaderboard" })).toBeVisible();
  await expect(page.getByText(/Top 50 by credited focus minutes/)).toBeVisible();

  // Either rows or the honest empty state — never a spinner forever.
  const rows = page.locator("ol li");
  const empty = page.getByText("No credited focus minutes here yet this week.");
  await expect(rows.or(empty).first()).toBeVisible({ timeout: 15_000 });

  // Guests get the login pitch instead of room-scope chips.
  await expect(page.getByRole("link", { name: "Log in" }).first()).toBeVisible();
});

test("stats page greets a fresh account with the empty state", async ({ page, context }) => {
  await registerUser(context, USERS.host, "Stats Host");
  await loginContext(context, USERS.host);

  await page.goto("/me");
  await expect(page.getByRole("heading", { name: "Your focus stats" })).toBeVisible();
  await expect(page.getByText("No credited focus yet")).toBeVisible();
  await expect(page.getByRole("link", { name: "Find a room" })).toBeVisible();
});

test("nav exposes stats and leaderboard to a signed-in member", async ({ page, context }) => {
  await registerUser(context, USERS.mate, "Stats Mate");
  await loginContext(context, USERS.mate);

  await page.goto("/");
  await expect(page.getByRole("link", { name: "Stats", exact: true })).toBeVisible();
  await expect(page.getByRole("link", { name: "Leaderboard", exact: true })).toBeVisible();

  // The room-scope chips come from the member's own rooms; a fresh account
  // has none, so only Global shows — and the board itself still loads.
  await page.getByRole("link", { name: "Leaderboard", exact: true }).click();
  await expect(page.getByRole("link", { name: "Global" })).toBeVisible();
  await expect(page.getByRole("heading", { name: /leaderboard/ })).toBeVisible();
});
