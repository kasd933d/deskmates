# DeskMates — Product Requirements Document

> Study together, silently. DeskMates is a virtual co-working space: join a room, see who else is focusing right now, share one synced Pomodoro timer, declare what you are working on, cheer each other on, and watch your focus hours add up.

**Version:** 1.0
**Owner:** Abdullah
**Builders:** Abdullah + AI coding agent
**Stack:** Next.js (TypeScript) + Tailwind CSS · Laravel 11 (PHP 8.3) REST API · Laravel Reverb (WebSockets) · MySQL 8 · Web Push (VAPID) · Google OAuth

---

## 1. Why this project

### 1.1 Purpose
Studying or working alone at home is hard to start and easy to abandon. "Body doubling" (working quietly alongside other people) is a proven way to stay focused, but video study streams are noisy, camera-based and awkward. DeskMates keeps the benefit and drops the friction: no camera, no mic, just a shared timer, live presence and small encouragements.

### 1.2 Why it works as a portfolio project
This is the project that proves **real-time** skills, which CommitQuest (API integration) and PupWatch (background jobs) do not.

| Skill | Where it shows up |
|---|---|
| WebSockets | Laravel Reverb, Laravel Echo, presence channels, client events |
| Distributed state | Server-authoritative timer synced across many clients |
| Time and clocks | Client/server clock offset, deterministic phase calculation |
| Concurrency | Idempotent phase transitions, race-safe host transfer, one-room-per-user rule |
| Security | Channel authorization, rate limiting, moderation, abuse prevention |
| Web platform | Web Push notifications, Web Audio, Page Visibility, PWA basics |
| Data modeling | Presence intervals, attendance crediting, daily rollups, streaks |
| Testing | Multi-browser Playwright test proving two users see the same timer |

### 1.3 Goals
1. Everyone in a room sees the same timer within ±1 second.
2. Joining a room and starting to focus takes under 10 seconds.
3. Presence, chat and reactions feel instant (under 300 ms on a normal connection).
4. Focus time is credited fairly and cannot be inflated easily.
5. Safe by default: no video, no audio, rate-limited chat, simple moderation tools.

### 1.4 Non-goals (v1)
- No video or voice chat.
- No file sharing or direct messages.
- No payments or premium tiers.
- No native mobile apps (responsive PWA-style web only).
- No AI features.

---

## 2. Target users

| Persona | Description | Need |
|---|---|---|
| **Student Sana** | University student preparing for exams | Someone "studying with her" to stay off her phone |
| **Remote Rizwan** | Freelance developer working from home | Light accountability and routine |
| **Group Gul** | Runs a study group or cohort | A private room with a shared schedule |

---

## 3. Core concepts

- **Room:** a persistent space with a timer configuration and members.
- **Phase:** one segment of the cycle: `focus`, `short_break`, `long_break` (or `idle` when stopped).
- **Cycle:** sequence of focus and break phases (default 25 / 5, long break 15 every 4 focus phases).
- **Presence:** who is currently in the room, determined by heartbeats.
- **Intention:** a one-line "what I'm working on" shown next to your avatar during focus.
- **Focus session:** credited minutes for a user in one focus phase.
- **Always-on room:** an official room whose timer runs forever on a fixed schedule so anyone can drop in at any time.

### 3.1 Room types

| Type | Who controls the timer | Listed publicly | Max members |
|---|---|---|---|
| **Always-on** (official, seeded) | Nobody; deterministic clock | Yes | 100 |
| **Public** (user created) | Host | Yes | 25 |
| **Private** (user created) | Host | No, invite code only | 12 |

---

## 4. Features

Priority: **P0** = MVP, **P1** = should ship, **P2** = stretch.

### 4.1 Authentication (P0)
- Register/login with email and password (Laravel Sanctum, cookie flow through Next.js).
- Google sign-in via Socialite (P1).
- Display name (required, 2 to 24 chars), avatar color and emoji (chosen from a fixed set, no uploads in v1).
- Guest mode (P1): join a public room with just a nickname; guests can focus and chat but their stats are not saved.
- Account deletion removes all personal data.

