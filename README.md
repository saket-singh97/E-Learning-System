# AI-Native LMS for Coaching Institutes

A multi-tenant Learning Management System built for Indian coaching
institutes — course delivery, live classes, assessments, fees, parent
communication, and an AI doubt-chat that's grounded in each institute's own
lecture content, not a generic chatbot bolted on top.

Each institute gets its own branded subdomain (`{institute}.lms.test` in
dev), its own admin/instructor/student surfaces, and fully isolated data —
one codebase, many tenants.

## Status

**S1 through S10 complete, S11 designed (build pending).** Full detail —
what's done, what's in progress, what to avoid — lives in
[`HANDOVER.md`](HANDOVER.md), the file every working session on this repo
starts by reading. Slice-by-slice history and test counts:
[`docs/PROJECT-STATUS.md`](docs/PROJECT-STATUS.md).

| Slice | Covers |
|---|---|
| S1 | Multi-tenancy, auth, roles, branding, cross-tenant isolation |
| S2 | Course → section → lesson content, video streaming, watch progress |
| S3 | AI spine — lecture ingestion pipeline, doubt chat grounded per-lecture |
| S4 | Batches, enrolment, waitlists, attendance |
| S5 | Live classes (Zoom SDK integration, session lifecycle) |
| S6 | Assessments — quiz builder, timed attempts, auto-grading |
| S7 | Fees — Razorpay payments, coupons, instalments, GST invoices |
| S8 | Comms — WhatsApp notifications, parent portal, parent-student linking |
| S9 | Hardening — 2FA, Google OAuth, AI spend caps, bandwidth caps, audit trail |
| S10 | Assignments, weighted gradebook, auto-issued certificates + verification |
| S11 | Plan tiers + institute billing (Razorpay subscriptions) — design done, not built |

## Tech stack

- **Backend:** Laravel 13, PHP 8.4
- **Admin/instructor UI:** Filament 5 (three panels — Super, Admin, Teach)
- **Student-facing UI:** Livewire, Blade
- **Database:** PostgreSQL (+ `pgvector` for lecture embeddings)
- **Queue/cache:** Redis
- **Payments:** Razorpay · **Video delivery:** Bunny CDN · **Live classes:** Zoom SDK
- **Comms:** WhatsApp (AiSensy)

## Architecture

Code is organised **by domain, not by layer** — `app/Domains/{Domain}/`,
each carrying the same `Models/Services/Filament/Livewire/Views/Jobs/
Policies/Events/` shape. Domains talk to each other through events only.

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — system map: surfaces, domains, data flow
- [`DECISIONS.md`](DECISIONS.md) — why things are built the way they are
- [`FLOW.md`](FLOW.md) — how a request travels across domains
- [`CLAUDE.md`](CLAUDE.md) — conventions and gotchas enforced by the codebase/tests

## Getting started

Full setup (PHP, Postgres, Redis, environment variables, hosts file
entries): [`SETUP.md`](SETUP.md).

```bash
composer install
cp .env.example .env
php artisan key:generate
docker compose up -d          # Postgres + Redis
php artisan migrate --seed
php artisan serve
```

## Testing

```bash
composer test
```

Runs `php artisan config:clear` then the full Pest suite (~870 tests),
including a generated cross-tenant isolation suite that runs once per
tenant-scoped model — see `CLAUDE.md` → "the tenant model registry rule."

## Docs map

| File | What's in it |
|---|---|
| `HANDOVER.md` | Live snapshot — read this first, every session |
| `docs/PROJECT-STATUS.md` | Slice-by-slice build history, plain English |
| `docs/FEATURES.md` | Full feature backlog (P1-P3), source of every slice's scope |
| `docs/superpowers/specs/` | Per-slice design + block-by-block implementation plans |
| `docs/bugs/`, `docs/features/` | Per-item trace files |
| `ARCHITECTURE.md` / `DECISIONS.md` / `FLOW.md` / `CLAUDE.md` | System map, rationale, request flow, conventions |
| `SETUP.md` | Local environment setup |

## License

Private project. Not for redistribution.
