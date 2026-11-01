# DeskMates — decisions log

Per the PRD (section 15.1): when the PRD is ambiguous, choose the simplest option that meets the acceptance criteria and log it here.

## 2026-10-31 — Milestone 0

- **Laravel 13 instead of 11.** The PRD specifies Laravel 11, but every 11.x release is currently flagged by Composer's security-advisory blocking (Composer 2.11 refuses to install them). Laravel 13.11 is the current stable release, supports PHP 8.3, and the architectural requirements of the PRD (Reverb, Sanctum, queues, policies, broadcasts) are unchanged. Recorded here as required by the PRD instructions.
- **`guzzlehttp/psr7` downgraded to 2.x.** `laravel/reverb` 1.12 requires `guzzlehttp/psr7 ^2.6`; the Laravel 13 skeleton ships v3. Resolved with `composer require laravel/reverb -W`.
- **Local PHP toolchain.** PHP 8.3.36 (NTS, windows.php.net zip) + Composer 2.11 installed to `~/.deskmaster-tools` because no system package manager (winget/scoop/choco) is available on the dev machine. Docker remains the runtime for MySQL and the full service stack.
- **`php artisan serve` for the API container.** The PRD's process list only requires an HTTP process for the REST API; using PHP's built-in server keeps the compose file free of nginx/php-fpm for v1. Swap for Octane/nginx before a production deploy.
- **Vendor via named Docker volume.** `apps/api/vendor` is a named volume (`api-vendor`) so the Linux containers never see the Windows-host vendor tree; `docker/php/entrypoint.sh` runs `composer install` on first boot. The bind mount keeps the rest of the app live-editable.
- **Reverb app credentials.** `REVERB_APP_KEY=deskmateskey` etc. are fixed, non-secret dev values shared by `.env.example`, compose and CI. Real deployments override via env. `APP_KEY` is still generated per machine and never committed.
- **CORS with credentials.** `config/cors.php` allows exactly `FRONTEND_URL` (default `http://localhost:3000`) with `supports_credentials = true` so the Milestone 1 Sanctum cookie flow works cross-origin. A wildcard origin cannot be combined with credentials.
- **npm, not pnpm.** The dev machine has npm 11; the PRD does not mandate a package manager. The monorepo is "folder-based" (no workspace tooling) for v1 — `apps/web` and `apps/api` are independent projects.
- **"Hello realtime" ping.** `POST /api/v1/ping` broadcasts `PingReceived` on the public `ping` channel; the landing page subscribes with Laravel Echo and shows each broadcast with its round-trip time. Public channel (no auth) is enough for Milestone 0; presence/private channel auth arrives with Milestones 3–4.

## 2026-11-01 — Milestone 1

- **Sanctum SPA cookie flow, not tokens.** `Sanctum::statefulApi()` + session cookies (with `credentials: "include"` and the XSRF-TOKEN round-trip in `apps/web/src/lib/api.ts`). The PRD targets a first-party web app on the same product domain; personal access tokens would add complexity with no benefit. Google OAuth (M7) reuses the same session guard.
- **One active room per user, enforced by schema.** `room_members.user_id` has a unique index; joining another room moves the membership row (documented in `RoomTest::test_joining_a_room_moves_the_member_from_their_previous_room`). Simplest way to guarantee presence correctness later (M3).
- **Always-on rooms are immutable to everyone, including admins.** `RoomPolicy` returns false for update/close on `always_on` rooms before any role check — their timers are pure functions of `(now, anchor_epoch, config)`; an admin edit would desynchronize every client. Admins moderate *users and messages*, not the deterministic clock.
- **Always-on room membership does not block joining elsewhere.** PRD §"rooms" treats official rooms as drop-in/drop-out; only host-created rooms occupy the unique `room_members` slot. (Decided from the PRD's "drop into an always-on room" lobby copy.)
- **Invite code validated in the controller, not the policy.** `RoomPolicy::join` only checks archived state; the private-room invite code is validated in `RoomController::join` so the failure is a 422 field error the form can render inline, not a 403.
- **`member_count` from `withCount`, handled in the resource.** Lobby queries use `withCount('members')` (a `members_count` attribute) while room pages eager-load `members`; `RoomResource` serializes `member_count` from whichever is present. Caught live in the browser: lobby cards initially showed `0` while the room page showed the real count.
- **SQLite for local dev and tests, MySQL 8 in compose/CI.** No Docker on the dev machine, and PHPUnit runs on `:memory:` SQLite. All queries avoid MySQL-only functions (`FIELD()` replaced with a portable `CASE` ordering) so dev and CI agree.
- **laravel-echo 2.x generics.** `Echo<"reverb">` — the `Echo` type parameter is a `keyof Broadcaster` (reverb/pusher/…), not the channel visibility literal from echo 1.x.
- **React Compiler lint (`react-hooks/set-state-in-effect`).** Web code follows the async-effect pattern (`useEffect` + cancellation flag; state set only in promise callbacks) and the keyed-child pattern for form state seeded from props (settings page), which the rule enforces.