**Acceptance criteria**
- Tokens are never readable by browser JavaScript.
- Auth endpoints are rate limited (5 attempts per minute per IP + email).

### 4.2 Rooms (P0)
- Lobby lists public and always-on rooms with live member counts and current phase (e.g. "Focus · 12:41 left").
- Create a room: name, type (public/private), theme, timer configuration, max members (within limits).
- Join by clicking, by slug URL, or by invite code (private).
- **One active room per user:** joining a room automatically leaves the previous one (the old tab shows "You joined another room").
- Host controls (user rooms only): start, pause, skip phase, reset, change timer settings, kick, ban, mute chat, transfer host, close room.
- If the host leaves, host transfers to the member present the longest. If the room is empty for 10 minutes, the timer stops and the room goes `idle`. Empty user rooms are archived after 24 hours.

**Timer configuration limits:** focus 10 to 90 min, short break 3 to 30 min, long break 10 to 45 min, long break every 2 to 6 cycles.

### 4.3 Synced timer (P0)
The server is the only source of truth. Clients only display.

**User-room timer (state-based)**
- `rooms` stores `phase`, `phase_started_at`, `phase_ends_at`, `cycle_index`, `phase_version`.
- Host actions and phase expiry both go through `RoomClockService::transition()`, which increments `phase_version` and broadcasts `RoomPhaseChanged`.
- A delayed job `AdvancePhase(room_id, expected_version)` is queued for the phase end. It does nothing if `phase_version` has changed (idempotent). A fallback also advances lazily if any request finds an expired phase.

**Always-on timer (deterministic)**
- No database state or jobs needed. Phase is a pure function: `phase = f(now, anchor_epoch, config)`. This makes it self-healing after crashes and trivially correct.
- Clients compute locally and a scheduled broadcast confirms phase changes.

**Client sync**
- Every state payload includes `server_now_ms` and `phase_ends_at_ms`.
- Client computes `offset = server_now_ms - client_now_ms` (using the best of several round trips) and renders countdown from `phase_ends_at_ms - (Date.now() + offset)`.
- Client re-syncs on tab visibility change, reconnect, and every 60 seconds.

**Acceptance criteria**
- Two browsers in the same room show timers that differ by no more than 1 second (verified by Playwright test).
- Refreshing, losing connection and reconnecting never shows a wrong time.
- Skipping a phase twice quickly does not double-advance (idempotency test).

### 4.4 Presence (P0)
- Presence UI uses Echo presence channel `presence-room.{id}` for instant join/leave.
- Authoritative presence for crediting uses **heartbeats**: client calls `POST /rooms/{id}/heartbeat` every 20 seconds. A member is considered gone after 60 seconds without a heartbeat (reaper job every 30 s removes stale members and broadcasts leave).
- Each member card shows avatar, name, intention, and a status dot (`focusing`, `on break`, `away`). "Away" appears if the tab has been hidden for over 2 minutes (via Page Visibility API, reported with the heartbeat).

### 4.5 Intentions and check-ins (P0 intention, P1 check-in)
- Before or during a focus phase, set an intention (max 80 chars), visible to the room.
- At the start of each break (P1), prompt: "Did you finish?" → `Yes` / `Partly` / `No`. Stored for personal stats; never shown to others except an aggregate "7 of 12 finished" line in the recap.

### 4.6 Chat (P0)
- Lightweight room chat, text only, max 300 characters.
- Rate limit: 1 message per 2 seconds, 20 per minute per user.
- Basic profanity filter (configurable word list) and link blocking for new accounts under 24 hours old.
- Keeps the last 200 messages per room; older ones are pruned.
- Report message button; host can delete messages, mute or kick users.
- Chat is auto-muted for everyone during focus phases when the host enables "silent focus" (default on for always-on rooms, with only reactions allowed).

### 4.7 Reactions (P0)
- Quick reactions (👏 🔥 💪 ☕ 🎉) float up the screen for everyone.
- Sent as Echo client events (whispers) for low latency; also validated server-side counters are not needed because reactions are ephemeral.
- Client-side throttle: 5 per second; ignore incoming bursts above a limit to protect the UI.
- Respects `prefers-reduced-motion` (shows a small counter instead of animation).

