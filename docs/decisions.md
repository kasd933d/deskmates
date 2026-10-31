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
