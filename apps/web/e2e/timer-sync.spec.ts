import { test, expect, type BrowserContext, type Page } from "@playwright/test";

/**
 * Milestone 2 acceptance (PRD 14): "the Playwright two-browser sync test
 * passes, including skip and reconnect cases" — everyone in a room sees
 * the same timer within ±1 second.
 *
 * Requires the local stack (see playwright.config.ts): API on :8000,
 * Reverb on :8080, Next.js on :3000. If the API's rate limiter is
 * warm from earlier runs, clear it first: `php artisan cache:clear`.
 *
 * Requests go through the browser contexts (not Node fetch) so they carry
 * the Sanctum stateful markers: the localhost cookie jar, a Referer from
 * the frontend origin, and the XSRF token — without those the API has no
 * session store and rejects the call.
 *
 * Users are registered once per run (the register endpoint is throttled)
 * and reused by every test; each test logs them into fresh contexts.
 */

const API_URL = process.env.E2E_API_URL ?? "http://localhost:8000";
const WEB_URL = process.env.E2E_WEB_URL ?? "http://localhost:3000";

const RUN = Date.now();

type User = { email: string; password: string };

const USERS: Record<"host" | "mate", User> = {
  host: { email: `e2e-host-${RUN}@example.com`, password: "secret123" },
  mate: { email: `e2e-mate-${RUN}@example.com`, password: "secret123" },
};

async function xsrfToken(context: BrowserContext): Promise<string> {
  const cookie = (await context.cookies()).find((c) => c.name === "XSRF-TOKEN")?.value;
  return cookie ? decodeURIComponent(cookie) : "";
}

async function spaPost(
  context: BrowserContext,
  path: string,
  data?: unknown,
): Promise<{ ok: boolean; status: number; body: string }> {
  const res = await context.request.post(`${API_URL}${path}`, {
    headers: {
      Accept: "application/json",
      Referer: `${WEB_URL}/`,
      "X-XSRF-TOKEN": await xsrfToken(context),
    },
    data,
  });

  return { ok: res.ok(), status: res.status(), body: await res.text() };
}

async function fetchCsrf(context: BrowserContext): Promise<void> {
  await context.request.get(`${API_URL}/sanctum/csrf-cookie`, {
    headers: { Referer: `${WEB_URL}/` },
  });
}

/** Register through the SPA flow (also logs the user in). */
async function registerUser(context: BrowserContext, user: User, name: string): Promise<void> {
  await fetchCsrf(context);

  const res = await spaPost(context, "/api/v1/auth/register", {
    name,
    email: user.email,
    password: user.password,
    password_confirmation: user.password,
  });

  if (!res.ok) {
    throw new Error(`register(${user.email}) failed: ${res.status} ${res.body}`);
  }
}

/** Establish a session in the context via the real login endpoint. */
async function loginContext(context: BrowserContext, user: User): Promise<void> {
  await fetchCsrf(context);

  const res = await spaPost(context, "/api/v1/auth/login", {
    email: user.email,
    password: user.password,
  });

  if (!res.ok) {
    throw new Error(`login(${user.email}) failed: ${res.status} ${res.body}`);
  }
}

/** Authenticated API call from inside a browser context (SPA XSRF flow). */
async function apiPost(
  context: BrowserContext,
  path: string,
  data?: unknown,
): Promise<Record<string, unknown>> {
  const res = await context.request.post(`${API_URL}/api/v1${path}`, {
    headers: {
      Accept: "application/json",
      Referer: `${WEB_URL}/`,
      "X-XSRF-TOKEN": await xsrfToken(context),
    },
    data,
  });

  expect(res.ok(), `POST ${path} -> ${res.status()} ${await res.text()}`).toBeTruthy();

  return res.json();
}

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