### 4.8 Focus credit and stats (P0)
**Crediting rules**
- Each heartbeat during a `focus` phase adds elapsed time since the previous heartbeat (capped at 45 s) to `phase_attendance.seconds_present`.
- When the phase ends, a job converts attendance to credited minutes: `minutes = floor(seconds_present / 60)`, only if at least 5 minutes.
- Max **12 credited hours per user per day** (in the user's timezone). Breaks are never credited.
- Credited sessions are stored in `focus_sessions`; daily totals roll up into `user_daily_focus`.

**Personal stats page**
- Today / this week / all-time focus minutes.
- Current and longest streak (a day counts with at least 25 credited minutes).
- 12-week heatmap, best day, average session length, favorite hours of the day.
- Task completion rate from check-ins (P1).

**Leaderboards (P1, opt-in)**
- Weekly top 50 by focus minutes (global) and per room. Users can opt out and appear as "Anonymous focuser".

### 4.9 Ambient sound (P1)
- Per-user, local only (no sync needed). Options: rain, brown noise, cafe, fireplace.
- Implemented with the Web Audio API (generated noise) and/or small royalty-free looping audio files placed in `public/audio` (CC0 or properly licensed; document the license in `docs/credits.md`).
- Volume slider, remembered in localStorage.

### 4.10 Notifications (P1)
- Web Push (VAPID) when a phase changes, if the tab is in the background and the user opted in.
- In-tab sound cue (soft chime) with a toggle.
- Push reminder 10 minutes before a scheduled session the user RSVP'd to.
- Push subscriptions are cleaned up automatically when the push service returns 404/410.

### 4.11 Scheduled sessions (P1)
- Host schedules a session (title, start time, timer config) for a private or public room.
- Members RSVP. At start time the room timer starts automatically.
- Listing page `/schedule` with upcoming sessions in the user's timezone.

### 4.12 Moderation and safety (P0)
- Report user/message with reason; reports stored for review (`reports` table, simple admin list page behind a role).
- Host can kick (can rejoin) or ban (cannot rejoin) from their room.
- Global admin can close a room and suspend an account (P1).
- Strict input limits and server-side validation everywhere.
- Community rules shown on first join of any room.

### 4.13 Stretch ideas (P2)
- Discord webhook announcing when a room starts.
- Room themes with animated backgrounds.
- "Study buddy" invites and friend list.
- Weekly recap email.
- Public profile with focus heatmap and badges.
- Redis-backed horizontal scaling of Reverb.

---

## 5. User flows

**First visit:** Landing → "Drop into a room" → pick an always-on room → join as guest or sign up → see timer and members → set intention → focus → chime at break → continue or leave.

**Create a room:** Lobby → New room → set name/type/timer → share link or invite code → friends join → host hits Start → everyone sees the same countdown.

**Session end:** Focus phase ends → timer flips to break for all clients → credit job runs → small toast "+25 min focused" → check-in prompt → stats update.

**Disconnect:** Wi-Fi drops → client shows "Reconnecting…" → Echo reconnects → client re-fetches room state and re-syncs the clock → resumes with the correct time.

---

## 6. Pages (Next.js App Router)

| Route | Purpose | Auth |
|---|---|---|
| `/` | Landing with live count ("1,204 people focusing now") | Public |
| `/login`, `/register` | Auth | Public |
| `/rooms` | Lobby: always-on, public, my rooms | Public (guests may view) |
| `/rooms/new` | Create room | Required |
| `/room/[slug]` | The room: timer, members, chat, reactions, controls | Required or guest |
| `/schedule` | Upcoming sessions and RSVP | Required |
| `/me` | Personal stats and heatmap | Required |
| `/leaderboard` | Weekly leaderboards | Public |
| `/settings` | Profile, timezone, notifications, privacy, delete account | Required |
| `/admin/reports` | Moderation queue | Admin only |

**Room page layout (desktop):** big central timer and phase label; member grid on the side with intentions; chat panel; reaction bar; host control bar (if host); ambient sound button. On mobile: timer on top, tabs for Members / Chat.

**UI requirements**
- Cozy, calm visual style, dark and light themes, minimal motion.
- Timer readable from across the room (large type) and accessible: `aria-live` updates only at phase changes and every minute, not every second.
- WCAG AA contrast; full keyboard operation; status not conveyed by color alone.
- Skeletons, offline banner and reconnect states.

---

## 7. Architecture

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

### 7.1 Processes (each its own container/process)
1. **web:** PHP-FPM or Octane serving the REST API.
2. **reverb:** `php artisan reverb:start` (persistent WebSocket server).
3. **worker:** `php artisan queue:work` (queues: `default`, `phase`, `push`).
4. **scheduler:** `php artisan schedule:work` or cron every minute.
5. **next:** Next.js (Vercel or container).

### 7.2 Channels and events

| Channel | Type | Purpose |
|---|---|---|
| `presence-room.{roomId}` | Presence | Member join/leave, client whispers (reactions) |
| `private-user.{userId}` | Private | Personal events (kicked, credit toasts, push fallback) |
| `lobby` | Public (P2) | Live room counts |

| Event | Channel | Payload |
|---|---|---|
| `RoomPhaseChanged` | room | phase, ends_at_ms, server_now_ms, cycle_index, version |
| `RoomSettingsUpdated` | room | new config, host id |
| `MessageSent` / `MessageDeleted` | room | message id, user, text, time |
| `IntentionUpdated` | room | user id, text |
| `MemberKicked` | user | room id, reason |
| `FocusCredited` | user | minutes, phase id |

All channel subscriptions are authorized in `routes/channels.php` using policies (banned users, full rooms and private rooms without membership are rejected).

### 7.3 Scheduled jobs
| Frequency | Job |
|---|---|
| every 30 s | `ReapStaleMembers` |
| every minute | `StartScheduledSessions`, `SendSessionReminders` |
| every 5 min | `ArchiveEmptyRooms`, `StopIdleTimers` |
| nightly | `RollupDailyFocus`, `PruneOldMessages`, `PruneOldAttendance` |
| on demand | `AdvancePhase` (delayed), `CreditFocusForPhase` |

### 7.4 Suggested repository layout (monorepo)
```
deskmates/
├── apps/
│   ├── web/                       # Next.js
│   │   ├── src/app/...
│   │   ├── src/components/room/   # Timer, MemberGrid, Chat, ReactionBar, HostControls
│   │   ├── src/lib/echo.ts        # Echo client setup + reconnect handling
│   │   ├── src/lib/clock.ts       # server clock offset logic
│   │   └── src/hooks/useRoom.ts
│   └── api/                       # Laravel
│       ├── app/Services/          # RoomClockService, PresenceService, FocusCreditService, ModerationService
│       ├── app/Events/
│       ├── app/Jobs/
│       ├── app/Http/Controllers/Api/
│       ├── app/Policies/
│       ├── routes/channels.php
│       └── tests/
├── docker-compose.yml
├── .github/workflows/ci.yml
├── docs/
└── README.md
```

---

## 8. Data model (MySQL)

All tables: `id` bigint unsigned PK, `created_at`, `updated_at` unless noted.

**users**
`name`, `email` (unique, nullable for guests if persisted), `password`, `google_id` (nullable, unique), `avatar_emoji`, `avatar_color`, `timezone`, `role` (`user`|`admin`), `is_suspended`, `show_on_leaderboard` (default true), `current_streak`, `longest_streak`, `last_focus_date`

**rooms**
`slug` (unique), `name`, `type` (`always_on`|`public`|`private`), `host_id` FK (nullable for always-on), `invite_code` (nullable, unique), `theme`, `max_members`, `focus_minutes`, `short_break_minutes`, `long_break_minutes`, `long_break_every`, `silent_focus` (bool), `phase` (`idle`|`focus`|`short_break`|`long_break`), `phase_started_at`, `phase_ends_at`, `cycle_index`, `phase_version`, `anchor_epoch` (always-on only), `last_active_at`, `archived_at` (nullable)
Indexes: `(type, archived_at)`, `(phase_ends_at)`

**room_members** *(current presence)*
`room_id` FK, `user_id` FK (unique per user: only one active room), `intention` (nullable, 80), `status` (`focusing`|`break`|`away`), `joined_at`, `last_seen_at`, `is_muted`
Unique: `(user_id)`; index `(room_id, last_seen_at)`

**room_phases** *(history)*
`room_id` FK, `type`, `started_at`, `ends_at`, `cycle_index`, `credited_at` (nullable)
Index: `(room_id, started_at)`

**phase_attendance**
`phase_id` FK, `user_id` FK, `seconds_present`. Unique: `(phase_id, user_id)`

**focus_sessions**
`user_id` FK, `room_id` FK, `phase_id` FK, `minutes`, `intention` (nullable), `outcome` (`done`|`partly`|`no`, nullable), `local_date`
Index: `(user_id, local_date)`. Unique: `(user_id, phase_id)`

**user_daily_focus**
`user_id` FK, `date`, `minutes`, `sessions`. Unique: `(user_id, date)`

**messages**
`room_id` FK, `user_id` FK, `body`, `deleted_at`
Index: `(room_id, id)`

**room_bans**
`room_id` FK, `user_id` FK, `banned_by` FK. Unique pair.

**reports**
`reporter_id` FK, `target_user_id` FK, `message_id` (nullable), `room_id` FK, `reason`, `status` (`open`|`reviewed`|`dismissed`)

**scheduled_sessions**
`room_id` FK, `host_id` FK, `title`, `starts_at`, `config` JSON, `status` (`scheduled`|`started`|`cancelled`)

**scheduled_session_rsvps**
`scheduled_session_id` FK, `user_id` FK. Unique pair.

**push_subscriptions**
`user_id` FK, `endpoint` (unique), `p256dh`, `auth`, `user_agent`

---

## 9. REST API

Base path `/api/v1`. JSON only. Error shape `{ "message": string, "errors"?: object }`. Pagination via `?page=`, `?per_page=` (max 50).

### Auth and profile
| Method | Path | Description |
|---|---|---|
| POST | `/auth/register`, `/auth/login`, `/auth/logout` | Standard |
| GET | `/auth/google/redirect`, `/auth/google/callback` | Google sign-in (P1) |
| GET/PATCH/DELETE | `/me` | Profile, update, delete account |
| POST | `/guest` | Create guest session (P1) |

### Rooms
| Method | Path | Description |
|---|---|---|
| GET | `/rooms` | Lobby list with counts and current phase |
| POST | `/rooms` | Create room |
| GET | `/rooms/{slug}` | Room state incl. `server_now_ms` |
| PATCH | `/rooms/{id}` | Update settings (host) |
| POST | `/rooms/{id}/join` | Join (body: `invite_code` for private) |
| POST | `/rooms/{id}/leave` | Leave |
| POST | `/rooms/{id}/heartbeat` | Keep presence alive (body: `visible` bool) |
| PATCH | `/rooms/{id}/intention` | Set intention |
| POST | `/rooms/{id}/timer/{action}` | `start`, `pause`, `skip`, `reset` (host) |
| POST | `/rooms/{id}/members/{userId}/kick` | Kick (host) |
| POST | `/rooms/{id}/members/{userId}/ban` | Ban (host) |
| POST | `/rooms/{id}/host/transfer` | Transfer host |
| DELETE | `/rooms/{id}` | Close room (host) |
| GET | `/time` | Lightweight clock sync endpoint |

### Chat
| Method | Path | Description |
|---|---|---|
| GET | `/rooms/{id}/messages` | Recent messages |
| POST | `/rooms/{id}/messages` | Send (rate limited) |
| DELETE | `/rooms/{id}/messages/{mid}` | Delete (host/admin/author) |
| POST | `/reports` | Report user or message |

### Stats
| Method | Path | Description |
|---|---|---|
| GET | `/me/stats` | Totals, streaks |
| GET | `/me/heatmap` | Last 12 weeks |
| PATCH | `/me/sessions/{id}/outcome` | Check-in answer |
| GET | `/leaderboard?scope=weekly&room=` | Leaderboard (opt-in users only) |

### Schedule and push
| Method | Path | Description |
|---|---|---|
| GET/POST | `/sessions` | List upcoming, create |
| POST/DELETE | `/sessions/{id}/rsvp` | RSVP / cancel |
| POST/DELETE | `/push/subscribe` | Register / remove push subscription |

### Admin
| Method | Path | Description |
|---|---|---|
| GET | `/admin/reports` | List reports |
| PATCH | `/admin/reports/{id}` | Review/dismiss |
| POST | `/admin/rooms/{id}/close` | Close room |
| POST | `/admin/users/{id}/suspend` | Suspend user |

**Rate limits:** 60 req/min per user, heartbeats exempt (1 per 15 s minimum enforced), messages 20/min, auth 5/min, `/time` 30/min.

---

## 10. Real-time correctness rules

1. **Server authority:** clients never decide the phase; they render what the server sends.
2. **Versioning:** every `RoomPhaseChanged` carries `version`. Clients ignore events older than the version they hold.
3. **Idempotent transitions:** `transition()` runs in a DB transaction with `SELECT ... FOR UPDATE` on the room row and checks `expected_version`.
4. **Reconnect:** on reconnect the client always refetches `GET /rooms/{slug}` before trusting events.
5. **Event ordering:** broadcast after commit (`ShouldBroadcast` with `afterCommit`).
6. **Clock offset:** use the median of 3 samples of `GET /time` to reduce network noise; recompute on resume from background.
7. **Single room rule:** enforced by the unique index on `room_members.user_id` and handled in a transaction (join = upsert + broadcast leave to old room).

---

## 11. Non-functional requirements

| Area | Requirement |
|---|---|
| Latency | Phase change reaches 95% of clients within 500 ms of the server transition |
| Capacity | One small instance supports 500 concurrent connections and 5 active rooms of 100 members |
| Timer accuracy | Displayed time within ±1 s of server time |
| Reliability | Rooms recover timer state after worker or Reverb restart with no manual action |
| Security | Channel auth on every private/presence channel, validated input, strict CORS, rate limits, encrypted push keys, no secrets in repo |
| Privacy | No video/audio, minimal PII, opt-out of leaderboard, account deletion removes sessions and messages |
| Accessibility | WCAG AA, reduced-motion support, screen-reader friendly timer announcements |
| Observability | Structured logs, failed-jobs table, health endpoint, Reverb connection count metric |
| Performance | Room page interactive in under 3 s on 4G; chat render virtualized beyond 200 messages |

---

## 12. Constraints and assumptions

- **WebSocket hosting:** serverless platforms (including Vercel functions) cannot host Reverb. Host Reverb with the API on a container/VPS (Railway, Fly.io, Render, small VPS) behind HTTPS/WSS. Next.js can still live on Vercel.
- Free tiers may sleep; document that always-on rooms and Reverb need an always-running instance.
- Single Reverb node in v1 (no Redis scaling). Document the Redis upgrade path.
- Database queue and cache drivers in v1.
- All timestamps in UTC; day boundaries use the user's timezone.
- Browser support: latest Chrome, Edge, Firefox, Safari (Web Push on iOS requires installing the PWA to the home screen; document this limitation).
- Scope shippable in about 5 to 7 weeks of part-time work.

---

## 13. Testing strategy

**Backend (Pest or PHPUnit)**
- Unit: `RoomClockService` (deterministic always-on function at phase boundaries and every Nth long break; transition idempotency; version checks), attendance crediting math and daily cap, streak calculation across timezones.
- Feature: all endpoints with policies (banned, full, private rooms), one-room-per-user rule, host transfer, kick/ban, rate limits, broadcast assertions using `Event::fake()`, channel authorization tests.
- Concurrency test: two simultaneous `skip` requests advance the phase only once.

**Frontend**
- Component/unit tests: clock offset calculation, countdown formatting, reducer handling out-of-order events.
- **Playwright multi-context test:** two browser contexts join one room; host starts the timer; assert both timers match within 1 second; host skips; both flip phase; one context goes offline and back and recovers the right time.

**CI (GitHub Actions)**
- Lint (ESLint, Pint), type check, backend tests, frontend tests, Playwright smoke test, build.

**Definition of done for any task:** merged, tests passing, lint clean, no console errors, docs updated if behavior changed.

---

## 14. Build plan (milestones)

### Milestone 0 — Setup (2 days)
Monorepo, Laravel + Next.js scaffolds, Reverb installed and running in Docker Compose (api, reverb, worker, scheduler, web, mysql), env examples, CI skeleton, a "hello realtime" ping event proving WebSocket round trip.
**Done when:** a message broadcast from Laravel appears live in the Next.js page.

### Milestone 1 — Auth, profile, lobby (3–4 days)
Register/login via cookie flow, profile with avatar emoji/color, room CRUD, lobby list, seeded always-on rooms.
**Done when:** a user can sign up, see the lobby and open a room page shell.

### Milestone 2 — Timer engine (5–7 days)
`RoomClockService`, deterministic always-on clock, state-based host-controlled timer, `AdvancePhase` delayed job, `GET /time`, client `clock.ts`, big synced countdown, `RoomPhaseChanged` events, reconnect logic.
**Done when:** the Playwright two-browser sync test passes, including skip and reconnect cases.

### Milestone 3 — Presence and membership (4–5 days)
Join/leave, one-room rule, heartbeats, stale reaper, presence channel and member grid, intentions, host transfer, idle/empty room handling.
**Done when:** members appear/disappear live, closing a tab removes the member within 60 seconds, and joining a second room kicks the first.

### Milestone 4 — Chat, reactions, moderation (4–5 days)
Messages with rate limits and filters, silent focus mode, reactions via whispers, kick/ban/mute, reports and admin queue.
**Done when:** chat and reactions work across multiple clients and abuse tools function end to end.

### Milestone 5 — Focus credit and stats (4–5 days)
Phase history, attendance, credit job, daily cap, rollup, streaks, stats page, heatmap, check-in prompt, opt-in leaderboard.
**Done when:** a full 25-minute (or shortened test) session credits the expected minutes and shows in stats.

### Milestone 6 — Notifications, schedule, ambient sound (4–5 days)
Web Push, scheduled sessions with RSVP and auto-start, reminders, ambient sound player, notification settings.
**Done when:** a scheduled session starts automatically and RSVP'd users receive a push reminder.

### Milestone 7 — Polish and launch (4–5 days)
Landing page with live count, accessibility pass, mobile layout, rate-limit review, security review, load test with a script simulating 200 connections, seed data, deployment, README with architecture diagram, screenshots and demo GIF.
**Done when:** live on a public URL, README explains the timer sync design and real-time correctness rules.

### Milestone 8 — Stretch (optional)
Discord announcements, themes, friends, weekly recap, Redis scaling.

---

## 15. Instructions for the coding agent

1. Read this whole document first. It is the source of truth; when ambiguous, choose the simplest option that meets the acceptance criteria and log it in `docs/decisions.md`.
2. Work milestone by milestone. Do not start the next until "Done when" is true.
3. Build and test the timer engine (`RoomClockService`, `clock.ts`) **before** any room UI polish.
4. Never trust the client for time, phase, host rights, membership or credited minutes.
5. Every state-changing action must be authorized by a Policy and broadcast **after commit**.
6. Keep controllers thin; logic in `app/Services`. Use Form Requests, API Resources, Policies.
7. Heartbeat and reaper logic must be covered by tests; credit calculations must have unit tests including the daily cap and 5-minute minimum.
8. Use UTC in storage; convert for day boundaries only with the user's timezone.
9. Do not store secrets, push keys or tokens in the repo; use env vars.
10. TypeScript strict mode; shared event/payload types in `apps/web/src/types`.
11. Small conventional commits (`feat:`, `fix:`, `test:`, `docs:`, `chore:`); update README after each milestone.

### Environment variables

**API (`apps/api/.env.example`)**
```
APP_URL=
FRONTEND_URL=
DB_HOST= DB_PORT= DB_DATABASE= DB_USERNAME= DB_PASSWORD=
QUEUE_CONNECTION=database
CACHE_STORE=database
BROADCAST_CONNECTION=reverb
REVERB_APP_ID= REVERB_APP_KEY= REVERB_APP_SECRET=
REVERB_HOST= REVERB_PORT=8080 REVERB_SCHEME=https
SANCTUM_STATEFUL_DOMAINS=
GOOGLE_CLIENT_ID= GOOGLE_CLIENT_SECRET= GOOGLE_REDIRECT_URI=
VAPID_PUBLIC_KEY= VAPID_PRIVATE_KEY= VAPID_SUBJECT=
```

**Web (`apps/web/.env.example`)**
```
NEXT_PUBLIC_APP_URL=
API_BASE_URL=
NEXT_PUBLIC_REVERB_APP_KEY=
NEXT_PUBLIC_REVERB_HOST=
NEXT_PUBLIC_REVERB_PORT=
NEXT_PUBLIC_REVERB_SCHEME=https
NEXT_PUBLIC_VAPID_PUBLIC_KEY=
```

---

## 16. Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Timer drift or desync | Core promise broken | Server authority, versioning, offset sync, Playwright sync test |
| Delayed jobs fire late | Phase changes lag | Lazy advance on read, deterministic always-on clock, monitor queue latency |
| WebSocket hosting limits | Cannot deploy | Choose container host early (Milestone 0), document constraints |
| Chat abuse and spam | Unsafe community | Rate limits, filters, silent focus, reports, kick/ban, new-account link block |
| Focus-time cheating | Unfair leaderboards | Heartbeat-based credit, daily cap, opt-in leaderboard, low stakes |
| Reconnect storms | Server overload | Backoff with jitter in the client, connection limits per user |
| Web Push on iOS limits | Missed reminders | Document PWA install requirement; in-tab sound as fallback |
| Scope creep | Never ships | P0 first; stretch only after launch |

---

## 17. Success metrics

**Portfolio goals**
- Deployed with a public URL, README, architecture diagram, screenshots and demo GIF (show two browser windows synced).
- Automated two-browser sync test in CI.
- 80%+ test coverage on service classes.
- Load test results documented (for example 200 concurrent connections, p95 event latency).

**Product goals (if shared)**
- 100 sign-ups; average session at least 25 minutes.
- 40% of users return on 3+ days in the first two weeks.
- At least 5 rooms with 5+ simultaneous members during peak hours.

---

## 18. Resume copy (use after you build it)

**DeskMates — Real-time virtual co-working rooms** · Next.js, TypeScript, Tailwind, Laravel, Laravel Reverb (WebSockets), MySQL, Web Push
- Built a full-stack real-time study platform where users share synchronized Pomodoro timers, live presence, chat and reactions, using Laravel Reverb, presence channels and Laravel Echo.
- Designed a server-authoritative timer with versioned, idempotent phase transitions and client clock-offset correction, keeping all participants within one second; verified with a multi-browser Playwright test in CI.
- Created a deterministic, stateless timer for always-on rooms, removing the need for database state or scheduled jobs and making rooms self-healing after restarts.
- Implemented heartbeat-based presence, attendance tracking and anti-abuse rules (rate limits, daily caps, moderation tools) to credit focus time fairly.
- Added Web Push reminders, scheduled sessions with RSVP, and a stats dashboard with streaks and heatmaps; containerized services and deployed to production.

---

## 19. Glossary

- **Body doubling:** working quietly alongside others to stay focused.
- **Presence channel:** a WebSocket channel that tells clients who is currently subscribed.
- **Whisper / client event:** a message sent directly between clients through the socket server without hitting the application server.
- **Idempotent:** safe to run multiple times with the same result.
- **Heartbeat:** a small periodic request proving a client is still present.
- **Clock offset:** the difference between the server's clock and the client's clock.
