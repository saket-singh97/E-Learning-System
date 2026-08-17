# Design: S12 Security & Resilience Hardening — AI-Native LMS

## 1. Why this document exists

Locks scope and architecture for S12 before any block is built, same role
S9-S11's own design docs played. Full block-by-block build log lives in the
companion `2026-08-17-s12-security-resilience-plan.md`.

S12 is the fourth P2-backlog slice (after S9 hardening, S10 assignments/
gradebook/certificates, S11 billing). Scope per `docs/ROADMAP.md`'s own S12
entry: `docs/FEATURES.md` **Section B** (auth/session/audit/GDPR items B9-B13,
B15) + **Section V-a** (resilience/correctness items V1-V5). 11 items total,
matching the roadmap's own count.

---

## 2. S12 scope

### 2.1 In scope

| # | Feature | Source |
|---|---|---|
| B9 | Device fingerprinting + registered-device limit (default 2) | REQ-33 |
| B10 | Concurrent session limit + remote device logout | REQ-33 |
| B11 | Login history, geolocation, impossible-travel detection | REQ-34 |
| B12 | Account-sharing detection + forced re-auth | REQ-36 |
| B13 | Immutable append-only audit log, 365-day retention | REQ-35 |
| B15 | GDPR / India DPDP: consent, data export, deletion, cookie consent | REQ-37 |
| V1 | Circuit breaker on every external provider | resilience |
| V2 | Explicit cache invalidation on write | resilience |
| V3 | Soft deletes on recoverable entities | resilience |
| V4 | Form Request validation on every endpoint | resilience |
| V5 | Eloquent-only data access, no raw SQL | resilience |

### 2.2 Verified current state (read before writing this, not assumed)

- **B9-B12 (device/session/login-history/sharing): fully greenfield.**
  Grepped every migration for `last_login`/`device_id`/`login_at` — zero
  hits. No session/device schema exists anywhere. `AuthenticatedSessionController`
  (S1) does a plain `Auth::attempt()` with no device or session bookkeeping
  at all.
- **B13 (audit log): partially exists, narrowly scoped.** `RoleAssignmentAudit`
  (S1) and `ImpersonationAudit` (S9 B3) each log exactly one action type,
  neither has a retention policy, and neither is a general-purpose sink other
  domains can write to. No `queue:prune`-style sweep exists for either. B13
  is a **new, general** audit log every sensitive action funnels into — not a
  third bespoke table.
- **B15 (GDPR/DPDP): zero existing surface.** Grepped `app/` and
  `database/migrations/` for `gdpr`/`dpdp`/`consent` — no hits. No cookie
  banner, no export endpoint, no deletion workflow anywhere.
- **V1 (circuit breaker): zero existing surface.** Grepped for
  `circuit.?breaker` — no hits. Every `Integrations/` driver (Bunny, Claude,
  Razorpay x2, WhatsApp BSP, live-class) retries with backoff (S9 B10's own
  `$tries`/`backoff()` convention on queued jobs) but nothing trips open on
  sustained failure — a slow-failing provider degrades the whole app instead
  of failing fast.
