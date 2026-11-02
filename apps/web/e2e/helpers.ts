import { expect, type BrowserContext } from "@playwright/test";

/**
 * Shared SPA auth helpers for the e2e suites (Milestone 2 timer sync,
 * Milestone 3 presence).
 *
 * Requests go through the browser contexts (not Node fetch) so they carry
 * the Sanctum stateful markers: the localhost cookie jar, a Referer from
 * the frontend origin, and the XSRF token — without those the API has no
 * session store and rejects the call.
 *
 * Requires the local stack (see playwright.config.ts): API on :8000,
 * Reverb on :8080, Next.js on :3000. If the API's rate limiter is warm
 * from earlier runs, clear it first: `php artisan cache:clear`.
 */

export const API_URL = process.env.E2E_API_URL ?? "http://localhost:8000";
export const WEB_URL = process.env.E2E_WEB_URL ?? "http://localhost:3000";

/** One account pair per run, shared by every spec file (auth is throttled). */
export const RUN = Date.now();

export type User = { email: string; password: string };

export const USERS: Record<"host" | "mate", User> = {
  host: { email: `e2e-host-${RUN}@example.com`, password: "secret123" },
  mate: { email: `e2e-mate-${RUN}@example.com`, password: "secret123" },
};

const sleep = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));

/** PRD 6: register and login are capped at 5/min per IP — wait a window out. */
const RATE_LIMIT_ATTEMPTS = 6;
const RATE_LIMIT_BACKOFF_MS = 11_000;

export async function xsrfToken(context: BrowserContext): Promise<string> {
  const cookie = (await context.cookies()).find((c) => c.name === "XSRF-TOKEN")?.value;
  return cookie ? decodeURIComponent(cookie) : "";
}

type PostResult = { ok: boolean; status: number; body: string };

async function spaPost(context: BrowserContext, path: string, data?: unknown): Promise<PostResult> {
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

export async function fetchCsrf(context: BrowserContext): Promise<void> {
  await context.request.get(`${API_URL}/sanctum/csrf-cookie`, {
    headers: { Referer: `${WEB_URL}/` },
  });
}

/** Register through the SPA flow (also logs the user in). */
export async function registerUser(context: BrowserContext, user: User, name: string): Promise<void> {
  await fetchCsrf(context);

  for (let attempt = 0; ; attempt++) {
    const res = await spaPost(context, "/api/v1/auth/register", {
      name,
      email: user.email,
      password: user.password,
      password_confirmation: user.password,
    });

    if (res.ok) return;

    if (res.status === 429 && attempt < RATE_LIMIT_ATTEMPTS) {
      await sleep(RATE_LIMIT_BACKOFF_MS);
      continue;
    }

    // Accounts are shared by every spec file: whoever registered first wins,
    // and login is what actually establishes each test's session.
    if (res.status === 422 && res.body.includes("already been taken")) return;

    throw new Error(`register(${user.email}) failed: ${res.status} ${res.body}`);
  }
}

/** Establish a session in the context via the real login endpoint. */
export async function loginContext(context: BrowserContext, user: User): Promise<void> {
  await fetchCsrf(context);

  for (let attempt = 0; ; attempt++) {
    const res = await spaPost(context, "/api/v1/auth/login", {
      email: user.email,
      password: user.password,
    });

    if (res.ok) return;

    if (res.status === 429 && attempt < RATE_LIMIT_ATTEMPTS) {
      await sleep(RATE_LIMIT_BACKOFF_MS);
      continue;
    }

    throw new Error(`login(${user.email}) failed: ${res.status} ${res.body}`);
  }
}

/** Authenticated POST from inside a browser context (SPA XSRF flow). */
export async function apiPost(
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

  const body = await res.text();
  expect(res.ok(), `POST ${path} -> ${res.status()} ${body}`).toBeTruthy();

  return JSON.parse(body) as Record<string, unknown>;
}

/** Authenticated GET from inside a browser context (SPA session). */
export async function apiGet<T>(context: BrowserContext, path: string): Promise<T> {
  const res = await context.request.get(`${API_URL}/api/v1${path}`, {
    headers: { Accept: "application/json", Referer: `${WEB_URL}/` },
  });

  const body = await res.text();
  expect(res.ok(), `GET ${path} -> ${res.status()} ${body}`).toBeTruthy();

  return JSON.parse(body) as T;
}
