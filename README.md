# DeskMates

> Study together, silently. DeskMates is a virtual co-working space: join a room, see who else is focusing right now, share one synced Pomodoro timer, declare what you are working on, cheer each other on, and watch your focus hours add up.

Full-stack real-time application. See [`DeskMates_PRD.md`](./DeskMates_PRD.md) for the product requirements and [`docs/decisions.md`](./docs/decisions.md) for build decisions.

**Stack:** Next.js (TypeScript, Tailwind CSS) · Laravel (PHP 8.3) REST API · Laravel Reverb (WebSockets) · MySQL 8 · Web Push (VAPID) · Google OAuth

## Architecture

```
┌──────────────────┐  HTTPS   ┌─────────────────────────────┐   SQL   ┌──────────┐
│ Next.js (Vercel) │─────────▶│ Laravel API (Docker)        │────────▶│ MySQL 8  │
│ Echo + pusher-js │          │ Sanctum · Policies · Queue  │         └──────────┘
└────────┬─────────┘          └──────────────┬──────────────┘
         │ WSS                               │ broadcasts
         ▼                                   ▼
┌─────────────────────────────────────────────────────────┐
│ Laravel Reverb (WebSocket server, same host as the API) │
└─────────────────────────────────────────────────────────┘
        Web Push service ◀── Laravel (queued push jobs)
```

## Repository layout

```
deskmates/
├── apps/
│   ├── web/                       # Next.js (App Router, TypeScript, Tailwind)
│   └── api/                       # Laravel REST API + Reverb broadcasting
├── docker/                        # Dockerfiles + entrypoint
├── docker-compose.yml             # api · reverb · worker · scheduler · web · mysql
├── .github/workflows/ci.yml       # lint, type check, tests, build
├── docs/                          # decisions, credits
└── README.md
```

## Quickstart (Docker)

Prerequisites: Docker with Compose.

```sh
cp apps/api/.env.example apps/api/.env
cd apps/api && php artisan key:generate   # or any local PHP; alternatively set APP_KEY by hand
cd ../.. && docker compose up --build
```

- Web: http://localhost:3000
- API: http://localhost:8000
- Reverb (WebSocket): ws://localhost:8080

## Quickstart (local development)

Prerequisites: PHP 8.3+, Composer, Node 20.9+, and a MySQL 8 (Docker is fine: `docker compose up mysql`).

```sh
# API
cd apps/api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve          # http://localhost:8000
php artisan reverb:start   # in a second terminal — ws://localhost:8080
php artisan schedule:work  # in a third terminal — reaper, auto-start, reminders
php artisan queue:work --queue=default,phase,push   # optional at this stage

# Web
cd apps/web
npm install
npm run dev                # http://localhost:3000
```

## Realtime proof (Milestone 0)

The landing page subscribes to the public `ping` channel through Laravel Echo. Every 5 seconds (or when you click **Send ping**) the browser calls `POST /api/v1/ping`; the API broadcasts `PingReceived` over Reverb and the page renders it live with the round-trip time.

## Synced timer (Milestone 2)

The server is the only source of truth for room timers. User rooms keep their phase in the `rooms` table; every host action and phase expiry goes through `RoomClockService::transition()` under a row lock, bumps `phase_version` and broadcasts `RoomPhaseChanged`. Always-on rooms have no timer state at all — the phase is a pure function of `(now, anchor_epoch, config)`, so those clocks self-heal after any crash. Clients render from `phase_ends_at_ms - (Date.now() + clock offset)`, where the offset is the median of three round trips to `GET /api/v1/time`, re-synced every 60 seconds and on tab focus. The proof is a two-browser Playwright test (`apps/web/e2e/timer-sync.spec.ts`) asserting both timers agree within ±1 second through start, skip, and an offline → online reconnect.

## Live presence (Milestone 3)

Every member of a room is a subscriber of a presence channel (`presence-room.{id}`, authorized by policies in `routes/channels.php` — private rooms and full rooms deny non-members). The member grid renders avatar, name, current intention and a status dot straight from `GET /rooms/{slug}` and then stays current through three live paths:

