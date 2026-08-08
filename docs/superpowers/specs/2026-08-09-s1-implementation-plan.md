# S1 Foundation — Implementation Plan

Date: 2026-08-09
Source design: `2026-08-08-s1-foundation-design.md`
Target demo: 2026-08-22
Status: READY TO EXECUTE

---

## PROGRESS — read this first, update it last

One session per block. At the end of a block: tick its row, commit, close the
session. The next session reads this table and knows exactly where to start.

| # | Block | Status | Commit | Notes |
|---|---|---|---|---|
| 0 | Prerequisites (PHP 8.3, Composer, Docker) | ☐ not started | — | Herd + Docker Desktop |
| 1 | Scaffold | ☐ not started | — | |
| 2 | Domain skeleton | ☐ not started | — | |
| 3 | Tenancy core | ☐ not started | — | highest risk |
| 4 | Isolation suite + CI | ☐ not started | — | |
| 5 | Auth | ☐ not started | — | |
| 6 | Roles | ☐ not started | — | |
| 7 | Filament panels | ☐ not started | — | |
| 8 | Student shell + branding | ☐ not started | — | |
| 9 | Content domain | ☐ not started | — | |
| 10 | `lesson_artifacts` contract | ☐ not started | — | parallel, any time |
| 11 | Security baseline | ☐ not started | — | |
| 12 | Docs | ☐ not started | — | |

Status values: `☐ not started` · `◐ in progress` · `☑ done`

**Session opener, copy this:**

```
Block N chalu karo — docs/superpowers/specs/2026-08-09-s1-implementation-plan.md
```

Nothing else. No `/context-restore`, no skills. This file carries the state.

**Session closer:** tick the row, write the commit hash in, one commit per block
with the message `feat(s1): block N — <name>`. Then close.

---

Twelve blocks, in dependency order. Each block ends in a **Verify** step that
either passes or does not. Do not start a block until the one it depends on
verifies.

Phases 1 to 3 of the normal spec interrogation are already answered by the source
design: who it is for, what changes, scope in and out, the schema, the named
errors, and the test plan. This document is only the execution sequence.

---

## Block 1 — Scaffold

**Depends on:** nothing.

1. `composer create-project laravel/laravel .` in the project root
2. Add `docker-compose.yml`: `postgres:16` with the `pgvector` extension, and
   `redis:7`
3. `.env`: `DB_CONNECTION=pgsql`, Redis for `CACHE_STORE`, `QUEUE_CONNECTION`,
   `SESSION_DRIVER`
4. Install: `livewire/livewire`, `filament/filament`, `spatie/laravel-permission`,
   `laravel/sanctum`, `laravel/horizon`, `pestphp/pest`
5. `php artisan migrate` against the container

**Verify:** `php artisan about` reports PostgreSQL and Redis connected.
`docker compose up -d` then `php artisan tinker` → `DB::select('select version()')`
returns PostgreSQL 16.

---

## Block 2 — Domain skeleton

**Depends on:** Block 1.

1. Create `app/Domains/Tenancy/` and `app/Domains/Content/`, each with the eight
   subfolders: `Models/`, `Services/`, `Filament/`, `Livewire/`, `Views/`,
   `Jobs/`, `Policies/`, `Events/`
