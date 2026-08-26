# Setup

Local install on Windows, native PHP (no Docker for PHP — see rationale below).
Postgres and Redis run in Docker. This file is the only thing a wiped machine
needs.

## 1. Prerequisites

- **PHP 8.4** via winget: `winget install PHP.PHP.8.4`. Winget's 8.3 package
  404s (php.net archived the download) — 8.4 is what this project runs on,
  not the 8.3 the original plan assumed. Enable extensions by hand-editing
  `php.ini` (copy from `php.ini-development` in the winget package dir:
  `%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe`).
  Required extensions: `pdo_pgsql`, `pgsql`, `redis` is not needed — this
  project uses `predis` (pure-PHP), not the `phpredis` extension, since
  `phpredis` has no practical Windows build.
- **Composer** — installs normally on Windows, no workaround needed.
- **Docker Desktop** — for Postgres + Redis only. Add
  `C:\Program Files\Docker\Docker\resources\bin` to `PATH` if `docker` isn't
  found; without the bundled credential helper on `PATH`, image pulls fail
  with an auth error.
- **Node.js** — for Vite (`npm run dev` / `npm run build`).
- Why native PHP instead of Docker-for-everything: the repo lives on a
  network/secondary drive; running PHP through WSL over a mounted path made
  Composer and Artisan several times slower. Only the stateful services
  (Postgres, Redis) are containerised.

## 2. Install

```
composer install
npm install
copy .env.example .env
php artisan key:generate
```

`composer.json`'s `config.platform` fakes `ext-pcntl` and `ext-posix` so
Horizon installs on Windows. Horizon itself still won't run locally — use
`php artisan queue:work` for local queue processing. Horizon is for the Linux
server only.

## 3. Start Postgres + Redis

```
docker compose up -d
```

Uses `pgvector/pgvector:pg16`, not stock `postgres:16` — the pgvector
extension has to be present in the image on Windows, since installing it into
a running container is unreliable there.

## 4. Environment variables

### Required now

| Variable | Notes |
|---|---|
| `APP_KEY` | set by `php artisan key:generate` |
| `APP_URL` | `http://lms.test` locally |
| `DB_CONNECTION` / `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | must match `docker-compose.yml`; defaults already do |
| `SESSION_DOMAIN` | `.lms.test` — cookie must be shared across every tenant subdomain |
| `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` | `redis` |
| `REDIS_CLIENT` | `predis` — deliberate, not a leftover; no Windows `phpredis` build |
| `REDIS_HOST` / `REDIS_PORT` | match `docker-compose.yml` |

A second database, `lms_test`, is needed for the test suite (`phpunit.xml`
points at Postgres, not SQLite — the isolation suite has to run against the
engine production uses). `docker/postgres/init/01-extensions.sql` only runs
against the default `lms` database on first container init, so create
`lms_test` and its extensions by hand:

```
docker exec lms_postgres createdb -U lms lms_test
docker exec lms_postgres psql -U lms -d lms_test -c "CREATE EXTENSION IF NOT EXISTS vector; CREATE EXTENSION IF NOT EXISTS pg_trgm;"
```

### Not needed until their slice

None of these packages are installed yet — no code reads these variables
today. Listed here so the slice that needs one knows what to configure.

| Variable | Provider | Needed from | Signup / setup |
|---|---|---|---|
| `BUNNY_STORAGE_KEY`, `BUNNY_STORAGE_ZONE`, `BUNNY_CDN_KEY` | Bunny Storage + CDN (video, files, nightly backups) | S2 | Create a Bunny account, a Storage Zone, and a Pull Zone; one API key covers both storage and CDN. |
| `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET` | Razorpay (fees, instalments, GST invoicing) | S7 | Register a Razorpay business account, complete KYC, generate API keys from the dashboard, register the webhook URL. |
| `WHATSAPP_BSP_TOKEN`, `WHATSAPP_BSP_PHONE_ID` | WhatsApp BSP — AiSensy or Interakt (parent nudges, comms) | S8, but **apply for approval immediately** — external and slow, do not wait for S8 | Sign up with the chosen BSP, verify a business phone number with Meta, wait for template + number approval. |
| `ANTHROPIC_API_KEY` | Claude API — Haiku 4.5 / Sonnet 5 / Opus 5, routed per task | S3 | Create an Anthropic Console account, generate an API key, set a spend cap. |
| `LIVE_CLASS_PROVIDER`, and Zoom Video SDK credentials if that provider is selected | `ExternalLinkProvider` (day one — teacher pastes their own Zoom/Meet link, no credentials needed) → Zoom Video SDK later | S5 | Day one: nothing to configure. Zoom Video SDK step-up: register a Zoom Video SDK app (not a regular Zoom account — no host licences needed) and set the SDK key/secret. |
| `SENTRY_LARAVEL_DSN` | Sentry (error monitoring, from launch) | any time before first real deploy | Create a Sentry project, copy the DSN. Telescope covers local/dev in the meantime. |

## 5. Hosts file entries

Tenant routing is subdomain-based (`{subdomain}.lms.test`) and Windows has no
wildcard-hosts support, so each tenant used locally needs its own line in
`C:\Windows\System32\drivers\etc\hosts` (requires Administrator):

```
127.0.0.1 lms.test
127.0.0.1 physics.lms.test
127.0.0.1 chemistry.lms.test
```

Add one line per tenant subdomain actually used for local testing.

## 6. Migrate and run

```
php artisan migrate
php artisan db:seed
composer dev
```

`composer dev` runs the server, queue worker, log tail, and Vite together.
Visit `http://lms.test` for the central app, `http://{subdomain}.lms.test`
for a tenant.

## 6a. Real-time (S19 N11 — Reverb)

`BROADCAST_CONNECTION` stays `log` for normal local dev and for the test
suite — no server needed for everyday work. To actually see real-time push
(direct messages, forum posts, help-desk replies) locally: set
`BROADCAST_CONNECTION=reverb` in `.env`, then run the WebSocket server in
its own terminal:

```
php artisan reverb:start
```

Reverb is Linux/Docker-first upstream; it runs on native Windows PHP but
needs this dedicated long-lived process (no `ext-pcntl` signal handling to
rely on here, same faked-extension situation `composer dev`'s queue worker
already lives with) — not part of `composer dev`, start it separately when
needed.

## 7. Run tests

```
composer test
```

Runs `php artisan config:clear` then `php artisan test` (Pest). Needs the
`lms_test` database from step 4.

## 8. Server hardening (production)

- SSH: key-only auth. Disable password login (`PasswordAuthentication no` in
  `sshd_config`), disable root login (`PermitRootLogin no`).
- Firewall: allow only 22 (SSH), 80 (HTTP), 443 (HTTPS). Deny everything else
  by default.