- **Arrivals/leaves:** `.here()`/`.joining()`/`.leaving()` presence callbacks trigger a debounced authoritative room refresh, so cards appear and disappear without a reload. Closing a tab drops the socket and Reverb broadcasts the member removal immediately; the row in the database dies with the reaper.
- **Heartbeat:** every 20 s (and on tab focus) `POST /rooms/{id}/heartbeat` carries the member's status and marks them alive. A member with no heartbeat for 60 s is reaped by `presence:reap-stale` (scheduler, every 30 s), which broadcasts `MemberRemoved` and transfers hostship to the longest-present member when the host was the one reaped. Heartbeat verdicts from the server (409: you joined a different room in another tab, 403: room closed/full) stop the client with a notice — membership is never guessed on the client.
- **Intentions:** saving your intention broadcasts it to the room; everyone's card updates live.

The acceptance suite is `apps/web/e2e/presence.spec.ts` (two browsers): a member appears live with their intention, closing a tab removes the member within 60 s (socket drop → live card removal, reaper → row removal, host transfer → crown moves), and opening a second room kicks the first (one-room rule).

## Chat, reactions & moderation (Milestone 4)

Chat is REST-backed and broadcast live: `POST /rooms/{slug}/messages` persists the message and broadcasts `MessageSent` on the room's presence channel, so every open client renders the bubble with no reload (the POST response and the broadcast dedupe by id); `DELETE` soft-deletes and broadcasts `MessageDeleted`. The server keeps the last 200 messages per room (nightly `messages:prune`), masks profanity, blocks links for accounts under 24 hours old, and rate-limits senders at 1 per 2 s / 20 per minute.

Reactions are pure client events — `channel.whisper("reaction", {emoji})` travels over the same presence channel with no server hop, floats up on every screen (`motion-safe:` only; reduced-motion users get per-emoji counters instead), and the sender renders its own float locally because Reverb never echoes a client event back to its origin. Outbound clicks are gated at 5/s and incoming bursts above 10/s are dropped.

Moderation (host or admin): mute flips a live `MemberMuted` verdict (composer disabled with the reason), kick/ban remove membership and tell the target why over their private `user.{id}` channel — a kicked member may rejoin, a banned one gets 403 at every gate. The host's **Silent focus** toggle broadcasts `RoomSettingsUpdated` and locks chat (reactions stay open) while the room is in a focus phase. Anyone can report a member (3–500 chars) from the room; reports land in the admin queue at `/admin/reports` (role-gated), where admins mark them reviewed or dismiss them. First join of any room shows the community-rules prompt once per browser.

The acceptance suite is `apps/web/e2e/chat-moderation.spec.ts` (two browsers): live chat both ways plus the link-block verdict, reaction whispers in both directions, mute/unmute verdicts, kick → rejoin → ban → 403, and the report dialog plus the admin-only gate. Feature tests cover the queue's review flow, profanity masking, rate limits and ban checks (`ChatTest`, `ModerationTest`).

## Focus credit & stats (Milestone 5)

Heartbeats prove focus: every 20 s beat during a focus phase adds elapsed time (capped at 45 s) to `phase_attendance`. When a phase ends — natural expiry, a host skip/pause, an idle stop, or an always-on flip — `CreditFocusForPhase` converts attendance into `focus_sessions` rows: `floor(seconds / 60)`, only at 5+ minutes, never for breaks, and never past a 12-hour daily cap computed in the member's own timezone. Each credit lands with a `user_daily_focus` rollup and a `FocusCredited` broadcast on the member's private channel, which renders the **"+X min focused"** toast and the break-start check-in prompt (**Did you finish?** → Yes / Partly / No, stored on the session for personal stats). Nightly `focus:rollup` rebuilds daily rows and repairs stalled credits; `focus:prune-attendance` drops attendance 30 days after its phase was credited.

`/me` shows today / this week / all-time minutes, current and longest streaks (a day counts at 25+ credited minutes; a run stays alive until the day after it ends), a 12-week heatmap, best day, average session length, favorite hours and check-in completion. `/leaderboard` lists the weekly top 50 globally or per room; opting out in settings keeps your minutes in the ranking but shows you as "Anonymous focuser". The whole pipeline is pinned by `tests/Feature/FocusCreditTest.php` (20 tests: accrual caps, the 5-minute floor, the 12-hour cap, timezone buckets, streaks, always-on flips, check-ins, leaderboard scoping and nightly repair).

