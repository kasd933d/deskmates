import { test, expect, type Browser, type BrowserContext, type Page } from "@playwright/test";
import {
  API_URL,
  RUN,
  USERS,
  WEB_URL,
  apiGet,
  apiPost,
  dismissRules,
  loginContext,
  registerUser,
  xsrfToken,
} from "./helpers";

/**
 * Milestone 4 acceptance (PRD 14): "chat and reactions work across
 * multiple clients and abuse tools function end to end."
 *
 * Requires the local stack (see playwright.config.ts):
 *
 *   cd apps/api && php artisan serve --port=8000
 *   cd apps/api && php artisan reverb:start
 *   cd apps/api && php artisan schedule:work
 *   cd apps/web && npm run dev
 *
 * and a warm-but-not-exhausted rate limiter (`php artisan cache:clear`
 * before a run — register/login are throttled at 5/min per IP, and chat
 * itself at 1 per 2 s / 20 per min per user).
 *
 * What is covered here vs. in tests/Feature: the two-browser flows (live
 * chat delivery, reaction whispers, mute verdicts, kick/ban verdicts and
 * the report dialog) run through real browsers and Reverb; the admin
 * queue's happy path (list + review/dismiss) is API-shaped and covered by
 * ModerationTest, while this suite pins the role gate a member sees.
 */

type RoomRef = { id: number; slug: string };
type Member = { user_id: number; name: string | null };

/** The member grid cards (PRD 4.4). */
function memberCards(page: Page) {
  return page.locator('section[aria-label="Room members"] li');
}

/** The intention field only renders for members — a "seat held" gate. */
function joinedGate(page: Page) {
  return page.locator("#intention");
}

