import { test, expect, type BrowserContext, type Page, type Response } from "@playwright/test";
import { RUN, USERS, apiGet, apiPost, dismissRules, loginContext, registerUser } from "./helpers";

/**
 * Milestone 3 acceptance (PRD 14): "members appear/disappear live, closing
 * a tab removes the member within 60 seconds, and joining a second room
 * kicks the first."
 *
 * Requires the local stack — including the scheduler, so the stale reaper
 * can run (see playwright.config.ts):
 *
 *   cd apps/api && php artisan serve --port=8000
 *   cd apps/api && php artisan reverb:start
 *   cd apps/api && php artisan schedule:work
 *   cd apps/web && npm run dev
 *
 * PRD instruction #7 covers heartbeat and reaper logic with feature tests
 * (tests/Feature/PresenceTest.php); this suite proves the same behaviour
 * end to end through two real browsers.
 *
 * `php artisan serve` handles one request at a time (~1 s each), and a page
 * load fans out several, so assertions that ride the join pipeline wait up
 * to 60 s: the gate below ("intention field visible") is the mate actually
 * holding a seat, which is what makes the arrival assertions meaningful.
 */

type RoomRef = { id: number; slug: string };

type RoomState = { members: Array<{ user_id: number; name: string | null }> };

/** The member grid cards (PRD 4.4: avatar, name, intention, status dot). */
function memberCards(page: Page) {
  return page.locator('section[aria-label="Room members"] li');
}

/** The intention field only renders for members — a "seat held" gate. */
function joinedGate(page: Page) {
  return page.locator("#intention");
}

/** Resolves once the page authorizes its presence channel (subscribes next). */
function presenceAuthorized(page: Page): Promise<Response> {
  return page.waitForResponse(
    (res) => res.url().includes("/broadcasting/auth") && res.request().method() === "POST",
  );
}

/** Create a public room through the API (creating auto-joins the creator). */
async function createRoom(context: BrowserContext, name: string): Promise<RoomRef> {
  const data = (await apiPost(context, "/rooms", {
    name,
    type: "public",
    focus_minutes: 25,
    short_break_minutes: 5,
    long_break_minutes: 15,
    long_break_every: 4,
  })) as unknown as { room: RoomRef };

  return data.room;
}

/** The authoritative member list straight from the API (not the UI). */
async function serverMembers(context: BrowserContext, slug: string): Promise<RoomState["members"]> {
  const state = await apiGet<RoomState>(context, `/rooms/${slug}`);
  return state.members;
}

test.beforeAll(async ({ browser }) => {
  // Register both users once per run (the register endpoint is throttled).
  const setup = await browser.newContext();
  try {
    await registerUser(setup, USERS.host, "E2E host");
    await registerUser(setup, USERS.mate, "E2E mate");
  } finally {
    await setup.close();
  }
});

