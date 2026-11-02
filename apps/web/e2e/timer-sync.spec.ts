import { test, expect, type Page } from "@playwright/test";
import { RUN, USERS, apiPost, loginContext, registerUser } from "./helpers";

/**
 * Milestone 2 acceptance (PRD 14): "the Playwright two-browser sync test
 * passes, including skip and reconnect cases" — everyone in a room sees
 * the same timer within ±1 second.
 *
 * Requires the local stack (see playwright.config.ts): API on :8000,
 * Reverb on :8080, Next.js on :3000. If the API's rate limiter is
 * warm from earlier runs, clear it first: `php artisan cache:clear`.
 *
 * Auth and SPA request plumbing live in ./helpers (shared with the
 * Milestone 3 presence suite).
 */

/** Parse "M:SS" / "H:MM:SS" into total seconds. */
function toSeconds(text: string): number {
  const parts = text.trim().split(":").map(Number);
  return parts.length === 3
    ? parts[0] * 3600 + parts[1] * 60 + parts[2]
    : parts[0] * 60 + parts[1];
}

/** PRD 1.1: everyone in a room sees the same timer within ±1 second. */
async function expectSameCountdown(left: Page, right: Page): Promise<void> {
  await expect
    .poll(
      async () => {
        const a = toSeconds(await left.getByTestId("timer-countdown").innerText());
        const b = toSeconds(await right.getByTestId("timer-countdown").innerText());
        return Math.abs(a - b);
      },
      { timeout: 15_000, message: "timers should agree within ±1 second" },
    )
    .toBeLessThanOrEqual(1);
}

test.beforeAll(async ({ browser }) => {
  // Register both users once per run through a throwaway context.
  const setup = await browser.newContext();
  try {
    await registerUser(setup, USERS.host, "E2E host");
    await registerUser(setup, USERS.mate, "E2E mate");
  } finally {
    await setup.close();
  }
});

test.describe("synced timer (Milestone 2)", () => {
  test("two browsers stay in sync through start, skip and reconnect", async ({ browser }) => {
    const hostCtx = await browser.newContext();
    const mateCtx = await browser.newContext();

    try {
      // --- Setup: host creates a room, mate joins, both open it. ---
      await loginContext(hostCtx, USERS.host);
      const room = (await apiPost(hostCtx, "/rooms", {
        name: `E2E Sync ${RUN}`,
        type: "public",
        focus_minutes: 25,
        short_break_minutes: 5,
        long_break_minutes: 15,
        long_break_every: 4,
      })) as unknown as { room: { id: number; slug: string } };

      await loginContext(mateCtx, USERS.mate);
      await apiPost(mateCtx, `/rooms/${room.room.id}/join`);

      const hostPage = await hostCtx.newPage();
      const matePage = await mateCtx.newPage();

      await hostPage.goto(`/room/${room.room.slug}`);
      await matePage.goto(`/room/${room.room.slug}`);

      await expect(hostPage.getByTestId("timer-phase")).toHaveText("Waiting to start");
      await expect(matePage.getByTestId("timer-phase")).toHaveText("Waiting to start");

      // Only the host gets controls.
      await expect(matePage.getByRole("button", { name: "Start focus" })).toHaveCount(0);

      // --- Start: the broadcast flips both clients to Focus. ---
      await hostPage.getByRole("button", { name: "Start focus" }).click();

      await expect(hostPage.getByTestId("timer-phase")).toHaveText("Focus");
      await expect(matePage.getByTestId("timer-phase")).toHaveText("Focus");
      await expectSameCountdown(hostPage, matePage);

      // --- Skip: both flip to Short break together. ---
      await hostPage.getByRole("button", { name: "Skip phase" }).click();

      await expect(hostPage.getByTestId("timer-phase")).toHaveText("Short break");
      await expect(matePage.getByTestId("timer-phase")).toHaveText("Short break");
      await expectSameCountdown(hostPage, matePage);

      // --- Reconnect: mate drops offline, misses a skip, catches up. ---
      await mateCtx.setOffline(true);
      await hostPage.waitForTimeout(1_000); // let the offline take effect

      await hostPage.getByRole("button", { name: "Skip phase" }).click();
      await expect(hostPage.getByTestId("timer-phase")).toHaveText("Focus");

      // The offline client missed the broadcast entirely.
      await hostPage.waitForTimeout(1_000);
      await expect(matePage.getByTestId("timer-phase")).toHaveText("Short break");

      // Back online: Echo reconnects, re-syncs the clock and re-fetches.
      await mateCtx.setOffline(false);
      await expect(matePage.getByTestId("timer-phase")).toHaveText("Focus", {
        timeout: 30_000,
      });
      await expectSameCountdown(hostPage, matePage);
    } finally {
      await hostCtx.close();
      await mateCtx.close();
    }
  });

  test("always-on rooms compute the same deterministic timer in two browsers", async ({ browser }) => {
    const ctxA = await browser.newContext();
    const ctxB = await browser.newContext();

    try {
      await loginContext(ctxA, USERS.host);
      await loginContext(ctxB, USERS.mate);

      const pageA = await ctxA.newPage();
      const pageB = await ctxB.newPage();

      // Seeded official room (AlwaysOnRoomSeeder); no join required.
      await pageA.goto("/room/the-library");
      await pageB.goto("/room/the-library");

      await expect(pageA.getByTestId("timer-phase")).toHaveText(/Focus|Short break|Long break/);
      await expect(pageB.getByTestId("timer-phase")).toHaveText(/Focus|Short break|Long break/);

      // Pure function of (now, anchor, config): identical in both browsers.
      await expectSameCountdown(pageA, pageB);
    } finally {
      await ctxA.close();
      await ctxB.close();
    }
  });
});