2. Create `app/Integrations/` and `app/Support/{Tenancy,CircuitBreaker,Cache}/`
3. `composer.json` autoload: map `App\Domains\` to `app/Domains/`, then
   `composer dump-autoload`
4. In `AppServiceProvider::boot()`, loop over the domain directories and call
   `View::addNamespace($domain, app_path("Domains/$domain/Views"))`
5. Point each Filament panel provider at its domain paths via
   `discoverResources()`

**Verify:** a throwaway Blade file at `app/Domains/Tenancy/Views/ping.blade.php`
renders through `view('tenancy::ping')`. Delete it after.

---

## Block 3 — Tenancy core

**Depends on:** Block 2. **This is the block everything else inherits.**

1. Migration `tenants`: `id`, `name`, `subdomain` (unique), `status`
   enum(active, suspended), `logo_path`, `favicon_path`, `primary_color`,
   `accent_color`, timestamps
2. `app/Domains/Tenancy/Models/Tenant.php`
3. `app/Support/Tenancy/CurrentTenant.php` — a container singleton
4. `app/Support/Tenancy/BelongsToTenant.php` trait:
   - `booted()` adds a global scope filtering on `tenant_id`
   - `creating` fills `tenant_id` from `CurrentTenant`
   - **no tenant bound → throw `MissingTenantContextException`.** Never write null
5. `app/Http/Middleware/ResolveTenant.php` — subdomain lookup, binds
   `CurrentTenant`, throws `TenantNotFoundException` on miss, and on
   `status = suspended` flushes the session and returns 403
6. Named exceptions in `app/Exceptions/`: `TenantNotFoundException`,
   `TenantSuspendedException`, `MissingTenantContextException`,
   `PrivilegeEscalationException`, `InvalidFileTypeException`
7. Job middleware that rebinds `CurrentTenant` from a job's carried `tenant_id`,
   and fails the job loudly when it is absent

**Verify:** two tenants seeded. Hitting `physics.lms.test` binds tenant A;
`chemistry.lms.test` binds tenant B; `nope.lms.test` returns 404. A model created
with no tenant bound throws rather than writing null.

---

## Block 4 — Isolation suite and CI

**Depends on:** Block 3. Do this **before** more models exist, so the registry
habit forms while there are only two.

1. `app/Support/Tenancy/TenantModelRegistry.php` — an explicit array of every
   tenant-owned model class
2. `tests/Isolation/CrossTenantTest.php` — iterates the registry. For each model:
   `all()` excludes the other tenant, `find()` returns null, `update()` affects 0
   rows, the API route 404s, the Filament table excludes it, the Filament edit
   page 404s, and `create()` with no tenant throws
3. A guard test that fails when a class using `BelongsToTenant` is missing from
   the registry (reflect over `app/Domains/*/Models/`)
4. `.github/workflows/ci.yml` — Postgres and Redis services, `composer install`,
   `php artisan migrate`, `./vendor/bin/pest`

**Verify:** suite green. Then hand-edit one seeded row's `tenant_id` and confirm
the suite goes red. Then add a model without registering it and confirm the guard
test fails. Revert both.

---

## Block 5 — Auth

**Depends on:** Block 3.

1. `users` migration: `tenant_id` nullable FK, `name`, `email`, `password`,
   `email_verified_at`, `remember_token`, **unique `(tenant_id, email)`**
2. Laravel Breeze or hand-built: register, login, logout, email verification,
   password reset, remember-me
3. `bcrypt` rounds set to 12 in `config/hashing.php`
4. Rate limiter: 5 attempts per minute per email plus IP, then a 15-minute lock
5. Every auth endpoint gets a Form Request class. No inline `validate()`

**Verify:** register, verify by email, log out, reset, log back in. Six wrong
passwords produce the lockout with the remaining time shown. The same email
registers successfully under two different tenants.

---

## Block 6 — Roles

**Depends on:** Block 5.

1. Publish `spatie/laravel-permission` migrations unmodified. No `tenant_id` on
   the pivot: `users.tenant_id` already scopes it
2. Seed five roles: `super_admin`, `institute_admin`, `instructor`, `student`,
   `parent`
3. A role-assignment policy: throw `PrivilegeEscalationException` when the actor
   assigns a role at or above their own level, and write an audit row
4. `EnsureRole` middleware for panel gating

**Verify:** an institute admin cannot create a super admin, and cannot create
another institute admin. An instructor cannot assign any role. Each attempt
returns 403 and leaves an audit row.

---

## Block 7 — Filament panels

**Depends on:** Block 6.

1. Three panels: `/super`, `/admin`, `/teach`, each in its own panel provider
2. Each panel gets `ResolveTenant` (except `/super`, which is platform level) and
   `EnsureRole`
3. `discoverResources()` pointed at `app/Domains/*/Filament/Resources`
4. Leave them empty. Block 9 fills them

**Verify:** a student hitting `/teach` gets 403. An instructor hitting `/admin`
gets 403. A super admin reaches `/super` on the bare domain and does not need a
subdomain.

---

## Block 8 — Student shell and branding

**Depends on:** Block 7.

1. `routes/student.php`, registered with `ResolveTenant`
2. One Livewire component: login → dashboard placeholder
3. A branding service reading the current tenant, exposing logo, favicon and the
   two colours as CSS custom properties on the layout
4. Apply it across all four surfaces, Filament panels included
5. Branding upload validated against a file-type allowlist; anything else throws
   `InvalidFileTypeException`

**Verify:** two subdomains render two different logos and colour schemes from one
codebase. Uploading a `.svg` when only `png/jpg/webp` are allowed is rejected with
a field error and nothing is written.

---

## Block 9 — Content domain (structure only)

**Depends on:** Block 8.

1. Migrations: `courses` (with unique `(tenant_id, slug)`), `course_sections`,
   `lessons` (with a `type` enum covering video, pdf, text, embed, quiz,
   live_session)
2. Models using `BelongsToTenant`, registered in `TenantModelRegistry`
3. Filament resources for all three in the `/teach` panel, with a relation
   manager for the tree
4. Soft deletes on `courses`, `course_sections`, `lessons`, `users`, with restore
   exposed in the panel
5. **No lesson content.** S2 fills the leaves

**Verify:** the isolation suite still passes with three new models in it. A
teacher can build Course → Chapter → Topic and reorder topics. Deleting a course
hides it and it can be restored.

---

## Block 10 — `lesson_artifacts` contract (paper only)

**Depends on:** nothing. Can run in parallel with any block.

Write the eleven consumer read-queries against the schema in the source design
§5, on paper. No migration, no code.

**Verify:** all eleven express cleanly. Any that cannot means the contract is
wrong, and it is still free to change. Record the result in the source design.

---

## Block 11 — Security baseline

**Depends on:** Block 9.

1. CSRF on every stateful route
2. HTML sanitisation on every user-supplied rich-text field before storage
3. Explicit `$fillable` on every model. No `$guarded = []`
4. A convention test asserting no raw SQL: grep the domain folders for
   `DB::raw`, `DB::statement`, `whereRaw` and fail on any hit
5. Server notes into `SETUP.md`: SSH key-only, firewall limited to 80, 443, 22

**Verify:** the raw-SQL test fails when a `DB::raw` call is added, and passes
once removed. A `<script>` tag in a course description renders inert.

---

## Block 12 — Docs

**Depends on:** everything above, done last.

1. `SETUP.md`: every environment variable, plus signup and configuration steps
   for Bunny, Razorpay, the WhatsApp BSP, Claude, the live-class provider and
   Sentry. Mark which are not needed until their slice
2. `CLAUDE.md`: the domain layout, the `View::addNamespace()` deviation, the
   registry rule, naming conventions, and the test command

**Verify:** a wiped machine can reach a running local install using only
`SETUP.md`.

---

## Order

```
1 Scaffold
   └─ 2 Domain skeleton
        └─ 3 Tenancy core            ← everything inherits this
             ├─ 4 Isolation + CI     ← do early, while only 2 models exist
             └─ 5 Auth
                  └─ 6 Roles
                       └─ 7 Filament panels
                            └─ 8 Student shell + branding
                                 └─ 9 Content domain
                                      └─ 11 Security baseline
                                           └─ 12 Docs