/** Resolves once the page authorizes its presence channel (subscribes next). */
function presenceAuthorized(page: Page): Promise<unknown> {
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
async function serverMembers(context: BrowserContext, slug: string): Promise<Member[]> {
  const state = await apiGet<{ members: Member[] }>(context, `/rooms/${slug}`);
  return state.members;
}

type RoomPair = {
  hostCtx: BrowserContext;
  mateCtx: BrowserContext;
  hostPage: Page;
  matePage: Page;
  room: RoomRef;
  hostId: number;
  mateId: number;
  close: () => Promise<void>;
};

/**
 * Both browsers registered, logged in, seated in one fresh public room
 * with their presence channels live and the rules prompt dismissed — the
 * common setup every test below starts from.
 */
async function openRoomPair(browser: Browser, label: string): Promise<RoomPair> {
  const hostCtx = await browser.newContext();
  const mateCtx = await browser.newContext();

  try {
    await loginContext(hostCtx, USERS.host);
    await loginContext(mateCtx, USERS.mate);

    const room = await createRoom(hostCtx, `E2E ${label} ${RUN}`);

    const hostPage = await hostCtx.newPage();
    const hostPresence = presenceAuthorized(hostPage);
    await hostPage.goto(`/room/${room.slug}`);
    await expect(memberCards(hostPage)).toHaveCount(1, { timeout: 60_000 });
    await hostPresence;

    const matePage = await mateCtx.newPage();
    const matePresence = presenceAuthorized(matePage);
    await matePage.goto(`/room/${room.slug}`);
    await expect(joinedGate(matePage)).toBeVisible({ timeout: 60_000 });
    await matePresence;
    await expect(memberCards(matePage)).toHaveCount(2, { timeout: 60_000 });
    await expect(memberCards(hostPage)).toHaveCount(2, { timeout: 60_000 });

    // Fresh contexts have never seen the rules prompt (PRD 4.12) — it
    // overlays the room and would intercept every click below.
    await dismissRules(hostPage);
    await dismissRules(matePage);

    const members = await serverMembers(hostCtx, room.slug);
    const hostId = members.find((m) => m.name === "E2E host")?.user_id;
    const mateId = members.find((m) => m.name === "E2E mate")?.user_id;
    if (hostId === undefined || mateId === undefined) {
      throw new Error(`room members missing: ${JSON.stringify(members)}`);
    }

    return {
      hostCtx,
      mateCtx,
      hostPage,
      matePage,
      room,
      hostId,
      mateId,
      close: async () => {
        await hostCtx.close();
        await mateCtx.close();
      },
    };
  } catch (err) {
    await hostCtx.close();
    await mateCtx.close();
    throw err;
  }
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

test.describe("chat, reactions and moderation (Milestone 4)", () => {
  test("chat messages flow live between two clients", async ({ browser }) => {
    test.setTimeout(240_000);
    const s = await openRoomPair(browser, "Chat");

    try {
      // The mate types; the host's already-open page shows the bubble
      // with no reload — MessageSent over the presence channel. Count
      // exactly one: the POST response and the broadcast must dedupe.
      // (exact: true — message rows carry "Delete message"/"Report
      // message" buttons that substring-match "Message" otherwise.)
      await s.matePage.getByLabel("Message", { exact: true }).fill("hello from mate");
      await s.matePage.getByLabel("Message", { exact: true }).press("Enter");
      await expect(s.hostPage.getByText("hello from mate")).toHaveCount(1, { timeout: 60_000 });

      // PRD 4.6: links are blocked for accounts under 24 h — and the
      // verdict renders inline instead of failing silently (e2e accounts
      // are always brand new). Wait out the 1/s burst window first so the
      // 422 — not the 429 — is what the panel reports.
      await s.matePage.waitForTimeout(2_500);
      await s.matePage.getByLabel("Message", { exact: true }).fill("see https://spam.example");
      await s.matePage.getByLabel("Message", { exact: true }).press("Enter");
      await expect(
        s.matePage.getByText("New accounts cannot post links for their first 24 hours."),
      ).toBeVisible({ timeout: 30_000 });

      // And live the other way: the host replies, the mate receives.
      await s.hostPage.getByLabel("Message", { exact: true }).fill("welcome to the room");
      await s.hostPage.getByLabel("Message", { exact: true }).press("Enter");
      await expect(s.hostPage.getByText("welcome to the room")).toHaveCount(1, { timeout: 60_000 });
      await expect(s.matePage.getByText("welcome to the room")).toHaveCount(1, { timeout: 60_000 });
    } finally {
      await s.close();
    }
  });

  test("reactions whisper to every other client", async ({ browser }) => {
    test.setTimeout(240_000);
    const s = await openRoomPair(browser, "React");

    try {
      await s.matePage.getByTestId("reaction-👏").click();

      // Reverb never echoes a client event back to its sender
      // (Channel::broadcast skips the origin) — the sender renders its
      // own float locally.
      await expect(
        s.matePage.locator('[data-testid="reaction-floats"] span').filter({ hasText: "👏" }),
      ).toHaveCount(1, { timeout: 15_000 });

      // Everyone else gets the whisper and floats it too.
      await expect(
        s.hostPage.locator('[data-testid="reaction-floats"] span').filter({ hasText: "👏" }),
      ).toHaveCount(1, { timeout: 30_000 });
    } finally {
      await s.close();
    }
  });

  test("host mute silences the composer live and unmute restores it", async ({ browser }) => {
    test.setTimeout(240_000);
    const s = await openRoomPair(browser, "Mute");

    try {
      await s.hostPage.getByTestId(`mute-${s.mateId}`).click();

      // MemberMuted (presence): the 🔇 badge lands on the card and the
      // muted member's own composer flips off with the reason visible.
      await expect(memberCards(s.hostPage).filter({ hasText: "E2E mate" })).toContainText("🔇", {
        timeout: 30_000,
      });
      const composer = s.matePage.getByLabel("Message", { exact: true });
      await expect(composer).toBeDisabled({ timeout: 30_000 });
      await expect(composer).toHaveAttribute("placeholder", "You are muted by the host.");

      // Same button (label flipped to Unmute by the same broadcast).
      await s.hostPage.getByTestId(`mute-${s.mateId}`).click();
      await expect(composer).toBeEnabled({ timeout: 30_000 });
    } finally {
      await s.close();
    }
  });

  test("kick allows a rejoin, ban refuses one — and both tell the target why", async ({
    browser,
  }) => {
    test.setTimeout(300_000);
    const s = await openRoomPair(browser, "Kick");

    try {
      // --- Kick: membership removed; the verdict rides the private
      // channel because a presence leave alone cannot say "you were
      // kicked" (PRD 7.2 MemberKicked). ---
      await s.hostPage.getByTestId(`kick-${s.mateId}`).click();
      await s.hostPage.getByTestId("confirm-kick").click();
      await expect(
        s.matePage.getByText("You were kicked from this room. You can rejoin."),
      ).toBeVisible({ timeout: 60_000 });

      // The server agrees a kicked member may come back...
      await apiPost(s.mateCtx, `/rooms/${s.room.id}/join`);

      // ...but the API join alone creates no socket: the wire
      // member_added that updates the host's grid only fires when this
      // member's own page re-subscribes, so re-enter the room the way a
      // real user would (the rules flag suppresses the prompt).
      await s.matePage.reload();
      await expect(joinedGate(s.matePage)).toBeVisible({ timeout: 60_000 });

      // ...and the host's grid sees them return live (presence joining).
      await expect(memberCards(s.hostPage).filter({ hasText: "E2E mate" })).toHaveCount(1, {
        timeout: 60_000,
      });

      // --- Ban: membership removed + a room_bans row that outlives it. ---
      await s.hostPage.getByTestId(`ban-${s.mateId}`).click();
      await s.hostPage.getByTestId("confirm-ban").click();
      await expect(
        s.matePage.getByText("You were banned from this room and cannot rejoin."),
      ).toBeVisible({ timeout: 60_000 });

      // The rejoin path answers 403 for a banned user. Raw request: the
      // apiPost helper asserts success, which is exactly what must not
      // happen here.
      const res = await s.mateCtx.request.post(`${API_URL}/api/v1/rooms/${s.room.id}/join`, {
        headers: {
          Accept: "application/json",
          Referer: `${WEB_URL}/`,
          "X-XSRF-TOKEN": await xsrfToken(s.mateCtx),
        },
        data: {},
      });
      expect(res.status()).toBe(403);
      expect(await res.text()).toContain("You are banned from this room.");
    } finally {
      await s.close();
    }
  });

  test("a report is submitted from the room and the queue is admin-only", async ({ browser }) => {
    test.setTimeout(240_000);
    const s = await openRoomPair(browser, "Report");

    try {
      // PRD 4.12: report a member (optionally with a message) — the
      // dialog posts to /reports and confirms inline.
      await s.matePage.getByTestId(`report-${s.hostId}`).click();
      await s.matePage.getByLabel("Reason").fill("Kept derailing the room");
      await s.matePage.getByRole("button", { name: "Send report" }).click();
      await expect(s.matePage.getByTestId("report-sent")).toBeVisible({ timeout: 30_000 });

      // PRD 6: /admin/reports sits behind the admin role — a plain member
      // sees the API's own 403 surfaced verbatim. (The queue's review flow
      // is API-shaped and lives in ModerationTest.) getByText, not
      // getByRole("alert"): Next's route announcer is also a role=alert.
      await s.hostPage.goto("/admin/reports");
      await expect(s.hostPage.getByText("Admins only.")).toBeVisible({ timeout: 30_000 });
    } finally {
      await s.close();
    }
  });
});