test.describe("live presence (Milestone 3)", () => {
  test("a member appears live and their intention reaches the room", async ({ browser }) => {
    test.setTimeout(180_000); // absorbs auth rate-limit backoff

    const hostCtx = await browser.newContext();
    const mateCtx = await browser.newContext();

    try {
      await loginContext(hostCtx, USERS.host);
      await loginContext(mateCtx, USERS.mate);

      const room = await createRoom(hostCtx, `E2E Presence ${RUN}`);

      // The host auto-joins an open room (M3) and sees their own card.
      const hostPage = await hostCtx.newPage();
      await hostPage.goto(`/room/${room.slug}`);
      await expect(memberCards(hostPage)).toHaveCount(1, { timeout: 60_000 });
      await expect(memberCards(hostPage)).toContainText("E2E host");

      const matePage = await mateCtx.newPage();
      const matePresence = presenceAuthorized(matePage);
      await matePage.goto(`/room/${room.slug}`);

      // Gate: the mate actually holds a seat (auto-join + refresh done).
      await expect(joinedGate(matePage)).toBeVisible({ timeout: 60_000 });
      await matePresence;
      await expect(memberCards(matePage)).toHaveCount(2, { timeout: 60_000 });

      // The live part: the host's page was already showing one card and
      // now shows two — no reload anywhere, presence did the work.
      await expect(memberCards(hostPage)).toHaveCount(2, { timeout: 60_000 });
      await expect(memberCards(hostPage).filter({ hasText: "E2E mate" })).toHaveCount(1);

      // PRD 4.5: an intention is visible to the room as soon as it is saved.
      // (The M4 rules prompt overlays a first join — dismiss it first.)
      await dismissRules(matePage);
      await matePage.locator("#intention").fill("Ship milestone 3");
      await matePage.locator("#intention").press("Enter");

      await expect(
        memberCards(hostPage).filter({ hasText: "Ship milestone 3" }),
      ).toHaveCount(1, { timeout: 60_000 });
    } finally {
      await hostCtx.close();
      await mateCtx.close();
    }
  });

  test("closing a tab drops the member live, then reaper and host transfer follow", async ({
    browser,
  }) => {
    // Up to 60 s of heartbeat silence plus a 30 s reaper cadence, and the
    // logins before that.
    test.setTimeout(300_000);

    const hostCtx = await browser.newContext();
    const mateCtx = await browser.newContext();

    try {
      await loginContext(hostCtx, USERS.host);
      await loginContext(mateCtx, USERS.mate);

      const room = await createRoom(hostCtx, `E2E Reaper ${RUN}`);

      const hostPage = await hostCtx.newPage();
      await hostPage.goto(`/room/${room.slug}`);
      await expect(memberCards(hostPage)).toHaveCount(1, { timeout: 60_000 });

      const matePage = await mateCtx.newPage();
      const matePresence = presenceAuthorized(matePage);
      await matePage.goto(`/room/${room.slug}`);

      // Gate: the mate holds a seat AND their presence channel is live, so
      // the leave broadcast below cannot race the subscription.
      await expect(joinedGate(matePage)).toBeVisible({ timeout: 60_000 });
      await matePresence;
      await expect(memberCards(matePage)).toHaveCount(2, { timeout: 60_000 });
      await expect(memberCards(hostPage)).toHaveCount(2, { timeout: 60_000 });

      // The host's tab goes away: their presence socket drops, so Reverb
      // broadcasts the leave and the mate's card grid reacts immediately —
      // comfortably inside PRD 4.4's "within 60 seconds".
      await hostCtx.close();
      await expect(memberCards(matePage)).toHaveCount(1, { timeout: 30_000 });
      await expect(memberCards(matePage)).toContainText("E2E mate");

      // The row itself only dies with the reaper (60 s of silence, every
      // 30 s): poll the server until it agrees. Five seconds apart keeps
      // the poll well under the 60/min authenticated API limit.
      await expect
        .poll(async () => (await serverMembers(mateCtx, room.slug)).length, {
          intervals: [5_000],
          timeout: 150_000,
          message: "the reaper should remove the stale member row",
        })
        .toBe(1);

      // PRD M3 host transfer: the seat was the host's, so the reaper hands
      // the room to the remaining member — controls and crown appear on
      // the mate's screen without a reload.
      await expect(matePage.getByRole("button", { name: "Start focus" })).toBeVisible({
        timeout: 30_000,
      });
      await expect(memberCards(matePage)).toContainText("👑");
    } finally {
      await hostCtx.close();
      await mateCtx.close();
    }
  });

  test("joining a second room kicks the member out of the first", async ({ browser }) => {
    test.setTimeout(240_000);

    const hostCtx = await browser.newContext();
    const mateCtx = await browser.newContext();

    try {
      await loginContext(hostCtx, USERS.host);
      await loginContext(mateCtx, USERS.mate);

      // Both rooms exist up front: creating a room auto-joins it (one-room
      // rule), so the mate must not create room two while sitting in room one.
      const first = await createRoom(hostCtx, `E2E Kick A ${RUN}`);
      const second = await createRoom(mateCtx, `E2E Kick B ${RUN}`);

      const hostPage = await hostCtx.newPage();
      const hostPresence = presenceAuthorized(hostPage);
      await hostPage.goto(`/room/${first.slug}`);
      await expect(memberCards(hostPage)).toHaveCount(1, { timeout: 60_000 });

      // The host's presence channel must be subscribed BEFORE the kick, so
      // the assertion below observes the live MemberRemoved broadcast.
      await hostPresence;

      const matePage = await mateCtx.newPage();
      await matePage.goto(`/room/${first.slug}`);
      await expect(joinedGate(matePage)).toBeVisible({ timeout: 60_000 });
      await expect(memberCards(matePage)).toHaveCount(2, { timeout: 60_000 });
      await expect(memberCards(hostPage)).toHaveCount(2, { timeout: 60_000 });

      // The mate opens the second room in a new tab: the server moves their
      // single membership immediately (PRD M3 one-room rule).
      const mateSecondTab = await mateCtx.newPage();
      await mateSecondTab.goto(`/room/${second.slug}`);
      await expect(joinedGate(mateSecondTab)).toBeVisible({ timeout: 60_000 });
      await expect(memberCards(mateSecondTab)).toHaveCount(1, { timeout: 60_000 });

      // Room one: the mate's card vanishes from the host's grid live
      // (MemberRemoved over the presence channel).
      await expect(memberCards(hostPage)).toHaveCount(1, { timeout: 30_000 });

      // The mate's original tab keeps heartbeating, so the server's own
      // verdict (409, one-room rule) — never a client-side guess — stops
      // it with a notice.
      await expect(
        matePage.getByText("You joined a different room in another tab."),
      ).toBeVisible({ timeout: 60_000 });

      // And the server agrees the seat is gone.
      await expect
        .poll(async () => (await serverMembers(hostCtx, first.slug)).length, {
          intervals: [3_000],
          timeout: 30_000,
          message: "the one-room rule should evict the mate server-side",
        })
        .toBe(1);
    } finally {
      await hostCtx.close();
      await mateCtx.close();
    }
  });
});
