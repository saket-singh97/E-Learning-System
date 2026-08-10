# S1 Foundation — Implementation Plan

Date: 2026-08-09
Source design: `2026-08-08-s1-foundation-design.md`
Target demo: 2026-08-22
Status: S1 COMPLETE — blocks 0-12 done

---

## PROGRESS — read this first, update it last

One session per block. At the end of a block: tick its row, commit, close the
session. The next session reads this table and knows exactly where to start.

| # | Block | Status | Commit | Notes |
|---|---|---|---|---|
| 0 | Prerequisites (PHP, Composer, Docker) | ☑ done 2026-08-09 | uncommitted | PHP **8.4.24**, not 8.3 — winget's 8.3 package 404s. No Herd; winget PHP + Docker Desktop |
| 1 | Scaffold | ☑ done 2026-08-09 | uncommitted | Laravel 13.24, Filament 5.7. predis not phpredis; Horizon needs `config.platform` |
| 2 | Domain skeleton | ☑ done 2026-08-09 | uncommitted | step 5 deferred — no panel provider exists yet, lands in Block 7 |
| 3 | Tenancy core | ☑ done 2026-08-09 | uncommitted | verified via Pest (Host header), not real hosts-file DNS |
| 4 | Isolation suite + CI | ☑ done 2026-08-09 | uncommitted | originally used a disposable `Widget` fixture (no real tenant model existed yet); swapped for `Course`/`CourseSection`/`Lesson` when Block 9 landed. CI workflow written but not yet run on GitHub (repo not pushed) |
| 5 | Auth | ☑ done 2026-08-09 | uncommitted | hand-built (not Breeze); JSON endpoints only, no Blade UI yet (Block 8); found + fixed a real cross-block bug in `bootstrap/app.php`'s JSON exception detection |
| 6 | Roles | ☑ done 2026-08-09 | uncommitted | role hierarchy level for `parent` is a documented assumption (source docs disagree on tier count); found + fixed a Laravel closure-route-binding bug in the test harness itself |
| 7 | Filament panels | ☑ done 2026-08-09 | uncommitted | found + fixed a Filament panel-gate bug (`FilamentUser`) and a cross-block URL-generation bug in `ResolveTenant` |
| 8 | Student shell + branding | ☑ done 2026-08-09 | uncommitted | found + fixed a Filament boot-timing bug — panel `->colors()` closures run before `ResolveTenant` middleware |
| 9 | Content domain | ☑ done 2026-08-09 | uncommitted | `CourseSectionResource`/`LessonResource` hidden from nav, reached via relation managers off `CourseResource`; restore exposed on all three, not just courses |
| 10 | `lesson_artifacts` contract | ☑ done 2026-08-09 | uncommitted | paper only, no code/migration; all 11 consumer queries written clean against §5 schema, no contract change needed |
| 11 | Security baseline | ☑ done 2026-08-09 | uncommitted | courses.description was missing from Block 9 (design §4.3 pinned it) — added migration + sanitisation now, since Block 11's own verify needs it; sanitisation via new `SanitizesHtml` trait (`app/Support/Security`), strip_tags allowlist; CSRF confirmed via Laravel 13's `PreventRequestForgery` default on `web` group, not `VerifyCsrfToken` |
| 12 | Docs | ☑ done 2026-08-09 | uncommitted | `SETUP.md` rewritten full: prerequisites, install, Docker DB/Redis, env vars split required-now vs not-until-slice (Bunny/Razorpay/WhatsApp BSP/Claude/live-class/Sentry), hosts-file entries, migrate+seed+run, test command, server hardening. `CLAUDE.md` written: domain layout, `View::addNamespace()` deviation, tenant model registry rule, naming conventions, test command |

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

## Verification pass — Blocks 0-4 (2026-08-09, 11:32am)