## Notifications, schedule & ambient sound (Milestone 6)

**Scheduled sessions (PRD 4.11).** `/schedule` lists every upcoming booking the viewer can reach — public rooms plus rooms they belong to or host — formatted in their profile timezone; hosts book through the form (room, title, start time, four-key timer config) and RSVP is a one-click idempotent toggle. Two every-minute scheduler entries run the clockwork: `sessions:remind` fans out a Web Push reminder to RSVP'd members exactly once when the start time falls inside the next 10 minutes (`reminded_at` marks the session even when nobody had a subscription, so a delivery failure never re-fires every minute), and `sessions:start` claims due bookings with a conditional `scheduled → started` status flip (idempotent — a crashed sweep can never double-start), cancels bookings whose room was archived, applies the booking's config to the room (broadcast as `RoomSettingsUpdated`) and starts the timer through `RoomClockService::transition()`.

**Web Push (PRD 4.10).** Subscriptions opt in from Settings → Notifications: the browser's `pushManager.subscribe` (VAPID public key from `NEXT_PUBLIC_VAPID_PUBLIC_KEY`) mirrors endpoint + keys to `POST /push/subscribe`, keyed by endpoint so a re-subscribe moves the row to the current account. Phase changes dispatch `SendPhasePush` to the room's current members with a subscription; `PushService` (minishlink/web-push v11, aes128gcm / rfc8291) encrypts every payload against the subscriber's `p256dh` key and reads each answer — a 404/410 deletes the row on the spot, so the table self-cleans. The service worker (`public/push-sw.js`) drops phase toasts while a DeskMates tab is visible (PRD: push fires "if the tab is in the background" — the in-tab chime covers the visible case) but never drops session reminders.

**Sound (PRD 4.9/4.10).** The room header's 🎧 button switches between four scenes — rain, brown noise, cafe, fireplace — all synthesized live with the Web Audio API (noise buffers through filter chains plus randomized clinks/crackles; no audio files, so there is nothing to license), with the choice and volume slider persisted in localStorage. A soft two-note sine chime cues transitions into focus/break — never idle, only on a visible tab — toggleable in settings next to the push opt-in.

The acceptance suite is `apps/web/e2e/schedule.spec.ts`: book a session through the form, RSVP on/off/on, then wait for `sessions:start` to flip the room into focus with the booked config applied (50/10 over the room's 25/5) and the started booking to leave the upcoming list. `ScheduledSessionTest` (14 tests) and `PushTest` (11 tests) pin list scoping, host-only booking, cancellation rules, auto-start idempotency, the reminder's exactly-once window, subscription lifecycle, phase fan-out and 404/410 cleanup against a fake PSR-18 client — browsers cannot receive real Web Push in the test rig, so delivery is proven server-side.

## Milestones

| Milestone | Status |
|---|---|
| 0 — Setup (monorepo, Docker, Reverb ping) | ✅ |
| 1 — Auth, profile, lobby | ✅ |
| 2 — Timer engine | ✅ |
| 3 — Presence and membership | ✅ |
| 4 — Chat, reactions, moderation | ✅ |
| 5 — Focus credit and stats | ✅ |
| 6 — Notifications, schedule, ambient sound | ✅ |
| 7 — Polish and launch | ⬜ |

## Testing

- **API:** `cd apps/api && vendor/bin/phpunit`
- **Web:** `cd apps/web && npm run lint && npx tsc --noEmit`
- **E2E (timer sync + presence + chat/moderation + stats + schedule):** start the local stack (API on :8000, Reverb on :8080, web on :3000, plus `php artisan schedule:work` so the presence reaper and session auto-start run), optionally `cd apps/api && php artisan cache:clear` to reset auth rate limits, then `cd apps/web && npm run test:e2e` (ports overridable with `E2E_API_URL` / `E2E_WEB_URL`)
- **CI:** every push runs lint, Pint, PHPUnit, type check and a production build (`.github/workflows/ci.yml`).