- **V2 (cache invalidation): zero existing cache to invalidate.** Grepped for
  `Cache::` app-wide — zero hits. This item is really two items in one:
  stand up a cache layer for the read-heavy computed aggregates that don't
  have one yet (`GradebookService::scoreFor()`, `StudentMetrics::summarize()`,
  both already documented in `HANDOVER.md` as "not truly DB-sortable... no
  materialized column"), then wire explicit bust-on-write for each.
- **V3 (soft deletes): mostly done, 4 real gaps.** `SoftDeletes` trait
  confirmed present on `User`, `Course`, `CourseSection`, `Lesson`, `Batch`,
  `Enrolment`, `Question` (grepped `app/Domains` + `app/Models`). **Missing**
  on the exact entity V3 names explicitly — payments: `FeePayment`,
  `FeeInvoice` (Fees domain), plus their S11 billing-domain equivalents
  `TenantSubscription`, `SubscriptionInvoice` — none of the four use the
  trait today, confirmed by grep. A mis-click hard-deletes a real invoice or
  subscription row with no recovery path.
- **V4 (Form Request): 4 exist, ~26 plain controllers total, 2 real gaps.**
  `app/Http/Requests/` has exactly 4 classes. Of the 26 plain (non-Filament)
  controllers under `app/Http/Controllers/`, most are route-model-bound
  download/show/webhook actions taking no validated user input at all —
  Form Requests don't apply to them. Grepped for inline
  `$request->validate(`: exactly 2 hits —
  `Auth/AuthenticatedSessionController.php` and
  `Content/WatchHeartbeatController.php`. Filament panels validate through
  their own schema layer already (a separate, already-correct mechanism);
  V4's real scope is these 2 inline calls plus confirming every other
  input-accepting plain controller (the `Auth/` family: registration,
  password reset) already routes through a Form Request — most already do.
- **V5 (no raw SQL): already done, zero gap.** `grep -rn "DB::raw\|whereRaw\|DB::statement" app/Domains` returns 0 hits, and
  `tests/Feature/Security/SecurityBaselineTest.php` already enforces this as
  a regression gate (per `CLAUDE.md`). V5 needs no new code — just a
  confirmation block and a note that the existing guard already covers it,
  so nothing regresses unnoticed.

### 2.3 Out of scope, and where each goes

- **B1-B8, B14 (register/login/2FA/roles/parent-role/CSRF baseline)** —
  already shipped across S1 and S9. Not re-touched here.
- **V6/V7 (SETUP.md currency, server hardening)** — ops/deployment concerns,
  not application code; no block needed, just keep `SETUP.md` current as new
  env vars land in this slice (Sentry alert wiring for V1, if a new key is
  needed).
- **V8-V15 (read replicas, horizontal scaling, feature flags, SSO, data
  import, strangler cutover)** — all P2/P3, explicitly scheduled for
  S20/S21 per `docs/ROADMAP.md`. Read replicas and horizontal scaling in
  particular need real production load to size correctly; building them
  now against zero traffic is speculative.
- **A per-provider circuit-breaker dashboard as a full new Filament
  resource** — V1 needs breaker state *visible*, not a dedicated CRUD
  surface. A read-only widget on the existing Super-panel/Admin-panel
  overview pages is enough; a new resource would be over-building for a
  status display.

---

## 3. Schema

### 3.1 New `Security` domain

`app/Domains/Security/{Models,Services,Filament,Jobs,Policies}/` — a new
domain, not folded into `Tenancy`. Rationale: these features are about
**how any user authenticates and is watched**, orthogonal to `Tenancy`'s own
job (who belongs to which institute, roles, branding). Mirrors why `Billing`
became its own domain in S11 rather than living inside `Tenancy` — a
different concern that happens to touch the same `User`/`Tenant` models.

```
device_sessions
  id, tenant_id, user_id, device_fingerprint (hash), device_label,
  ip_address, user_agent, last_seen_at, revoked_at (nullable), timestamps

login_history
  id, tenant_id, user_id, ip_address, country, city (nullable, from IP
  lookup), device_fingerprint, successful (bool), flagged_impossible_travel
  (bool, default false), created_at only (append-only, no updated_at)

account_sharing_flags
  id, tenant_id, user_id, distinct_device_count_7d, flagged_at,
  reviewed_at (nullable), timestamps

audit_log_entries
  id, tenant_id (nullable — platform-level actions have none, same
  reasoning ImpersonationAudit already established), actor_id (nullable —
  a system-initiated sweep has no human actor), action (string, e.g.
  'plan.updated', 'tenant.suspended'), subject_type, subject_id,
  metadata (json), ip_address (nullable), created_at only (append-only)

gdpr_export_requests
  id, tenant_id, user_id, requested_by_id, status (pending|processing|
  ready|failed), export_disk_path (nullable), expires_at, timestamps

gdpr_deletion_requests
  id, tenant_id, user_id, requested_by_id, status (pending|processing|
  completed|failed), scheduled_for, completed_at (nullable), timestamps

cookie_consents
  id, tenant_id (nullable — a consent can be recorded pre-login on the
  marketing/login page, before any tenant context resolves), session_id,
  user_id (nullable, filled in once the visitor authenticates),
  categories (json: analytics/marketing/necessary), consented_at
```

`device_sessions`, `login_history`, `account_sharing_flags`,
`gdpr_export_requests`, `gdpr_deletion_requests` are all `BelongsToTenant`
and registered in `TenantModelRegistry` — every row's user is a real,
currently-tenant-scoped account, no platform-level-actor case the way
impersonation has. `audit_log_entries` and `cookie_consents` are
**deliberately not** `BelongsToTenant` (explicit nullable `tenant_id`
column instead, same shape `ImpersonationAudit` already established in
S9 B3) — both need to record platform-level and pre-tenant-context events
respectively, which the trait's `creating()` hook would reject outright.

### 3.2 Modified existing tables

- `fee_payments`, `fee_invoices` (Fees domain), `tenant_subscriptions`,
  `subscription_invoices` (Billing domain) — add `deleted_at`, add
  `SoftDeletes` to all four models (V3's real gap).
- No new columns on `users` — device/session/login state lives in the new
  tables above, not bolted onto the shared model (matches
  `HANDOVER.md`'s own standing "Avoid" note against adding
  domain-specific concerns onto `App\Models\User` directly).

---

## 4. Design decisions

1. **Device fingerprint is a hash of UA + a client-set cookie ID, not a
   paid fingerprinting vendor.** No budget signal anywhere in this app for
   a third-party device-ID service (FingerprintJS etc.); a stable
   first-party cookie plus user-agent hash is the same precision level
   most session-limit implementations actually use, and it's free. Documented
   as a real limitation (a cleared cookie resets the fingerprint) rather than
   glossed over.
2. **Session limit is enforced at login time, not via a middleware on
   every request.** Checking `device_sessions` count on every request would
   add a query to every single page load; instead, `AuthenticatedSessionController`
   checks the limit once at the moment a new session is created (the same
   point `SeatQuota`'s enforcement-at-point-of-action pattern from S11
   already established for a different resource), and remote logout revokes
   the row immediately, checked lazily on that device's *next* request via
   existing session middleware (a revoked device gets logged out on its next
   navigation, not instantly — acceptable latency, same class of tradeoff
   this app already accepts for `Tenant::isSuspended()`'s own lazy check).
3. **Impossible-travel detection is a simple velocity check (distance /
   time > plausible max speed), not a geolocation vendor subscription.**
   IP-to-city resolution needs *some* data source; reuse `RealDnsResolver`'s
   own "no vendor account needed" posture — a free/bundled IP-geolocation
   library (e.g. a local MaxMind GeoLite2 database) rather than a paid API,
   flagged in the plan doc as the concrete library choice for whichever
   block builds it.
4. **Account-sharing detection is a daily sweep, not real-time.** Same
   "state transition that depends on elapsed time/pattern, not a single
   event" reasoning `HANDOVER.md` already documents for why every
   time-windowed check in this app (`SuspendOverdueTenantSubscriptions`,
   `FlagOverdueInvoices`) is a scheduled sweep, not computed lazily on read.
   Counts distinct `device_sessions` fingerprints per user over a rolling
   7-day window; over a threshold (default 4, configurable) flags the
   account and forces re-auth on its next request (session invalidated,
   not the account locked — a false positive shouldn't lock a real user
   out).
5. **`audit_log_entries` is written to synchronously, not queued.** The
   whole point of an audit log is that the write survives even if a queue
   worker later fails or a job gets lost — queuing the audit write itself
   would undermine the guarantee. A `AuditLogger::record()` service call,
   not an event listener with retry semantics.
6. **365-day retention is a monthly sweep job, not a database-level TTL.**
   Postgres has no native row-TTL; `PruneAuditLogEntries` (monthly, matching
   S9 B14's own `queue:prune-failed --hours=720` precedent for "a scheduled
   prune, not an ad-hoc cleanup script") deletes rows past 365 days. Hard
   delete, not soft — an audit log entry that's aged past its own retention
   window has nothing left to "restore."
7. **GDPR export is an async job producing a downloadable zip, not a
   synchronous request.** A full data export (every domain's rows for one
   user — enrolments, submissions, certificates, fee history, quiz
   attempts) can be large and slow; mirrors `ExportRevenueRegister`'s own
   `ShouldQueue` + `Storage::disk('local')` + signed-download-link shape
   (S9 B11), not a new pattern.
8. **GDPR deletion is anonymization, not row deletion, for anything with
   a legal/financial retention requirement.** A paid `FeeInvoice` (GST
   invoice, India's own tax retention rules) or a `SubscriptionInvoice`
   cannot simply vanish on a user's deletion request without breaking
   compliance elsewhere; `GdprDeletionService` nulls/hashes PII columns
   (`name`, `email`, `phone`) on rows the law requires kept, and hard-deletes
   rows with no such requirement (quiz attempts, video-watch events).
   Scheduled (`scheduled_for`, typically +30 days, a grace window to
   cancel), not immediate — matches how every other destructive action in
   this app (`cancelSubscription`) already requires confirmation before an
   irreversible step.
9. **Cookie consent is stored per-session before login, then reconciled
   to the user once authenticated.** The banner has to work for an
   anonymous visitor on the login page itself, before any `user_id` exists
   — `cookie_consents.user_id` starts null, backfilled on the same session
   ID once `Auth::login()` fires, mirroring how `ParentInviteCode`
   redemption (S9 B5) already handles "real state exists before a User
   row is attached to it."
10. **Circuit breaker state lives in Redis, not the database.** Breaker
    state (open/closed/half-open, failure count, cooldown expiry) is
    inherently ephemeral and needs to be read on every provider call with
    near-zero latency — this app already uses Redis for cache/queue/session
    (confirmed in `config/`), so a `CircuitBreaker` service backed by Redis
    keys (`TTL`-based cooldown, atomic `INCR` for failure count) needs no
    new infrastructure. A new `app/Support/Resilience/CircuitBreaker.php`,
    wrapped around each `Integrations/` driver's outbound call — same
    "shared, cross-domain code lives outside `Domains/`" placement
    `CLAUDE.md` already establishes for `Support/`.
11. **Cache layer targets computed aggregates only, not raw model reads.**
    Caching arbitrary Eloquent queries app-wide is a much bigger surface
    than this slice's own scope; `V2`'s own motivating example (a teacher
    edits a lesson, stale data lingers) is specifically about the
    already-flagged-as-slow computed aggregates
    (`GradebookService::scoreFor()`, `StudentMetrics::summarize()`,
    `InstituteOverviewWidget`'s own numbers) — cache those under a
    tenant+entity-scoped key, bust on the specific model events that can
    change the underlying data (`AssignmentSubmission` graded,
    `QuizAttempt` submitted, `AttendanceRecord` created/updated).
12. **Soft-delete restore gets a real admin UI action, not just a
    database-level flag.** `V3`'s own text is explicit: "Restore must be
    exposed in the admin panel, not only at the database level." Each of
    the 4 newly-soft-deleted resources' `ListRecords` page gains a
    `TrashedFilter` + `RestoreAction`, same Filament-native pattern already
    proven safe for `Course`/`Batch`/`Lesson` (S1/S4), not a new mechanism.

---

## 5. Testing strategy

- **Unit/feature per service**: `DeviceFingerprintService`, `SessionLimiter`,
  `ImpossibleTravelDetector`, `AccountSharingDetector`, `AuditLogger`,
  `GdprExportService`, `GdprDeletionService`, `CircuitBreaker` each get a
  dedicated test file, same one-file-per-unit convention every prior slice
  used.
- **Cross-tenant isolation**: the 5 new `BelongsToTenant` models register in
  `TenantModelRegistry`, auto-generating `CrossTenantTest` coverage — no
  hand-written per-model isolation tests (`CLAUDE.md`'s own rule).
- **Circuit breaker**: fake a provider driver that fails N times in a row,
  assert the breaker trips (fails fast, no Nth+1 real call attempted), then
  assert a half-open probe after the cooldown window restores it — the same
  "can't test true concurrency, can test the state machine's own logic"
  honesty this session's own `StorageQuota`/`PlanChangeService` race-fix
  tests already modeled (S11 B15/B16).
- **GDPR deletion**: assert PII columns are actually null/hashed post-
  deletion on a retained-for-compliance row, and assert a fully-removable
  row (e.g. a `QuizAttempt`) is gone — not just that the request's own
  status flipped to `completed`.
- **Soft-delete restore**: one test per newly-covered model confirming
  `->delete()` sets `deleted_at` (not a hard delete), `withTrashed()` finds
  it, and the tenant scope still excludes a *different* tenant's
  soft-deleted row when `withTrashed()` is used (`V3`'s own explicit
  warning about this exact interaction).
- Design doc §3.5 estimate (a rough anchor, not a target, same posture
  every prior slice's own estimate takes): ~70-85 new tests across 15
  blocks — this slice touches 11 independently-testable feature areas plus
  a dedicated review-pass block, wider than S11's 5-model billing surface
  but each individual piece is smaller.

---

## 6. Open questions, locked with a default rather than left blocking

- **Impossible-travel plausible-max-speed threshold** — defaulted to 900
  km/h (commercial flight speed) unless the plan doc's own block note
  narrows it; a config value, not hardcoded, so it's tunable without a
  redeploy.
- **Account-sharing device-count threshold** — defaulted to 4 distinct
  devices in 7 days; also a config value.
- **GDPR deletion grace window** — defaulted to 30 days (`scheduled_for`),
  matching typical DPDP/GDPR "reasonable time to reconsider" practice; not
  a hardcoded legal opinion, flagged as a business decision the actual
  owner can override before this ships to production.