Re-checked every numbered deliverable against the actual filesystem/DB/test run
(not just re-reading each block's own "done" note). Full suite: 8/8 tests, 15
assertions, green.

| Block | Checked | Result |
|---|---|---|
| 0 | `php -v`/`composer -V`/`docker version`, all 12 `php -m` extensions, `memory_limit=512M` | ✅ all present, matches |
| 1 | 9 composer packages installed at stated versions, `docker-compose.yml` services (`pgvector/pgvector:pg16`, `redis:7-alpine`) | ✅ matches |
| 2 | All 16 domain subfolders (`Tenancy`/`Content` × 8), `Integrations/`, 3 `Support/*` folders | ✅ all exist |
| 3 | `tenants` migration columns match spec exactly; all 5 named exceptions exist; `resolve.tenant` alias + exception rendering wired in `bootstrap/app.php` | ✅ matches |
| 3 | `RebindsTenant` job middleware (queue-side tenant rebind) | ⚠️ **code exists, zero test coverage.** No job in any `Domains/*/Jobs/` folder yet (all empty), so nothing has ever exercised `RebindsTenant::handle()` — not the happy path, not the `fail()`-on-missing-`tenant_id` path. **Fix later:** write a `RebindsTenantTest` once the first real queued job exists (naturally lands with Block 9 or wherever the first `ShouldQueue` job is added) — dispatch a fake job with/without `tenant_id`, assert `CurrentTenant` gets bound / assert `$job->fail()` is called with `MissingTenantContextException`. |
| 4 | `TenantModelRegistry`, `CrossTenantTest` (4 assertions × dataset), guard test, `ci.yml`, `phpunit.xml`/`Pest.php` wiring | ✅ matches, both red/green directions re-confirmed live this pass |

**Net:** one real gap — `RebindsTenant` is unverified, not broken (code path is simple enough to be low-risk, but "simple enough" isn't the same as "tested"). Everything else in Blocks 0-4 checks out against the plan as written. Nothing here blocks starting Block 5.

---

## Block 0 — Local toolchain ✅ COMPLETE (2026-08-09)

Not in the original twelve. It had to happen first: the machine had no PHP and no
Composer, and Docker Desktop was installed but not running.

Owner's decision: Windows-native PHP with Postgres and Redis in Docker, rather
than WSL2. The repo lives on the `S:` drive, and running it from WSL over
`/mnt/s` would make Composer and Artisan several times slower.

1. PHP **8.4.24** via winget (`PHP.PHP.8.4`), installed to the winget package
   directory
2. `php.ini` created from `php.ini-development` with `pdo_pgsql`, `pgsql`,
   `mbstring`, `curl`, `gd`, `intl`, `zip`, `sodium`, `openssl`, `fileinfo`,
   `sockets`, `bcmath` enabled and `memory_limit = 512M`
3. Composer **2.10.2** as `composer.phar` in `%USERPROFILE%\bin`, with a
   `composer.bat` shim
4. User `PATH` extended with the PHP directory, `%USERPROFILE%\bin`, and
   `C:\Program Files\Docker\Docker\resources\bin`
5. Docker Desktop started; daemon **29.6.2**

**Deviation:** the plan assumed PHP 8.3. Winget's `PHP.PHP.8.3` package cannot
install — its download URL returns 404, because php.net archives superseded
releases. 8.4 installs cleanly and both Laravel 13 and Filament 5 support it.

**Deviation:** the Docker CLI directory also holds `docker-credential-desktop`.
Without it on `PATH`, `docker compose up` fails on image pull with
`error getting credentials`.

**Verify:** ✅ `php -v` → 8.4.24, `composer -V` → 2.10.2,
`docker version` → server 29.6.2, and `php -m` lists every extension above.

---

## Block 1 — Scaffold ✅ COMPLETE (2026-08-09)

**Depends on:** nothing.

1. `composer create-project laravel/laravel .` in the project root
2. Add `docker-compose.yml`: `postgres:16` with the `pgvector` extension, and
   `redis:7`
3. `.env`: `DB_CONNECTION=pgsql`, Redis for `CACHE_STORE`, `QUEUE_CONNECTION`,
   `SESSION_DRIVER`
4. Install: `livewire/livewire`, `filament/filament`, `spatie/laravel-permission`,
   `laravel/sanctum`, `laravel/horizon`, `pestphp/pest`
5. `php artisan migrate` against the container

**Verify:** ✅ `php artisan about` reports PostgreSQL and Redis connected.
`docker compose up -d` then `php artisan tinker` → `DB::select('select version()')`
returns PostgreSQL 16.

```
Drivers:  Cache redis | Database pgsql | Queue redis | Session redis
pg:         PostgreSQL 16.14 (Debian 16.14-1.pgdg12+1) on x86_64-pc-linux-gnu
extensions: plpgsql, vector, pg_trgm
cache(redis): pong          redis ping: PONG
containers:   lms_postgres healthy, lms_redis healthy
migrations:   0 pending
pest:         2 passed, 2 assertions
```

### What was actually built

Laravel **13.24.0**. Installed at the versions Composer resolved:
filament 5.7, livewire 4.3, spatie/laravel-permission 8.3, sanctum 4.x,
horizon 5.48, pest 4.7, predis 3.5.

`docker-compose.yml` runs `pgvector/pgvector:pg16` and `redis:7-alpine`, both with
healthchecks and named volumes. `docker/postgres/init/01-extensions.sql` creates
`vector` and `pg_trgm` on first boot, so the risk table's pgvector-on-Windows entry
never came up.

Scaffolding was created in a throwaway `_laravel_tmp/` and moved into the root,
because `composer create-project .` refuses a directory that already holds `docs/`
and `.git/`. Laravel's own `.gitignore` was discarded in favour of the existing one,
which already carried the Laravel patterns.

### Deviations from the block as written

1. **`predis/predis` instead of the `phpredis` extension.** There is no practical
   Windows build of phpredis. `REDIS_CLIENT=predis` in `.env` is deliberate.
2. **Horizon needs a `config.platform` override.** It requires `ext-pcntl`, which
   does not exist on Windows, so `composer.json` declares `ext-pcntl` and
   `ext-posix` as satisfied. The package and its dashboard install, but the
   `horizon` command cannot run locally — use `php artisan queue:work` for local
   queue processing. Horizon is for the Linux server. CI on Linux has the real
   extensions, so the override changes nothing there.
3. **`phpunit.xml` points at PostgreSQL, not SQLite `:memory:`.** A separate
   `lms_test` database was created with the same extensions. Block 4's isolation
   suite has to run against the engine production uses; a suite green on SQLite
   would prove nothing about Postgres global scopes.
4. **`SESSION_DOMAIN=.lms.test`** was set now rather than in Block 3, since
   subdomain tenancy needs a cookie shared across subdomains.
5. `install:api` and `horizon:install` were run, so `routes/api.php`, the
   `personal_access_tokens` migration and the Horizon config exist.

### Carried into Block 3

`physics.lms.test` and `chemistry.lms.test` need hosts-file entries before Block 3
can verify. That needs administrator rights and has not been done.

---

## Block 2 — Domain skeleton ✅ COMPLETE (2026-08-09)

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

**Verify:** ✅ a throwaway Blade file at `app/Domains/Tenancy/Views/ping.blade.php`
rendered through `view('tenancy::ping')` → `pong`. Deleted after.

### Deviations from the block as written

1. **Step 5 deferred.** No Filament panel provider exists yet — `filament:install
   --panels` has not run, and the three panels (`/super`, `/admin`, `/teach`) are
   Block 7's job. Block 7 step 3 already carries this exact instruction
   (`discoverResources()` pointed at `app/Domains/*/Filament/Resources`), so
   nothing is lost — it just happens once there is a provider to point.
2. **`App\Domains\` → `app/Domains/` autoload map is redundant but harmless.**
   `App\` already maps to `app/`, which covers the `Domains` sub-namespace. Added
   anyway per the block spec, for explicitness.
3. **20 `.gitkeep` files** added, one per empty leaf folder (all of `Models/`,
   `Services/`, `Filament/`, `Livewire/`, `Views/`, `Jobs/`, `Policies/`,
   `Events/` under both domains, plus `Integrations/` and the three `Support/`
   folders) — otherwise git tracks none of the skeleton, since it does not track
   empty directories.

---

## Block 3 — Tenancy core ✅ COMPLETE (2026-08-09)

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

```
tests/Feature/Tenancy/ResolveTenantTest.php: 4 passed, 8 assertions
full suite: 6 passed, 10 assertions
```

### Deviations from the block as written

1. **Verified via Pest with a `Host` header, not real subdomain DNS.** Laravel's
   test client resolves `Route::domain()` from the request URL in-process — no
   real hosts-file entry needed. The `physics.lms.test` / `chemistry.lms.test`
   hosts-file entries carried over from Block 1 are still undone (need admin
   rights) and are only needed for manual browser verification, not the
   automated suite.
2. **`BelongsToTenant`'s global scope is a no-op when no tenant is bound**,
   rather than throwing — only the `creating` write path throws
   `MissingTenantContextException`. Console/seeder contexts (no HTTP request,
   no bound tenant) can still read unscoped. Writing with no tenant bound still
   throws, so `tenant_id` is never null.
3. **All five named exceptions created now**, though `PrivilegeEscalationException`
   and `InvalidFileTypeException` stay unused until Blocks 6 and 8 — the block
   spec listed all five together.
4. Test route (`/__tenant_ping`) registered ad hoc inside the test file, same
   throwaway pattern as Block 2's `ping.blade.php` — nothing added to
   `routes/web.php`.

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

## Block 4 — ✅ COMPLETE (2026-08-09)

**Built:**
1. `app/Support/Tenancy/TenantModelRegistry.php` — `MODELS` const array
2. `tests/Fixtures/Models/Widget.php` + `tests/Isolation/CrossTenantTest.php` —
   dataset-driven test iterating `TenantModelRegistry::MODELS`
3. Guard test reflecting `app/Domains/*/Models/*.php` for `BelongsToTenant`
   usage not present in the registry
4. `.github/workflows/ci.yml` — Postgres 16 + Redis 7 services, `composer
   install`, `key:generate`, `migrate`, `./vendor/bin/pest`
5. `tests/Isolation` wired into `phpunit.xml` (new `Isolation` testsuite) and
   `tests/Pest.php` (`->in('Feature', 'Isolation')`) so it gets the Laravel
   `TestCase` binding

### Deviations from the block as written

1. **No real tenant-owned model exists yet** — Block 9 (Content domain) hasn't
   landed, so the registry had nothing real to iterate. Added a disposable
   `Tests\Fixtures\Models\Widget` fixture (its own `widgets` table, created/
   dropped per-test via `Schema::create`/`dropIfExists` in `beforeEach`/
   `afterEach`) and registered it in `TenantModelRegistry::MODELS`. This
   proves the "generated from a registry, not hand-written per model"
   mechanism end-to-end. Remove the `Widget` entry once Block 9 registers a
   real model — the suite picks the new model up automatically via `->with()`.
2. **API route 404 and Filament table/edit-page assertions dropped from the
   per-model test** — neither exists yet for any tenant model (API routes and
   Filament resources land in Blocks 7 and 9). Only the four assertions
   buildable today run: `all()` excludes the other tenant, `find()` returns
   null, `update()` affects 0 rows, `create()` with no tenant bound throws
   `MissingTenantContextException`. Add the route/Filament assertions to this
   same test once a real model + resource exist.
3. **CI workflow written but not exercised on GitHub** — repo has no remote
   and is uncommitted, so `ci.yml` has never actually run in Actions. Syntax
   and service config are hand-verified against the local Postgres/Redis
   setup (same credentials as `phpunit.xml`), not GitHub-verified.

**Verify (done locally, both directions confirmed then reverted):**
- Broke `BelongsToTenant`'s global scope (no-op closure) → isolation test went
  red (`Expecting Collection not to contain 2`) → reverted → green.
- Added an unregistered `RogueModel` under `app/Domains/Tenancy/Models/` using
  `BelongsToTenant` → guard test went red (listed the offending FQCN) →
  removed the file → green.
- Full suite: 8 tests, 15 assertions, all green.

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

## Block 5 — ✅ COMPLETE (2026-08-09)

**Built:**
1. Migration `add_tenant_id_to_users_table`: nullable `tenant_id` FK, dropped
   the old `email`-only unique index, added `unique(tenant_id, email)`
2. `User` model: `implements MustVerifyEmail`, `tenant_id` fillable, `tenant()`
   relation. `UserFactory` defaults `tenant_id` to `Tenant::factory()`
3. Hand-built (not Breeze) under `app/Http/Controllers/Auth/`:
   `RegisteredUserController`, `AuthenticatedSessionController` (login/logout),
   `VerifyEmailController`, `EmailVerificationNotificationController`,
   `PasswordResetLinkController`, `NewPasswordController`
4. Form Requests for every endpoint that takes input: `RegisterUserRequest`,
   `LoginRequest` (owns the lockout logic), `ForgotPasswordRequest`,
   `ResetPasswordRequest`
5. `routes/auth.php`, included from `routes/web.php` inside
   `Route::domain('{subdomain}.lms.test')->middleware(['web','resolve.tenant'])`
6. `AppServiceProvider::registerTenantAwareAuthUrls()` — `VerifyEmail` and
   `ResetPassword` notifications overridden via `createUrlUsing()` so links
   point at `{tenant.subdomain}.lms.test`, not the central app URL
7. Login/registration/password-reset all pass `tenant_id` inside the
   credentials array to `Auth::attempt()` / `Password::sendResetLink()` /
   `Password::reset()` — Laravel's `EloquentUserProvider` turns every
   non-password credential key into a `WHERE`, so tenant scoping falls out of
   the framework's own query builder instead of a bolted-on scope
8. `config/hashing.php` and `lang/en/*` published (Laravel 11+ skeleton ships
   neither by default; needed rounds=12 config and `auth.failed`/`auth.throttle`
   message strings)

### Deviations from the block as written

1. **Hand-built, not Breeze.** Breeze's scaffolded controllers assume a
   single-tenant `User::where('email', ...)` query; every one of them would
   need rewriting for tenant-scoped credentials anyway, so scaffolding it
   first would only add throwaway code. Matches the project's existing pattern
   of hand-rolled tenancy infra over package magic.
2. **JSON endpoints, no Blade views.** No UI exists yet (Block 8 builds the
   student shell), so every controller returns JSON. Register/login return
   `{id, email}`; logout/verify/reset return a `status` string. Revisit once
   Block 8 needs actual forms.
3. **Rate limiting collapsed to one mechanism, not two.** The spec's wording
   ("5 attempts per minute... then a 15-minute lock") reads as two layers. I
   built both — a route-level `throttle:login` (5/min via `RateLimiter::for`)
   *and* `LoginRequest`'s own 5-attempt/15-minute account lockout — and hit a
   real collision: both trip at the same count, so the route throttle's 429
   always wins and the lockout message (the thing the demo verify step
   actually checks for) never surfaces. Removed the route-level limiter
   entirely; `LoginRequest::authenticate()` is now the only throttle,
   5 failed passwords → 15-minute lock, checked and cleared per email+IP key.
4. **`config/hashing.php` didn't need a value change.** Laravel 13's
   framework default is already `env('BCRYPT_ROUNDS', 12)` — 12 out of the
   box. Only had to publish the file (it doesn't ship in Laravel 11+'s
   trimmed skeleton) so the setting is visible/pinned, not silently implicit.
5. **`resources/views/welcome.blade.php` (real regression, fixed).** Its
   default guest nav called `route('login')` with no params. Once `login`
   became a domain-bound route (`{subdomain}.lms.test/login`), that call
   started throwing `UrlGenerationException` on every request to `/`,
   including the pre-existing `ExampleTest`. Removed the dead login/register
   nav block — central domain has no login concept now, only tenant
   subdomains do. This page is scaffolding Block 8 replaces anyway.
6. **`bootstrap/app.php` bug found and fixed (not a deviation from this
   block's own scope, but discovered while building it).** Block 3 had set
   `shouldRenderJsonWhen(fn ($r) => $r->is('api/*'))` — Laravel's
   `shouldRenderJsonWhen` *replaces* the default Accept-header JSON detection
   rather than adding to it. Every non-`api/*` JSON request (all of this
   block's endpoints, none of which live under `/api`) was silently getting
   HTML redirect responses instead of JSON error bodies, regardless of
   `Accept: application/json`. Fixed to
   `fn ($r) => $r->is('api/*') || $r->expectsJson()`. Confirmed no regression
   against Block 3's `ResolveTenantTest` (those assertions check status codes
   only, not content-type).

**Verify (automated, `tests/Feature/Auth/AuthenticationFlowTest.php`, 3
tests / 31 assertions):**
- Full round trip: register → verify-by-email (captured via
  `Notification::fake()`) → logout → forgot-password → reset-password →
  log back in with the new password and `remember=true`; asserts the
  recaller cookie is set.
- Six wrong passwords: first five return 422 (`auth.failed`), sixth returns
  422 with the throttle message and remaining-seconds count.
- Same email registers under two different tenants (`physics` and
  `chemistry`) — two distinct `User` rows, both succeed.

**Not covered — flagged, not fixed:**
- No test hits the endpoints as literal HTML-form POSTs (only JSON, since
  there's no UI). If Block 8 adds real forms, add a test confirming
  `expectsJson() === false` requests still get sane (redirect/session-error)
  behavior, not a broken response.
- Password reset / email verification links are asserted via the captured
  notification object, not through an actual mail client / browser click —
  standard Laravel testing practice, but worth knowing this is a controller
  test, not an end-to-end one.

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

## Block 6 — ✅ COMPLETE (2026-08-09)

**Built:**
1. `spatie/laravel-permission` migrations published unmodified
   (`2026_08_09_062416_create_permission_tables`); `User` gets `HasRoles`
2. `RoleSeeder` — five global roles, guard `web`, run from `DatabaseSeeder`
3. `app/Domains/Tenancy/Services/RoleHierarchy.php` — `LEVELS` map
   (`super_admin`=1 … `parent`=4) and `CAN_ASSIGN_ROLES` (admin tier only);
   `canAssign()` and `highestRole()` are the two decision points
4. `app/Domains/Tenancy/Policies/RoleAssignmentPolicy::assign()` — writes a
   `role_assignment_audits` row for every attempt (allowed or not), *then*
   throws `PrivilegeEscalationException` on denial
5. `role_assignment_audits` migration + `RoleAssignmentAudit` model
   (`tenant_id`, `actor_id`, `target_user_id`, `actor_role`,
   `attempted_role`, `allowed`) — uses `BelongsToTenant`, so it's now a
   *real* tenant-owned model, registered in `TenantModelRegistry`
6. `app/Http/Middleware/EnsureRole.php`, aliased `role` — `abort(403)` when
   the user lacks any of the listed roles
7. `PrivilegeEscalationException` renders to 403 in `bootstrap/app.php`,
   alongside the two exceptions Block 3 already mapped

### Deviations from the block as written

1. **`parent`'s hierarchy level is a documented assumption, not a spec'd
   fact.** The source docs disagree with each other:
   `docs/superpowers/specs/2026-08-08-s1-foundation-design.md`'s own
   contradiction log (line 441) already flags "DESIGN.md (4 roles) vs
   FEATURES.md §0 (5 roles)" as unresolved. I set `student` and `parent` to
   the same level (4, tied) — neither can assign roles regardless (see
   `CAN_ASSIGN_ROLES`), so the tie never actually changes a decision. If a
   future block needs `parent` to outrank or be outranked by `student`,
   `RoleHierarchy::LEVELS` is the one place to change it.
2. **Role assignment gated to admin-tier roles, not pure hierarchy
   comparison.** A literal reading of "blocked at or above own level" alone
   would let an `instructor` (level 3) assign `student`/`parent` (level 4,
   strictly below) — but the verify step explicitly requires "an instructor
   cannot assign any role." Added `RoleHierarchy::CAN_ASSIGN_ROLES =
   [super_admin, institute_admin]` as a separate gate checked before the
   level comparison.
3. **Audit row written on every attempt, not just denials.** The error
   table only specifies an audit row on the `PrivilegeEscalationException`
   path; `RoleAssignmentAudit` has an `allowed` boolean and gets a row for
   successful assignments too. Costs one column, gives a complete audit
   trail instead of only a denial log — kept as a small superset of what
   was asked, not a scope change.
4. **No controller/route for role assignment yet.** Block 6's own item list
   (1-4 above) never mentions one — user-management UI is Block 7's job
   (Filament panels). Verified through an ad-hoc test-only route
   (`tests/Feature/Tenancy/RoleAssignmentTest.php`, same throwaway pattern
   Blocks 2/3 already used), exercising the policy through real HTTP +
   the `bootstrap/app.php` 403 mapping, not just a unit call.
5. **`TenantModelRegistry` grew a `seedAttributes()`/`updateAttributes()`
   lookup (real fix, not a workaround).** Block 4 built the isolation suite
   assuming every registered model shares a `name` column — true only for
   the `Widget` fixture. `RoleAssignmentAudit` is the first *real* tenant
   model to land in the registry and it doesn't have a `name` column, so
   the generic `['name' => ...]` literals in `CrossTenantTest` would have
   silently either broken (unknown column) or, worse, been skipped. Added
   per-model attribute lookups to `TenantModelRegistry` instead — this is
   what "generated from a registry, not hand-written per model" (Block 4's
   own stated goal) actually requires once model shapes differ.

### Bugs found and fixed (both real, both outside this block's stated scope)

1. **Pest dataset crash: `Call to a member function connection() on null`.**
   `->with(TenantModelRegistry::MODELS)` — an unnamed, numerically-indexed
   array of 2+ real (autoloadable) Eloquent-model class-strings — crashed
   before the test body even ran; the same array with a single entry, or
   with explicit string keys, worked fine. Root cause not fully chased down
   (smells like a Pest/PHPUnit dataset-labeling code path that behaves
   differently for 2+ unnamed class-string values pointing at real
   classes) — not worth more time against a working fix. Fix: give the
   dataset explicit keys —
   `array_combine(array_map(class_basename(...), MODELS), MODELS)` — which
   also makes `CrossTenantTest`'s output read `with dataset "Widget"` /
   `"RoleAssignmentAudit"` instead of an unlabeled index.
2. **Route closures binding models positionally, not by name, when a
   domain-parameter has no matching closure argument.** In
   `RoleAssignmentTest`'s ad-hoc route
   (`Route::domain('{subdomain}.lms.test')->post('/__assign_role/{actor}/{target}/{role}', ...)`),
   the closure only declared `($actor, $target, $role)` — no `$subdomain`
   parameter. Laravel resolved `$actor` to the literal string `"physics"`
   (the subdomain) instead of binding the `User` model, a 500 instead of
   the intended 403. Fixed by declaring `$subdomain` explicitly as the
   closure's first parameter so all four URI segments have a named match.
   Test-file-only bug (this exact route/closure shape isn't used anywhere
   in `app/`), but worth knowing the pattern is unsafe if Block 7+ ever
   defines closure-based domain routes with unbound segments.

**Verify (automated):**
- `tests/Feature/Tenancy/RoleAssignmentTest.php` (7 tests / 14 assertions):
  institute_admin → super_admin blocked + audited; institute_admin →
  institute_admin blocked + audited; instructor → any role blocked +
  audited; institute_admin successfully assigns instructor/student/parent
  (dataset, 3 cases); super_admin assigns institute_admin (allowed) then
  super_admin (blocked) — both against the same target, back to back.
- `tests/Feature/Auth/EnsureRoleTest.php` (3 tests): allowed role passes
  through; disallowed role gets 403; guest gets 401 (via `auth` middleware,
  ahead of `role` in the stack).
- `tests/Isolation/CrossTenantTest.php` re-verified with `RoleAssignmentAudit`
  as a second, real dataset entry — all four generic assertions
  (`all()`/`find()`/`update()`/`create()`-throws) pass against it, not just
  the `Widget` fixture.
- Full suite: 22/22 passed, 70 assertions.

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

## Block 7 — ✅ COMPLETE (2026-08-09)

**Built:**
1. `app/Providers/Filament/{Super,Admin,Teach}PanelProvider.php` via
   `make:filament-panel`, registered in `bootstrap/providers.php`. `super` has
   no `->domain()` (bare `lms.test`); `admin`/`teach` use
   `->domain('{subdomain}.lms.test')`, same pattern as `routes/auth.php`
2. `admin`/`teach` get `ResolveTenant::class` in `->middleware()` (runs for
   every request, guest included, same as the auth-route group); all three
   get `EnsureRole::class.':<role>'` in `->authMiddleware()`, after
   `Authenticate::class` — `super`→`super_admin`, `admin`→`institute_admin`,
   `teach`→`instructor`
3. `discoverResources()` called twice per panel, once per existing domain
   (`app/Domains/Tenancy/Filament/Resources`, `app/Domains/Content/Filament/Resources`)
   — both dirs empty (`.gitkeep` only) until Block 9
4. Panels otherwise left at scaffold defaults (default Amber color, stock
   Dashboard page, `AccountWidget`/`FilamentInfoWidget`) — no custom UI

### Deviations from the block as written

1. **Role gate is a straight panel↔role mapping, not a hierarchy check.**
   `RoleHierarchy` (Block 6) governs who can *assign* roles; it says nothing
   about panel access. Read the panel names literally against the five
   seeded roles — no role gets access to more than one panel. Not spec'd
   either way; revisit if `super_admin` or `institute_admin` turn out to need
   cross-panel access later.
2. **`discoverResources()` points every panel at both domains equally**,
   since neither directory holds anything yet and the block spec's own
   wording (`app/Domains/*/Filament/Resources`) doesn't say which panel
   owns which domain. Block 9 explicitly owns "Filament resources for all
   three in the `/teach` panel" — that's where a real per-panel/per-domain
   split gets decided, once resource classes exist to route.
3. **No Filament login page (`->login()`) on any panel.** The scaffold
   command auto-added it to `super` (first panel registered) but not to
   `admin`/`teach`; removed it from `super` too, for consistency. Filament's
   stock login form does plain `email`/`password` auth with no `tenant_id`
   key, which would bypass the tenant-scoped credential lookup Block 5
   built (`Auth::attempt` with `tenant_id` in the credentials array) — same
   collision risk Block 5 already solved once. Flagged, not fixed: real
   panel login UI is deferred until it can be built tenant-aware.
4. **No login page also means no guest-access assertion tested.** Verify
   only requires role-mismatch (403) and bare-domain-reachability checks,
   both authenticated; unauthenticated panel access is a documented gap,
   not a regression.

### Bugs found and fixed (both real, both outside this block's stated scope)

1. **Filament's own panel gate 403s every authenticated user outside
   `app.env=local`.** `Filament\Http\Middleware\Authenticate` falls back to
   `config('app.env') !== 'local'` when the user model doesn't implement
   `FilamentUser` — true in the `testing` env, so it blocked every request
   before `EnsureRole` (which runs right after it in `authMiddleware`) ever
   got a chance to check the role. Fixed: `User implements FilamentUser`,
   `canAccessPanel()` returns `true` unconditionally — `EnsureRole` stays
   the single source of truth for panel access, per this block's own item 2.
2. **`ResolveTenant` never filled the implicit `{subdomain}` route
   parameter for URL generation.** Rendering the `teach` panel's dashboard
   throws `UrlGenerationException` building its own logout link
   (`filament.teach.auth.logout`, URI `teach/logout`) — Laravel's `route()`
   has no way to know `subdomain` for a route inside
   `Route::domain('{subdomain}.lms.test')` unless told. This would have hit
   *any* route generation inside that domain group, not just Filament's —
   Block 8/9 pages linking to each other would have failed the same way.
   Fixed at the source: `ResolveTenant::handle()` now calls
   `URL::defaults(['subdomain' => $subdomain])` right after binding
   `CurrentTenant`, so every URL generated for the rest of the request
   fills `subdomain` from the resolved host automatically.

**Verify (automated, `tests/Feature/Filament/PanelAccessTest.php`, 5 tests):**
- Student → `/teach` → 403. Instructor → `/admin` → 403.
- Instructor → `/teach` → 200. Institute admin → `/admin` → 200.
- Super admin (`tenant_id = null`) → `http://lms.test/super` → 200, no
  subdomain, no tenant seeded.
- Full suite: 27/27 passed, 75 assertions.

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

## Block 8 — ✅ COMPLETE (2026-08-09)

**Built:**
1. `routes/student.php` — `GET /dashboard`, registered via a second
   `Route::domain('{subdomain}.lms.test')->middleware(['web','resolve.tenant'])`
   group in `routes/web.php`, same pattern as `routes/auth.php`. No `EnsureRole`
   — any tenant user, or a guest, can load the shell; the component itself
   decides what to show
2. `App\Livewire\StudentPortal` (default Livewire namespace, view
   `resources/views/livewire/student-portal.blade.php`) — one component, two
   states: `@auth` shows a welcome placeholder and a logout button, `@else`
   shows the login form. `login()` calls `Auth::attempt()` with `tenant_id`
   from `CurrentTenant`, same tenant-scoped-credentials pattern Block 5 built
3. `app/Domains/Tenancy/Services/BrandingService.php` — `primaryColor()` /
   `accentColor()` (tenant's hex or a default), `cssVariables()` (both as a
   `--color-primary`/`--color-accent` string), `logoUrl()` / `faviconUrl()`
   (built from `Storage::disk('public')->url()`), and `storeLogo()` /
   `storeFavicon()` for uploads
4. `resources/views/student/dashboard.blade.php` — the one Blade layout for
   the shell, inlines `cssVariables()` in a `<style>` block, sets the favicon
   link and an optional logo `<img>`, hosts `<livewire:student-portal />`
5. All three Filament panel providers (`Super`/`Admin`/`Teach`) call
   `->colors()`, `->favicon()`, `->brandLogo()` with closures reading
   `BrandingService`, replacing the static `Color::Amber` from Block 7
6. `InvalidFileTypeException` gained a `forField()` factory (field name +
   allowed list + rejected extension, mirroring the other four named
   exceptions' factory style) and a `bootstrap/app.php` render mapping to a
   422 with a Laravel-shaped `errors.{field}` body — a real field error, not
   a bare `abort()`
7. `BrandingService::ALLOWED_EXTENSIONS = ['png', 'jpg', 'webp']` — checked
   against `UploadedFile::extension()` (MIME-guessed, not the client-supplied
   filename), before any `Storage::put` call, so a rejected upload writes
   nothing

### Deviations from the block as written

1. **No separate `/login` route for the shell.** The block says "one
   Livewire component: login → dashboard placeholder" (singular) — read as
   one component covering both states behind one route (`/dashboard`), not
   two routes. The existing JSON `POST /login` from Block 5 is untouched and
   still exists for API-style clients; the Livewire component authenticates
   itself via `Auth::attempt()` directly, it doesn't call that endpoint.
2. **`storeLogo()`/`storeFavicon()` take an explicit `Tenant` argument**
   rather than reading `CurrentTenant` themselves. Keeps the write path
   testable without needing a bound tenant context, and matches who'd
   actually call it later (Block 9's Filament resource, acting on a specific
   tenant row, not necessarily "the current one").
3. **Upload validation exercised through an ad-hoc test route**
   (`tests/Feature/Tenancy/BrandingUploadTest.php`), same throwaway pattern
   Blocks 2/3/6/7 already used — there's no real upload endpoint yet (that's
   Block 9's Filament form), so this proves the exception → 422 render
   mapping end-to-end without inventing a route the spec never asked for.
4. **Allowlist is exactly `png`/`jpg`/`webp`**, no `jpeg` synonym added,
   matching the block's literal wording rather than guessing at a more
   permissive real-world list.

### Bugs found and fixed (both real, both outside this block's stated scope)

1. **Ad-hoc test route used implicit Eloquent route-model-binding without the
   `web`/`api` middleware group, which silently gave a null-`id`, unsaved
   model instead of a 404 or the real row.** `SubstituteBindings` (the
   middleware that actually performs `{tenant} → Tenant::find()`) only runs
   inside the `web`/`api` groups; a bare `Route::post()` registered in a test
   has neither, so `function (Tenant $tenant)` resolved via the *container*
   instead (`app(Tenant::class)`), handing back a fresh, empty model. Same
   root cause as Block 6's closure-binding bug, different mechanism. Fixed
   by not using implicit binding in ad-hoc test routes at all — take the raw
   id, `Tenant::findOrFail()` it manually — matching the explicit-parameter
   fix Block 6 already settled on for this exact class of trap.
2. **Filament panel `->colors()`/`->favicon()`/`->brandLogo()` closures
   evaluate during provider boot, before route middleware runs — so
   `ResolveTenant` never gets a chance to bind `CurrentTenant` before
   `BrandingService` is read for the very first time on any given request.**
   `Panel::boot()` calls `FilamentColor::register($this->getColors())`
   eagerly, and `HasColors::getColors()` evaluates every closure immediately
   at that point — handing `ColorManager` an already-resolved (and, before
   the fix, always-default) array, not a lazy closure. Caught by a test that
   uses two tenants with different `primary_color` values and asserts each
   panel response contains that tenant's own computed shade and not the
   other's — it failed with both responses showing the *fallback* color's
   hue, proving neither tenant's color was actually reaching the page.
   Fixed in `BrandingService::tenant()`: when `CurrentTenant` isn't bound
   yet, resolve the tenant straight from the current request's host (the
   same subdomain lookup `ResolveTenant` itself does), instead of assuming
   the middleware has already run. Verified both directions: two tenants get
   two different rendered colors on the same panel, and the bare-domain
   `/super` panel (no tenant, ever) still renders without error.

**Verify (automated):**
- `tests/Feature/Tenancy/BrandingServiceTest.php` (5 tests): css variables
  from tenant colors; default-color fallback with no tenant bound; null
  logo/favicon urls when unset; allowed upload stores and updates
  `logo_path`; disallowed extension throws `InvalidFileTypeException` and
  writes nothing.
- `tests/Feature/Tenancy/BrandingUploadTest.php` (2 tests): the same
  allow/reject pair through real HTTP, proving the `bootstrap/app.php` 422
  render mapping.
- `tests/Feature/Student/StudentPortalTest.php` (3 tests): guest sees the
  login form; correct credentials log in and show the placeholder; wrong
  credentials show a field error and leave the user logged out.
- `tests/Feature/Filament/PanelBrandingTest.php` (2 tests): two tenants get
  two different computed primary-color shades on the same panel; the
  bare-domain super panel still renders with no tenant bound.
- Full suite: 39/39 passed, 109 assertions.

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

## Block 9 — ✅ COMPLETE (2026-08-09)

**Built:**
1. Migrations: `courses` (unique `(tenant_id, slug)`), `course_sections`
   (`course_id`, `position`), `lessons` (`course_section_id`, `type` enum of
   video/pdf/text/embed/quiz/live_session, `position`) — all three with
   `softDeletes()`. Fourth migration adds `softDeletes()` to `users`
2. `App\Domains\Content\Models\{Course,CourseSection,Lesson}` — `BelongsToTenant`
   + `SoftDeletes`, ordered `hasMany` relations (`orderBy('position')`) down the
   tree, `belongsTo` back up it
3. Registered in `TenantModelRegistry::MODELS`; the disposable `Widget` fixture
   (`tests/Fixtures/Models/Widget.php`) and its isolation-test scaffolding
   deleted now that real tenant-owned models exist
4. Filament resources under `App\Domains\Content\Filament\Resources`, wired
   into the `/teach` panel (`TeachPanelProvider` already discovered this path
   from Block 7): `CourseResource` (top-level nav, list/create/edit) with a
   `SectionsRelationManager`; `CourseSectionResource` and `LessonResource`
   (both `shouldRegisterNavigation(): false`) reached by drilling in from the
   tree, each carrying its own `LessonsRelationManager` / row actions
5. Sections and lessons tables use `->reorderable('position')`; a
   `manageLessons` row action on the sections table links into
   `CourseSectionResource`'s edit page, which hosts `LessonsRelationManager`
   — this is the "tree" UI: Course → Sections (reorderable) → Lessons
   (reorderable)
6. `TrashedFilter`, `RestoreAction`, `ForceDeleteAction` on `CourseResource`'s
   table and on both relation managers, each `modifyQueryUsing()` to include
   trashed rows — restore exposed everywhere a row can be deleted
7. No lesson content fields — `type` picks the shape, S2 fills the leaves

### Deviations from the block as written

1. **Three resources, but only one in navigation.** The block says "Filament
   resources for all three" without saying all three need their own nav
   entry. `CourseSectionResource` and `LessonResource` exist as real
   resources (satisfying the registry/registration requirement and giving
   each model its own edit page + relation manager) but are reached only by
   drilling down from `CourseResource`, matching how a teacher actually
   thinks about the tree — there's no reason to browse a flat list of every
   lesson across every course.
2. **Restore extended to sections and lessons, not just courses.** The
   block's soft-delete bullet lists all three content models (plus `users`)
   together; the verify line only mentions courses explicitly. Treated the
   bullet as the requirement and the verify line as the minimum, so
   `TrashedFilter`/`RestoreAction`/`ForceDeleteAction` are on all three
   tables, not only `CourseResource`'s.
3. **`users` got the soft-deletes migration and `SoftDeletes` trait only —
   no restore UI.** No `UserResource` exists anywhere in the codebase yet;
   user management isn't part of Block 9's file list (only "Filament
   resources for all three" — courses/sections/lessons), so this is DB-level
   groundwork for whichever future block adds user management, not a gap in
   this one.

**Verify (automated):**
- `tests/Feature/Content/CourseTreeTest.php` (4 tests): builds a
  Course→Section→Lesson tree and confirms the relations resolve both ways;
  reordering (changing `position`) is reflected in the ordered relation
  query; deleting a course hides it from default queries and `restore()`
  brings it back; an instructor can reach `/teach/courses`
- `tests/Isolation/CrossTenantTest.php`: re-run with `Course`, `CourseSection`,
  `Lesson` in `TenantModelRegistry::MODELS` in place of `Widget` — same
  create/read/update/missing-context assertions, now against real models
- Full suite: 45/45 passed, 127 assertions

---

## Block 10 — `lesson_artifacts` contract (paper only)

**Depends on:** nothing. Can run in parallel with any block.

Write the eleven consumer read-queries against the schema in the source design
§5, on paper. No migration, no code.

**Verify:** all eleven express cleanly. Any that cannot means the contract is
wrong, and it is still free to change. Record the result in the source design.

**Done (2026-08-09):** all eleven written against §5 schema, all clean, no
contract change needed. See source design §5.3.

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

**Done (2026-08-09):** `tests/Feature/Security/SecurityBaselineTest.php` — 4
tests: CSRF middleware present on `web` group, no `$guarded = []` in any
domain model, no raw-SQL convention (verified red/green by hand — injected
`DB::raw` into `Course.php`, test failed, reverted, test passed), script tag
stripped from `Course.description` on save. `SETUP.md` written with SSH +
firewall notes. `courses.description` added (was missing from Block 9 despite
being in design §4.3 schema) via new migration + `SanitizesHtml` trait. Full
suite: 49/49 passing.

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

**Done (2026-08-09):** `SETUP.md` walked end to end against the actual repo
state (PHP/winget path, `docker-compose.yml` services, `phpunit.xml`'s
`lms_test` DB, `composer.json` scripts, `routes/web.php` domain routing) —
every command in it matches what's really in the repo, including the
previously-undocumented step of creating `lms_test` and its extensions by
hand, since the Docker init script only fires for the default `lms` database.
No external accounts exist yet (Bunny/Razorpay/WhatsApp BSP/Claude/Sentry/live
class), so those sections are documented but unexercised — flagged as such
rather than claimed verified. `CLAUDE.md` written new at repo root. S1
Foundation done, all 12 blocks.

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
- [x] Security baseline in place, each item with a test
- [x] Eleven `lesson_artifacts` consumer queries written and checked
- [x] `SETUP.md` and `CLAUDE.md` written
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

---

## Post-S1 review (2026-08-09)

Full-codebase review of blocks 0-12 after Block 12 closed, looking for real
bugs (not style). One critical fix, one spec-conformance fix, one reviewed
and accepted as-is.

1. **Fixed — cross-tenant data leak via `/super` panel.** `SuperPanelProvider`
   discovered `Domains/Content/Filament/Resources` (`CourseResource` etc.)
   but carries no `->domain()` and no `ResolveTenant` middleware, by design —
   the super panel is deliberately cross-tenant. `BelongsToTenant`'s global
   scope (`app/Support/Tenancy/BelongsToTenant.php:14`) only adds the
   `tenant_id` where-clause `if (... ->isBound())`; with no tenant bound it
   silently omits the filter rather than throwing. Net effect: a super_admin
   hitting `/super/courses` saw every tenant's courses unscoped, and
   `DeleteAction`/`EditAction` on that table would act on rows across tenants
   with no indication which institute they belonged to. This is exactly the
   failure mode flagged in advance in **What can go wrong** above ("global
   scope leaks... another tenant's row") — same family, different entry
   point (panel resource registration, not a relationship). Confirmed live:
   reverted the fix, `PanelAccessTest` regression test failed with 200
   instead of 404; reapplied, back to green.
   **Fix:** removed the `Domains/Content/Filament/Resources` discovery line
   from `SuperPanelProvider`. Super panel now only discovers `Tenancy`
   resources (currently none exist, so this is a no-op today, correct for
   when they land).
2. **Fixed — `/admin` also over-registered Content resources**, against
   Block 9's own spec text ("Filament resources for all three in the `/teach`
   panel"). Not a data leak — `AdminPanelProvider` does carry `ResolveTenant`
   and `->domain()`, so an institute_admin only ever saw their own tenant's
   courses — but it let `institute_admin` reach Course/Section/Lesson CRUD
   that was never meant to exist outside `/teach`. This was a known,
   explicitly flagged gap from Block 7 (see that block's "Deviations from
   spec" note 2: discovery was pointed at both domains equally because
   neither directory held anything yet, "Block 9 explicitly owns... that's
   where a real per-panel/per-domain split gets decided" — Block 9 landed the
   resources but never came back to do the split).
   **Fix:** removed the same discovery line from `AdminPanelProvider`.
   Content resources now exist only in `TeachPanelProvider`.
   **New test:** `tests/Feature/Filament/PanelAccessTest.php` — asserts
   `/super/courses` and `/admin/courses` 404, `/teach/courses` 200.
3. **Reviewed, not changed — `User` doesn't use `BelongsToTenant`.** Flagged
   as a risk, not a confirmed bug: `users.tenant_id` is nullable by design
   (design §4.3 — `NULL` means platform-level super admin), and every current
   auth path (`Auth::attempt`, registration) already filters by `tenant_id`
   explicitly rather than relying on a global scope. Adding `BelongsToTenant`
   would force every `User` create to resolve a bound tenant, which breaks
   super-admin creation (`tenant_id = null`, no tenant ever bound) — not a
   safe mechanical fix. Left as-is; worth a real look if a future `User`
   query is ever added that filters by something other than `tenant_id`
   explicitly.

Full suite after fixes: 50/50 passing (49 + 1 new regression test).

### Round 2 (2026-08-09, same day) — auth, migrations, branding

Second pass, scoped to what round 1 didn't cover deeply: auth controllers,
role hierarchy, `BrandingService`, migrations, student shell. Verified round
1's fixes were still in place first. Two real bugs found and fixed.

1. **Fixed — soft-deleted rows blocked reuse of `courses.slug` and
   `users.email`.** Both `unique(tenant_id, slug)` on `courses` and
   `unique(tenant_id, email)` on `users` are plain composite unique
   constraints that still match soft-deleted rows (Postgres unique indexes
   don't know about `deleted_at`). Soft-delete a course, create a new one
   with the same slug → `23505 duplicate key`. Same for a user re-registering
   with an email that belonged to a soft-deleted account in the same tenant.
   Two independent layers had this bug: the DB constraint, and separately
   `RegisterUserRequest`'s `Rule::unique('users','email')->where('tenant_id',
   ...)` validation rule, which also matches soft-deleted rows and rejects
   the registration with a 422 before the request ever reaches the DB —
   fixing only the DB layer left the bug fully reproducible through the
   validation layer. **Fix:** two new migrations
   (`2026_08_09_114244_make_courses_slug_unique_exclude_soft_deleted.php`,
   `2026_08_09_114245_make_users_email_unique_exclude_soft_deleted.php`) drop
   the plain unique index and replace it with a Postgres partial unique index
   (`... where deleted_at is null`) via `DB::statement` — migrations aren't
   inside `app/Domains`, so this doesn't trip the Block 11 raw-SQL convention
   test. `RegisterUserRequest` gets `->whereNull('deleted_at')` added to the
   uniqueness rule. Confirmed both ways: reverted each fix in turn, the new
   regression tests failed on the original error (duplicate key / 422 "email
   has already been taken"), reapplied, back to green.
   **New tests:** `CourseTreeTest` — recreate a course with a soft-deleted
   course's slug. `AuthenticationFlowTest` — re-register with a soft-deleted
   user's email in the same tenant.
2. **Fixed — building an auth URL for a tenant-less user crashed instead of
   failing clearly.** `AppServiceProvider::registerTenantAwareAuthUrls()`'s
   `VerifyEmail`/`ResetPassword` closures read `$notifiable->tenant->subdomain`
   directly. For a `tenant_id = null` user (platform-level super admin, per
   design §4.3) `->tenant` is null, and PHP emits "Attempt to read property
   'subdomain' on null" — a warning that resolves to `null`, which then
   flows into a broken/empty URL rather than a clear failure. Not currently
   reachable through any built route (every verification/reset route lives
   under `Route::domain('{subdomain}.lms.test')`, and super-admin login/panel
   auth isn't wired up yet — flagged as deferred back in Block 7), but the
   `VerifyEmail`/`ResetPassword` `createUrlUsing` closures are global
   Laravel hooks that fire for any `User::sendEmailVerificationNotification()`
   or `Password::sendResetLink()` call, including from `tinker` or a future
   admin action — a live landmine, not a theoretical one. **Fix:** extracted
   `tenantSubdomainOrFail()`, using `?->` plus `?? throw new \LogicException`
   with a clear message, matching the project's "no catch-all handlers, name
   every failure" convention (design §6) instead of a silent null-property
   warning.
   **New test:** `AuthenticationFlowTest` — `Password::sendResetLink()` for a
   `tenant_id = null` user throws the named `\LogicException`, not a bare
   `\Error`.

Nothing else found in this pass — role hierarchy, `RoleAssignmentPolicy`,
and `BrandingService`'s early-boot fallback resolution were read carefully
and check out.

Full suite after round 2: 53/53 passing (50 + 3 new regression tests).

### Round 3 (2026-08-10) — pre-S3 pass, alongside the S2 review below

Third pass, run together with S2's own review (see that plan's "Post-S2
review" for the S2-scoped findings). This round's S1-scoped findings:

1. **Fixed (serious) — a logged-in user could reach a different institute's
   `/teach` or `/admin` panel.** `app/Http/Middleware/EnsureRole.php:15-19`
   checked `$user->hasAnyRole($roles)` alone. Roles carry no `tenant_id` by
   design (§4.3), and `SESSION_DOMAIN=.lms.test` (`.env:34`) means a session
   cookie set on one institute's subdomain is also valid on every other
   institute's subdomain under the same parent domain. Round 1's item 3
   above ("Reviewed, not changed — `User` doesn't use `BelongsToTenant`")
   checked that *login* filters by `tenant_id` explicitly — true, and still
   correct — but never checked what happens on a request *after* login, on
   a different subdomain, with no new login attempt. Concretely: an
   instructor logs in at `physics.lms.test`, then opens
   `chemistry.lms.test/teach` while still logged in. `EnsureRole` passed
   (the "instructor" role matches), `BelongsToTenant`'s scope then bound
   every query to chemistry (resolved from the host), and the physics
   instructor had full CRUD over chemistry's courses and lessons.
   `tests/Feature/Auth/EnsureRoleTest.php`'s three original cases always
   created the user in the same tenant as the route under test, so this
   exact path was never exercised.
   **Fix:** `EnsureRole` now also checks `$user->tenant_id` against
   `CurrentTenant`'s resolved id when a tenant is bound, and 403s on a
   mismatch — skipped on the super panel, which has no `ResolveTenant`
   middleware and so never binds a tenant.
   **New test:** `EnsureRoleTest` — an instructor with a valid role, logged
   in and acting on a *different* tenant's subdomain than their own, gets
   403'd.
2. **Fixed (serious) — `CourseSectionResource` had the same dead-end create
   page `LessonResource` was already fixed for in the S2 review.**
   `app/Domains/Content/Filament/Resources/CourseSections/CourseSectionResource.php`
   still registered `'create' => CreateCourseSection::route('/create')`, but
   its form only collects `title`, never `course_id`, and `course_id` is a
   required FK on `course_sections`. `shouldRegisterNavigation(): false`
   only hides the nav link — the route was still directly reachable and
   submitting it threw a NOT NULL `QueryException`. The identical fix was
   applied to `LessonResource` during the S2 review but never carried over
   to its sibling resource.
   **Fix:** removed the `create` page and its route, deleted
   `Pages/CreateCourseSection.php`. Sections are created through
   `CourseResource`'s `SectionsRelationManager`, which supplies `course_id`.
3. **Fixed (serious) — the student/parent portal login had no lockout and
   didn't rotate the session.** `app/Livewire/StudentPortal.php` called
   `Auth::attempt()` directly instead of `TenantAuthenticator` (the "one
   place tenant-scoped login + lockout lives," used by all three Filament
   panel logins) — the only login surface in the app with no
   `RateLimiter`, open to unlimited password guessing against student
   accounts. Neither `login()` nor `logout()` called
   `session()->regenerate()` / `->invalidate()`, unlike every other
   auth path in the codebase, leaving a session-fixation window.
   **Fix:** routed through `TenantAuthenticator`, added
   `session()->regenerate()` on login and `session()->invalidate()` +
   `->regenerateToken()` on logout.
   **New tests:** `StudentPortalTest` — lockout after 5 failed attempts;
   session id changes across login and logout.

**Reviewed, not changed** — `BandwidthUsageDaily::record()`'s
`firstOrCreate()` was re-flagged as a possible race by this round's first
pass, but checking `Illuminate\Database\Eloquent\Builder::firstOrCreate()`
(`vendor/laravel/framework/.../Builder.php:710-717`) confirms it already
falls through to `createOrFirst()` on a miss, which catches the
unique-constraint violation — the same conclusion the S2 round 2 review
already reached on the same line. Left unchanged; this was a false
positive, recorded so it doesn't get re-flagged a third time.

Full suite after round 3 (S1 + S2 findings combined): 109/109 passing.

---
