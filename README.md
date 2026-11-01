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

## Milestones

| Milestone | Status |
|---|---|
| 0 — Setup (monorepo, Docker, Reverb ping) | ✅ |
| 1 — Auth, profile, lobby | ✅ |
| 2 — Timer engine | ✅ |
| 3 — Presence and membership | ⬜ |
| 4 — Chat, reactions, moderation | ⬜ |
| 5 — Focus credit and stats | ⬜ |
| 6 — Notifications, schedule, ambient sound | ⬜ |
| 7 — Polish and launch | ⬜ |

## Testing

- **API:** `cd apps/api && vendor/bin/phpunit`
- **Web:** `cd apps/web && npm run lint && npx tsc --noEmit`
- **E2E (timer sync):** start the local stack (API on :8000, Reverb on :8080, web on :3000), optionally `cd apps/api && php artisan cache:clear` to reset auth rate limits, then `cd apps/web && npm run test:e2e`
- **CI:** every push runs lint, Pint, PHPUnit, type check and a production build (`.github/workflows/ci.yml`).