10 lesson_artifacts contract — parallel, any time
```

Blocks 3 and 4 carry the risk. If the tenant scope is wrong, every later slice
inherits the fault, and the isolation suite is the only thing that catches it.

---

## Definition of done

- [ ] Two subdomains resolve to two institutes with distinct branding on all four surfaces
- [ ] Isolation suite green, and demonstrably red when a `tenant_id` is tampered with
- [ ] A tenant-owned model missing from the registry fails the build
- [ ] All five roles exist; privilege escalation blocked and audited
- [ ] Register, verify, login, reset, remember-me, lockout all working
- [ ] Course → Section → Lesson CRUD works in `/teach`, with restore after delete
- [ ] Security baseline in place, each item with a test
- [ ] Eleven `lesson_artifacts` consumer queries written and checked
- [ ] `SETUP.md` and `CLAUDE.md` written
- [ ] Demo delivered on 2026-08-22

## Out of scope

Custom domains and SSL, impersonation, 2FA, Google OAuth, plans and
subscriptions, any lesson content, parent linkage logic. Each is recorded against
its later slice in the source design §3.2.

## What can go wrong

| Risk | Signal | Response |
|---|---|---|
| Filament does not click | Three days in, the first two resources still fight you | Fall back to hand-built Livewire admin screens. Cheaper than it used to be, since the learner UI is already Livewire |
| Postgres and pgvector setup on Windows | Extension will not install in the container | Use the `pgvector/pgvector:pg16` image rather than adding the extension to stock `postgres:16` |
| Global scope leaks through a relationship | Isolation suite passes but an eager-loaded relation returns another tenant's row | Add a relationship case to the generated suite. The registry makes this one change, not sixty |
| Blocks 5 to 9 slip | 2026-08-22 arrives with the tree unfinished | Demo blocks 1 to 8. Two branded institutes and a green isolation suite is a real demo. The date does not move |
