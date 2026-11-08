import { test, expect, type BrowserContext } from "@playwright/test";
import { RUN, USERS, apiGet, apiPost, loginContext, registerUser } from "./helpers";

/**
 * Milestone 6 acceptance (PRD 14): "a scheduled session starts automatically
 * and RSVP'd users receive a push reminder."
 *
 * The reminder's delivery — window selection, the exactly-once marker and
 * the 404/410 cleanup — is owned by `tests/Feature/ScheduledSessionTest`
 * and `tests/Feature/PushTest`, which drive the real WebPush stack against
 * a fake PSR-18 client (browsers cannot receive real Web Push here). This
 * suite covers what only a browser can: booking through the form, the RSVP
 * toggle, and the every-minute scheduler flipping the room into focus with
 * the booked config applied.
 *
 * Requires the local stack **including the scheduler worker**
 * (see playwright.config.ts):
 *
 *   cd apps/api && php artisan serve --port=8000
 *   cd apps/api && php artisan reverb:start
 *   cd apps/api && php artisan schedule:work
 *   cd apps/web && npm run dev
 */

const sleep = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));

/** Local minute-grid value for the datetime-local input, ~90 s out. */
function startsAtValue(): string {
  const date = new Date(Date.now() + 90_000);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

type RoomState = {
  room: { phase: string; focus_minutes: number; short_break_minutes: number };
  timer: { phase: string };
};

/**
 * Poll the room's REST state until the scheduler has started it. The
 * `sessions:start` sweep runs once a minute, so a session booked ~90 s out
 * lands focus within ~3 minutes of submission.
 */
async function waitForPhase(context: BrowserContext, slug: string, phase: string): Promise<RoomState> {
  const deadline = Date.now() + 240_000;
  let last: RoomState | null = null;

  while (Date.now() < deadline) {
    last = await apiGet<RoomState>(context, `/rooms/${slug}`);
    if (last.timer.phase === phase) return last;
    await sleep(3000);
  }

  throw new Error(`Timed out waiting for phase "${phase}" (last seen: ${last?.timer.phase ?? "none"})`);
}

test.describe("schedule (Milestone 6)", () => {
  test("guests are redirected and signed-in members get the nav link", async ({ page, context }) => {
    await page.goto("/schedule");
    await expect(page).toHaveURL(/\/login/, { timeout: 15_000 });

    await registerUser(context, USERS.mate, "Schedule Mate");
    await loginContext(context, USERS.mate);

    await page.goto("/");
    const navLink = page.getByRole("link", { name: "Schedule", exact: true });
    await expect(navLink).toBeVisible();
    await navLink.click();

    await expect(page.getByRole("heading", { name: "Schedule" })).toBeVisible();
    // This account hosts no rooms, so the booking form is replaced by the
    // "host a room first" pitch — never a broken empty form.
    await expect(page.getByText("Host a room to schedule sessions")).toBeVisible();
  });

  test("a booked session auto-starts the room with its booked config", async ({ browser }) => {
    // Booking + RSVP up front, then the every-minute scheduler claims the
    // session at its start time (up to ~90 s out) on the next sweep.
    test.setTimeout(300_000);

    const context = await browser.newContext();
    try {
      await loginContext(context, USERS.host);

      const room = (await apiPost(context, "/rooms", {
        name: `E2E Schedule ${RUN}`,
        type: "public",
        focus_minutes: 25,
        short_break_minutes: 5,
        long_break_minutes: 15,
        long_break_every: 4,
      })) as unknown as { room: { id: number; slug: string; name: string } };

      const page = await context.newPage();
      await page.goto("/schedule");
      await expect(page.getByTestId("schedule-form")).toBeVisible();

      // Book it: room, title, start time and a config that differs from
      // the room's defaults so "config applied" is observable.
      await page.getByTestId("session-room").selectOption({ label: room.room.name });
      await page.getByTestId("session-title").fill("Auto-start sprint");
      await page.getByTestId("session-starts-at").fill(startsAtValue());
      await page.getByTestId("session-focus_minutes").fill("50");
      await page.getByTestId("session-short_break_minutes").fill("10");
      await page.getByTestId("session-submit").click();

      const row = page.getByTestId("session-row").filter({ hasText: "Auto-start sprint" });
      await expect(row).toBeVisible();
      await expect(row).toContainText("50/10 min");
      await expect(row.getByTestId("rsvp-count")).toHaveText("0");

      // RSVP is a toggle: on, off, on again (the API is idempotent too —
      // covered by ScheduledSessionTest).
      await row.getByRole("button", { name: "RSVP" }).click();
      await expect(row.getByRole("button", { name: "Going ✓" })).toBeVisible();
      await expect(row.getByTestId("rsvp-count")).toHaveText("1");

      await row.getByRole("button", { name: "Going ✓" }).click();
      await expect(row.getByRole("button", { name: "RSVP", exact: true })).toBeVisible();
      await expect(row.getByTestId("rsvp-count")).toHaveText("0");

      await row.getByRole("button", { name: "RSVP", exact: true }).click();
      await expect(row.getByTestId("rsvp-count")).toHaveText("1");

      // PRD 4.11: at start time the room timer starts by itself, with the
      // booking's config applied over the room's defaults.
      const state = await waitForPhase(context, room.room.slug, "focus");
      expect(state.room.focus_minutes).toBe(50);
      expect(state.room.short_break_minutes).toBe(10);

      // The started booking left the upcoming list.
      await page.reload();
      await expect(page.getByTestId("session-row").filter({ hasText: "Auto-start sprint" })).toHaveCount(0);
    } finally {
      await context.close();
    }
  });
});
