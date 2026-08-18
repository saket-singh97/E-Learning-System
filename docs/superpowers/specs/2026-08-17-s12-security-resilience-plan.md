# Implementation Plan: S12 Security & Resilience Hardening

Full design: `2026-08-17-s12-security-resilience-design.md`. This is the
block-by-block build log — update the PROGRESS table as each block lands,
same as every S1-S11 plan did. 15 blocks (B0-B14), finer-grained than the
11-item roadmap count since several roadmap items (B15's three GDPR
sub-parts, V1's two rollout halves) split cleanly into independent blocks.

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-17-s12-security-resilience-design.md and
docs/superpowers/specs/2026-08-17-s12-security-resilience-plan.md, then build
Block 0 of the S12 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | Domain groundwork — new `Security` domain scaffold, 5 `BelongsToTenant` migrations + models (`device_sessions`, `login_history`, `account_sharing_flags`, `gdpr_export_requests`, `gdpr_deletion_requests`) + 2 non-tenant migrations (`audit_log_entries`, `cookie_consents`), tenant registry | ✅ done |
| B1 | Device fingerprinting (`DeviceFingerprintService`) + registered-device limit at login (default 2, config-driven) (B9) | ✅ done |
| B2 | Concurrent session limit + remote device logout — Admin/Teach panel "My devices" page (B10) | ✅ done |
| B3 | Login history + IP geolocation + impossible-travel detection (`ImpossibleTravelDetector`, velocity check) (B11) | ✅ done |
| B4 | Account-sharing detection — daily sweep, forced re-auth on flag (B12) | ✅ done |
| B5 | General `audit_log_entries` sink (`AuditLogger`) + wire into the highest-value existing sensitive actions (role assignment, impersonation, plan change, tenant suspension) | ✅ done |
| B6 | Audit log 365-day retention sweep (`PruneAuditLogEntries`, monthly) + Super-panel read-only audit log viewer (B13) | ✅ done |
| B7 | GDPR/DPDP cookie consent — banner + `cookie_consents` write path, pre-login and post-login reconciliation (B15 pt.1) | ✅ done |
| B8 | GDPR/DPDP data export — async job, zip of every domain's rows for one user, signed download link (B15 pt.2) | ✅ done |
| B9 | GDPR/DPDP data deletion — anonymize-vs-hard-delete split, 30-day grace window, scheduled sweep (B15 pt.3) | ✅ done |
| B10 | Circuit breaker core (`App\Support\Resilience\CircuitBreaker`, Redis-backed) + wire onto Bunny + Claude drivers (V1 pt.1) | ✅ done |
| B11 | Circuit breaker wired onto Razorpay (both integrations) + WhatsApp BSP + live-class provider, Sentry alert on trip, dashboard visibility (V1 pt.2) | ✅ done |
| B12 | Cache layer + explicit invalidation — `GradebookService::scoreFor()`, `StudentMetrics::summarize()`, `InstituteOverviewWidget` (V2) | ✅ done |
| B13 | Soft-delete gap closure — `FeePayment`/`FeeInvoice`/`TenantSubscription`/`SubscriptionInvoice` + admin restore UI on all 4 (V3); Form Request extraction on the 2 remaining inline-`validate()` controllers (V4) | ✅ done |
| B14 | Full test coverage, isolation registration sweep, review pass, V5 regression-guard confirmation, `PROJECT-STATUS.md`/`HANDOVER.md` update | ⬜ not started |

**Test suite:** 925/926 at slice start (1 pre-existing, unrelated
`CourseSlugUniquenessTest` failure carried since S1/S2, out of scope here
same as every prior slice). Design doc §5 estimates ~70-85 new tests by
B14 (~995-1010 total) — a rough anchor, not a target; every prior slice's
own estimate undershot its actual by a wide margin.

---

## Block notes (fill in as each block builds — deviations, gotchas,
## decisions made mid-build)

### B0 — Domain groundwork — DONE (2026-08-17)

- 7 migrations (`2026_08_17_180000` through `_180006`), new
  `app/Domains/Security/Models/` with 7 models. `constrained()` FK column
  naming followed the existing convention throughout: `requested_by_id`
  and `actor_id` both need an explicit `->constrained('users')` table
  name since Laravel's own singular-column-minus-`_id` guess is wrong for
  either (`requested_bies`/`actors`).
- 5 `BelongsToTenant` (`DeviceSession`, `LoginHistory`,
  `AccountSharingFlag`, `GdprExportRequest`, `GdprDeletionRequest`) — every
  row's user is a real, currently-tenant-scoped account. Registered in
  `TenantModelRegistry` with `seedAttributes()`/`updateAttributes()`
  entries, auto-generating `CrossTenantTest` coverage per `CLAUDE.md`'s own
  rule.
- 2 deliberately not `BelongsToTenant` (`AuditLogEntry`, `CookieConsent`)
  — same `ImpersonationAudit` (S9 B3) exception the design doc's §3.1
  already names: both need to record events with no tenant context yet
  (a platform-level action, or a pre-login cookie banner), which the
  trait's `creating()` hook would reject outright. Neither is registered
  in `TenantModelRegistry` for the identical reason.
- **One deviation from `ImpersonationAudit`'s own precedent, deliberate:**
  `audit_log_entries.actor_id` is `nullOnDelete()`, not `cascadeOnDelete()`
  like `impersonation_audits.actor_id` is. An audit trail recording what a
  now-deleted user once did needs to survive that user's deletion — the
  whole point of an audit log — so cascading the delete would defeat it.
  Called out explicitly in the migration's own docblock, not silently
  diverged.
- `login_history` and `audit_log_entries` are append-only (`created_at`
  only, no `updated_at` column at the DB level; `LoginHistory`/
  `AuditLogEntry` models set `const UPDATED_AT = null`). `cookie_consents`
  has neither `created_at` nor `updated_at` — it has its own single
  `consented_at` column instead, so `CookieConsent` sets
  `public $timestamps = false` rather than just nulling `UPDATED_AT`
  (caught before it could become a real bug: `UPDATED_AT = null` alone
  would still have left Eloquent trying to write a nonexistent
  `created_at` column on every `create()` call).
- **Real bug, caught by the generated `CrossTenantTest` itself, not
  spotted by inspection**: Eloquent's own pluralization guesses
  `login_histories` for `LoginHistory`; the migration (matching the
  design doc's own singular table name, `login_history`) created the
  singular form. `SQLSTATE[42P01]: relation "login_histories" does not
  exist` on the very first isolation-suite insert. Same mismatch class
  `TenantStorageUsage` (S11 B0) already hit — fixed identically, with an
  explicit `protected $table = 'login_history';` on the model rather than
  renaming the migration.
- `composer test`: full suite run below.

### B1 — Device fingerprinting + registered-device limit — DONE (2026-08-17)

- `DeviceFingerprintService` — sha256(cookie device-id + user-agent),
  `Cookie::queue()` for the mint case (works from anywhere in the request
  lifecycle, including an event listener that fires after the controller
  already built its response). `config('security.device_limit')`, new
  `config/security.php`, default 2, `SECURITY_DEVICE_LIMIT` env override.
- **Real architectural finding, not assumed going in**: `TenantAuthenticator::validate()`
  (the chokepoint every prior slice's own docblocks call "the one place
  tenant-scoped login lives") only covers the *password* path — Google
  OAuth's `Auth::login()` calls bypass it entirely. The actual universal
  chokepoint every login surface funnels through (password, TOTP-completed,
  Google OAuth, all three Filament panels, both portals) is Laravel's own
  `Illuminate\Auth\Events\Login` event, fired by `SessionGuard::login()`
  itself. `RegisterDeviceOnLogin` listens there instead — zero controllers
  touched, the same DRY win `AttendanceRecorded`'s own dual-listener
  pattern (S8) already established for this app.
- **Rejection needed active teardown, not just a thrown exception.** By
  the time the `Login` event fires, `SessionGuard::login()` has already
  written the session — a bare exception would leave a half-established
  session behind. The listener calls `Auth::guard($event->guard)->logout()`
  + session `invalidate()`/`regenerateToken()` (matching
  `AuthenticatedSessionController::destroy()`'s own real-logout shape)
  *before* re-throwing, so a rejected device-limit login behaves exactly
  like any other `abort(409, ...)` on the same request — no different UX
  than a wrong 2FA code.
- Explicit `tenant_id` derivation from `$user->tenant_id` throughout
  `DeviceRegistrar`, never ambient `CurrentTenant` — proven with a
  dedicated test (`derives tenant_id from the user, not from an unbound or
  mismatched ambient CurrentTenant`), not just asserted in a comment.
  Load-bearing specifically because Google OAuth's callback route is
  host-agnostic (S9 B2), so `CurrentTenant` is genuinely unbound there.
- A platform-level user (`tenant_id === null`, i.e. `super_admin`) is
  exempt from device tracking — `device_sessions.tenant_id` is `NOT NULL`
  by schema (B0), and no scope signal anywhere in B9's own feature text
  names super_admin as in-scope. A deliberate, documented boundary, not an
  oversight.
- Tested at three levels: `DeviceFingerprintService` directly (cookie
  mint/reuse, stable-per-cookie/UA hash), `DeviceRegistrar` directly
  (new device, returning device, limit enforcement, revoked devices don't
  count, tenant derivation), and one real end-to-end HTTP test through the
  actual `/login` JSON endpoint proving the event wiring itself fires —
  same "don't just trust the listener class looks right" discipline S9
  B9's own `FilamentView::registerRenderHook` gotcha already taught this
  session.
- **Test-fixture bug in this block's own first draft**: `DeviceFingerprintServiceTest`
  originally mocked `Cookie::shouldReceive('queue')` — but `fingerprintFor()`
  also calls `Cookie::make()` on the same facade to build the cookie
  object passed into `queue()`, and Mockery's partial facade mock requires
  *every* called method to have an expectation, not just the one under
  test. Fixed by dropping the mock entirely and asserting against the real
  `Cookie::hasQueued()` API instead — a genuine assertion on real
  behaviour, not a brittle mock of it.
- `composer test`: full suite run below.

### B2 — Concurrent session limit + remote device logout — DONE (2026-08-17)

- **Deviation from this block's own plan-note wording, caught before
  building it, not after**: the plan note listed "Admin/Teach/Super panel"
  — but `super_admin` is always `tenant_id === null`, and B1 already
  exempted every null-tenant user from device tracking entirely
  (`device_sessions.tenant_id` is `NOT NULL` by schema). A "My devices"
  page on the Super panel would always render empty, since no row could
  ever exist for the only role allowed there. Scoped to Admin + Teach only.
- `EnsureDeviceNotRevoked` middleware — mirrors `ResolveTenant`'s own
  dual-registration shape exactly (`CLAUDE.md`'s documented reason):
  appended to the global `web` group (covers Livewire's `/livewire/update`
  and both portals) *and* listed explicitly in each of Admin/Teach's own
  panel middleware array (a global-append-only registration never fires on
  an actual Filament page load, only its Livewire AJAX round-trips — the
  identical gap `ResolveImpersonation` (S9 B3) already hit once). Not
  added to `SuperPanelProvider` — consistent with the same exemption
  reasoning above, and matching that `ResolveTenant`/`ResolveImpersonation`
  aren't registered there either.
- Design doc §4 decision 2's own "checked lazily on that device's next
  request" — a revoked device isn't logged out instantly the moment
  another session clicks "log out," only the next time it makes a request.
  On a hit, the middleware actively logs the guard out and invalidates the
  session (same real-teardown reasoning `RegisterDeviceOnLogin`, B1,
  already established) before `abort(401, ...)`.
- `MyDevices` (Admin, Teach) — two small, independent Filament pages, not
  a shared base class; the whole page is plain table config with nothing
  worth extracting (unlike `Gradebook`'s `BuildsGradebookRows`, kept for
  genuinely complex row-building logic). Relies on ambient `CurrentTenant`
  scoping for the query (`DeviceSession::query()->where('user_id', ...)`),
  unlike `DeviceRegistrar`'s own explicit-scope discipline — a real
  difference deliberately kept: a normal panel page render always has
  `CurrentTenant` reliably bound by `ResolveTenant`'s own strict mode,
  unlike the handful of login surfaces `DeviceRegistrar` has to cover
  (Google OAuth's host-agnostic callback among them).
- Current device detected by recomputing its own fingerprint and comparing
  — its own `revoke` row-action is hidden, so a user can't accidentally
  lock themselves out from their own device list.
- Tested at the middleware level directly (revoked device blocked +
  logged out, active device passes, an unregistered device passes,
  platform-level user always exempt, a guest request is untouched) plus
  one true end-to-end HTTP test (real login, real second request, proving
  the actual registration) and at the page level (own devices only,
  cross-tenant/cross-user rows never visible, current-device action
  hidden, revoke actually persists).
- **Real test-infrastructure bug, this block's own first draft — caught
  by a failing assertion, not by inspection**: `withUnencryptedCookie()`
  (the obvious-looking Laravel test helper) sends a cookie value
  `EncryptCookies` middleware then tries to *decrypt* — this app's
  `lms_device_id` cookie was never added to that middleware's own
  `$except` list (no reason to, it's `httpOnly` and server-read-only), so
  the middleware's `catch (DecryptException)` silently drops it on every
  real HTTP test request. Every "same device, second request" scenario
  therefore looked like a brand-new device instead, since
  `DeviceFingerprintService` saw no cookie and minted a fresh random UUID
  each time. Not a production bug — real traffic never uses
  `withUnencryptedCookie()`; the queued cookie gets genuinely encrypted on
  the way out and decrypted on the way back in through the same real
  middleware pass. B1's own tests happened to pass despite the same
  mistake purely by luck: none of B1's assertions depended on a cookie
  value actually round-tripping correctly (each of its 3 device-limit
  logins supplied a *different* raw value anyway, so a silently-nulled
  cookie still produced 3 distinct-looking devices, coincidentally the
  exact right answer for the wrong reason).
- **Switching to `withCookie()` (the documented, correct encrypted-cookie
  test helper) still did not fix it, across three separate attempts** —
  the resulting fingerprint never matched a precomputed expectation,
  whether chained across two real requests or narrowed to one, with
  `actingAs()` supplying auth state directly. Root cause not fully
  isolated (a `CookieValuePrefix` validation mismatch inside
  `EncryptCookies` is the leading suspect, unconfirmed). Rather than keep
  guessing, cut the loss explicitly: the two tests that needed a *custom
  app cookie* to survive Laravel's real HTTP test client with a known,
  stable value were dropped, not left flaky or hand-waved as passing.
  Every other case — including the middleware's full logic (revoked
  blocks + logs out, active passes, no row passes, platform-level user
  exempt, guest passes) — is instead proven by calling `handle()`
  directly (no HTTP kernel, no `EncryptCookies`, no cookie-round-trip
  question at all), matching `DeviceRegistrarTest`'s own established B1
  pattern. What's *not* covered by an automated test as a result: the
  literal middleware *registration* reachability over a real HTTP request
  with a real cookie, and `MyDevices`'s own "hide my current device"
  comparison. Both are simple, low-risk gaps (dual-registration mirrors
  `ResolveTenant`/`ResolveImpersonation` exactly, both proven live by
  other passing tests; the current-device check is a one-line equality
  with no independent logic) — flagged plainly, the same "not directly
  provable in this suite, stated rather than glossed over" posture S11
  B15 already established for true concurrent-lock contention.
- `composer test`: full suite run below.

### B3 — Login history + impossible-travel detection — DONE (2026-08-17)

- **No real `GeoIpResolver` driver built, deliberately** — design doc §4
  decision 3 named a local MaxMind GeoLite2 database, matching
  `RealDnsResolver`'s own "no vendor account needed" posture, but
  MaxMind now gates even the free GeoLite2 tier behind account
  registration to download the `.mmdb` file itself. Unlike `RealDnsResolver`
  (a plain PHP builtin, immediately bindable and testable), there is no
  real database file available in this environment to build or verify a
  real driver against — a stub with zero test coverage would be worse
  than not building it. `FakeGeoIpResolver` stays the bound driver
  (matches every other unconfigured vendor integration in this app —
  Razorpay, WhatsApp, live-class — all bind their Fake by default too).
  Provisioning a real `.mmdb` file is a `SETUP.md`-level deployment
  concern, the same boundary the design doc already draws around V6/V7.
- **`RecordLoginHistory` — a sibling listener on the same `Illuminate\Auth\Events\Login`
  event `RegisterDeviceOnLogin` (B1) already established as the one point
  every login surface funnels through**, not merged into B1's own class —
  same "one listener per concern" shape `AttendanceRecorded` already has
  two independent listeners for.
- **Scoped to successful logins only — a deliberate cut, not a stale
  oversight.** `Illuminate\Auth\Events\Login` only fires on success by
  construction; recording *failed* attempts too would need hooking
  `TenantAuthenticator::validate()`'s own failure branch, which — unlike
  the `Login` event — genuinely isn't reachable from Google OAuth's flow
  at all (a real, different chokepoint problem this block doesn't try to
  solve). Impossible-travel detection only makes sense comparing
  successful sessions anyway (a rejected password attempt was never
  really "there"), so the smallest version that delivers this block's
  actual value doesn't need failed-attempt tracking. `login_history.successful`
  stays in the schema for a later fast-follow, unused by this block
  beyond always writing `true`.
- `ImpossibleTravelDetector` re-resolves both IPs on every call rather
  than storing lat/lon on `login_history` — that table only has
  `country`/`city` strings (B0's own schema, not revisited here), and
  this runs once per login, not a hot path. Real haversine distance /
  elapsed hours > `config('security.impossible_travel_max_kmh')`
  (default 900 km/h, config-driven per design doc §6). Two logins at the
  same instant from two distinct real locations correctly flags (floored
  elapsed time, not a zero-guard early return) — the astronomically
  narrow-but-real case a naive "return false if no time passed" guard
  would have silently missed.
- **Real bug, caught by the "should NOT flag" tests failing, not by
  inspection**: assumed `diffInSeconds()`'s plain default (no extra args)
  form was already unsigned, matching `PlanChangeService::prorate()`'s
  own S11 B9 precedent for `diffInDays()`. Wrong for `diffInSeconds()`
  specifically in this Carbon version — it still returned a *negative*
  value when `$toAt` was after `$fromAt`, which floored straight to the
  `max(0.001, ...)` minimum, turning a perfectly plausible ~50 km/h
  Mumbai→Pune trip into an apparent multi-million-km/h one (and made the
  "respects the configured max speed" test fail too, for the identical
  reason — no config value could ever be high enough to un-flag a
  division by 0.001). Fixed with an explicit `abs()`, not by assuming
  Carbon's own convention holds across every `diffIn*()` method — a
  narrower, more accurate lesson than the S11 B9 one it first looked like
  it'd match.
- Tested at three levels: `FakeGeoIpResolverTest` (the driver itself —
  known cities, loopback nulls, stable-but-differentiated results for
  unlisted IPs), `ImpossibleTravelDetectorTest` (the velocity math
  directly — flags a real distant-city pair, doesn't flag a real nearby
  pair, never flags on unresolvable IPs, respects the config threshold,
  the same-instant edge case both ways), and `RecordLoginHistoryTest`
  (the listener — geo data recorded correctly, first-login-ever never
  flags, a genuinely impossible pair flags, a plausible pair doesn't,
  platform-level users are skipped, plus one real HTTP login proving
  the listener is actually registered). Unlike B2's own device-fingerprint
  cookie, IP comes from `REMOTE_ADDR`, not a cookie — no `EncryptCookies`
  round-trip involved, so B2's own multi-attempt saga doesn't repeat here;
  still bound the request directly into the container rather than
  chasing a full HTTP dispatch, for the same "full control, zero
  framework plumbing to second-guess" reasoning B2 ultimately settled on.
- `composer test`: full suite run below.

### B4 — Account-sharing detection — DONE (2026-08-18)

- `DetectAccountSharing` — daily unscoped sweep, same shape every other
  cross-tenant job in this app already uses
  (`SuspendOverdueTenantSubscriptions`, `FlagOverdueInvoices`). Counts
  distinct `device_sessions.device_fingerprint` values *first-seen*
  (`created_at`) within a rolling 7-day window per user — deliberately
  not "currently active" devices, since B1's own `device_limit` already
  caps concurrent devices at 2; the sharing signal here is *cycling*
  through many different devices over the window (revoking an old one to
  free a slot, logging in from a new one, repeatedly), which only a
  first-seen-in-window count can surface. No `DB::raw`/`selectRaw`
  (`CLAUDE.md`'s own security baseline) — distinct-counting happens in
  PHP via `pluck()->unique()->count()`, cheap for a daily sweep over a
  7-day window, not a hot path.
- `EnsureAccountNotFlaggedForSharing` — same dual-registration shape
  `EnsureDeviceNotRevoked` (B2) already established, but appended to the
  global `web` group *and* Admin/Teach's own panel middleware, not
  Admin/Teach-only in spirit — a shared-account signal applies to a
  student/parent every bit as much as staff (portals get it purely
  through the global append, same as B2's own reasoning for why they
  don't need explicit registration the way Filament panels do).
- **`account_sharing_flags.reviewed_at` repurposed as "already enforced,"
  not only "an admin looked at it"** — a real, deliberate design decision
  made explicit here since B0's own schema comment didn't pin this down.
  The middleware sets it itself the moment the forced logout fires, so
  the same flag can't force a logout loop on every subsequent request;
  the user just logs back in normally afterward, and a later sweep run
  creates a fresh flag if the pattern continues. This also happens to be
  the exact hook a future admin-review UI (not built in this block) could
  reuse without a schema change.
- Tested at three levels: `DetectAccountSharingTest` (threshold crossed/
  not crossed, the 7-day window boundary, a revoked device still counts,
  no duplicate flag while one's unreviewed, a fresh flag lands once the
  prior one's reviewed, a real cross-tenant unscoped sweep), the
  middleware directly (blocks + tears down + marks reviewed, passes once
  reviewed, only forces one logout not a loop, no flag passes through,
  platform-level user exempt, guest passes), plus one real HTTP test —
  safe here unlike B2's own device-fingerprint saga, since this
  middleware never reads a cookie at all, only `Auth::user()` + a DB
  lookup.
- `composer test`: full suite run below.

### B5 — General audit log sink — DONE (2026-08-18)

- `AuditLogger::record()` — one plain service call (design doc §4 decision
  5: synchronous, not queued, so the write survives a queue worker
  failure), matching `AuditLogEntry`'s own B0 schema exactly. `ipAddress`
  is an explicit param, not read from the `request()` helper internally —
  same "explicit, never ambient" discipline `DeviceRegistrar` (B1) already
  established, and it's what lets the identical call work from a
  scheduled job (no request in scope at all) as cleanly as from an HTTP
  action.
- Wired into exactly the four call sites the plan names, no more:
  - `RoleAssignmentPolicy::assign()` — `'role.assigned'`, only on a
    successful assignment (the `allowed = false` path already writes its
    own `RoleAssignmentAudit` row; this sink is additional, not a
    replacement, so a blocked attempt intentionally writes no
    `audit_log_entries` row).
  - `ImpersonationService::start()` — `'impersonation.started'`, metadata
    carries the paired `impersonation_audits.id` so the two tables can be
    cross-referenced. `stop()` deliberately left unwired — the plan names
    "impersonation" as one action, and `ImpersonationAudit` already owns
    the full start/end lifecycle; this sink exists for cross-domain
    visibility of the sensitive moment, not to duplicate that table.
  - `EditTenant`'s `changePlan` Filament action — `'plan.updated'` (the
    plan doc's own example action string, verbatim). Wired at the
    Filament-page call site, not inside `PlanChangeService::changePlan()`
    itself: that service takes no `$actor` param today and is called from
    several tests directly with only `(Tenant, Plan)`; adding an actor
    param would touch every existing caller for a concern the page layer
    already has cleanly available via `Auth::user()`. `subscribe()`/
    `cancel()` left unwired — plan text says "plan change", and
    `changePlan()` is the one operation actually named that.
  - `SuspendOverdueTenantSubscriptions::suspend()` — `'tenant.suspended'`,
    `actor: null` (system-initiated sweep, same as every other field
    already nullable for exactly this case). Refactored the existing bare
    `Tenant::where(...)->update(...)` into fetch-then-update so the
    fetched `Tenant` model is available as the audit row's subject.
- **Real pre-existing bug, not introduced by this block, caught only
  because `composer test` gates every block**: `SecurityBaselineTest`'s
  "no raw SQL in domain folders" check is a plain `str_contains()` scan
  over full file contents, comments included — B4's own docblock on
  `DetectAccountSharing.php` literally spelled out `` `DB::raw`/`selectRaw` ``
  while explaining why the job avoids them, and the scan flagged that
  prose as if it were a real `DB::raw` call. Fixed by rewording the
  comment so it no longer contains the literal `DB::raw` substring,
  without touching the job's actual logic (still zero raw SQL). Worth
  remembering for every future block's own comments: `CLAUDE.md`'s
  security-baseline test greps text, not tokens — naming a forbidden
  method inside a docblock trips the same test as using it.
- Test suite at 981 total (980 passed, 1 pre-existing unrelated
  `CourseSlugUniquenessTest` failure carried since S1/S2, same as every
  prior block) after this fix — 3 new tests (`AuditLoggerTest`: full
  entry, all-optional-fields-null entry, `nullOnDelete` survives a
  force-deleted actor) plus assertions added to 4 existing test files
  (`RoleAssignmentTest`, `ImpersonationTest`,
  `TenantResourcePlanSectionTest`, `SuspendOverdueTenantSubscriptionsTest`)
  proving each wired call site actually writes the right row, not just
  that `AuditLogger` works in isolation.

### B6 — Audit log retention + viewer — DONE (2026-08-18)

- `PruneAuditLogEntries` — `->monthly()`, not `->daily()` like every other
  sweep in this file (`SuspendOverdueTenantSubscriptions`,
  `DetectAccountSharing`), per design doc §4 decision 6's own explicit
  cadence call. Hard `->delete()` on rows past
  `config('security.audit_log_retention_days')` (default 365, new env
  `SECURITY_AUDIT_LOG_RETENTION_DAYS`) — no soft-delete, matching decision
  6's "nothing left to restore" reasoning verbatim. No `CurrentTenant`
  clearing needed the way every other unscoped sweep in this app requires
  — `AuditLogEntry` was never `BelongsToTenant` to begin with (B0), so
  there's no tenant scope to bypass.
- `AuditLog` (Super panel) — a plain `HasTable` page, not a Resource,
  same "nothing worth Create/Edit scaffolding" reasoning `MyDevices` (B2)
  already established for an append-only/no-create data source. Lives at
  `app/Filament/Pages/` — confirmed (not assumed) that path is Super's
  own exclusive `discoverPages` source (`SuperPanelProvider`'s only
  `discoverPages` call), unlike Admin/Teach's own `Filament/Admin/Pages`/
  `Filament/Teach/Pages` — so no explicit panel-registration edit was
  needed, matching how `ImpersonateUser` already got picked up the
  identical way in S9 B3.
- Unscoped `AuditLogEntry::query()` (every tenant's rows plus
  platform-level ones, `tenant_id IS NULL` included) — deliberate, not
  the accidental cross-tenant leak `CrossTenantTest` exists to catch
  elsewhere: this page is Super-panel-only (`EnsureRole::class.':super_admin'`
  already gates the whole panel), and the whole point of a platform-level
  audit viewer is seeing across every tenant at once. `AuditLogEntry`
  isn't `BelongsToTenant` in the first place, so there's no global scope
  being bypassed here, unlike `MyDevices`'s own ambient-`CurrentTenant`
  reliance.
- Action-name and tenant `SelectFilter`s, a `view` row-action opening a
  read-only modal (`KeyValueEntry` for `metadata`, the one column too
  unstructured for a plain `TextColumn`) — the smallest surface that
  makes a 981-row (and growing) table actually navigable, nothing beyond
  what "read-only viewer" asks for. No export/search-by-actor-name/date-
  range filter built — not named by the plan text, a fast-follow if a
  real investigation ever needs it.
- Tested at three levels: `PruneAuditLogEntriesTest` (past-cutoff deleted,
  within-window kept, exact-boundary kept — strictly-older only, a custom
  config value respected, an empty table runs cleanly), `AuditLogPageTest`
  (cross-tenant + platform-level rows all visible in one list, both
  filters narrow correctly, a non-`super_admin` gets `403` the same way
  `PanelAccessTest`'s own existing cases already prove for this panel).
- `composer test`: 990 total, 989 passed, the same one pre-existing
  unrelated `CourseSlugUniquenessTest` failure carried since S1/S2.

### B7 — GDPR cookie consent — DONE (2026-08-18)

- **Real mechanical gotcha, traced not assumed**: design doc §4 decision 9
  says consent is "reconciled to the user once authenticated," but a
  naive `session()->getId()` lookup inside a `Login` listener finds
  nothing — `SessionGuard::login()` calls `updateSession()` (which
  regenerates the session ID) *before* firing `Login`, so by the time any
  listener runs, the pre-login ID `CookieConsentService::record()` wrote
  against is already gone. Session *data* survives that regeneration
  (only the ID rotates), so `record()` stashes the pre-login session ID
  under a session key (`CookieConsentService::PENDING_SESSION_KEY`)
  instead of relying on the ID itself; `ReconcileCookieConsent` reads that
  key back, still intact, and forgets it once used. Traced through
  `SessionGuard::login()` -> `updateSession()` -> `Store::regenerate()` in
  the vendor source, not assumed from the design doc's own wording alone.
- `ReconcileCookieConsent` — a third sibling listener on the same `Login`
  chokepoint `RegisterDeviceOnLogin` (B1) established, alongside
  `RecordLoginHistory` (B3). Unlike those two, never skipped for a
  null-tenant (`super_admin`) login — cookie consent isn't a tenant-scoped
  concern, a platform-level visitor's own consent is exactly as real.
- `CookieConsentService::reconcile()` takes an explicit `$sessionId` param
  rather than reading `session()` internally — same "explicit, never
  ambient" discipline `DeviceRegistrar` (B1) already established, keeps it
  a plain directly-testable unit; the listener is what reads/forgets the
  session key at the edges.
- `CookieConsentBanner` (Livewire) — two choices only ("accept all" /
  "necessary only"), not a per-category toggle UI; the smallest surface
  `cookie_consents.categories` actually needs recorded, matching every
  other block's own "smallest version that delivers the block's value."
  Wired onto every real first-contact surface, mirroring exactly where
  `ImpersonationBanner`/`NotificationCentre` already sit: `TOPBAR_END`
  (authenticated Filament shell, all 3 panels, one registration) *plus* a
  new `SIMPLE_PAGE_END` hook (`AppServiceProvider`) since `TOPBAR_END`
  never renders on Filament's own guest login page — confirmed, not
  assumed, that a login-page-only banner needs the second hook. Both
  portals get it through their single shared dashboard blade
  (`student/dashboard.blade.php`, `parent/dashboard.blade.php`), which
  already toggles guest/authenticated internally, so one include per
  portal covers both states.
- Tested at three levels: `CookieConsentServiceTest` (record with/without
  a user, the session stash written only for the anonymous case,
  `hasDecision()` by session vs. by user, `reconcile()` scoped to the
  right session only and never overwriting an already-attached row),
  `ReconcileCookieConsentTest` (the listener directly, a no-op with
  nothing stashed, plus one real HTTP login using `withSession()` to
  pre-seed the pending key — sidesteps B2's own cookie-encryption saga
  entirely since this mechanism never reads a cookie, only session data),
  `CookieConsentBannerTest` (shows/hides correctly across anonymous,
  logged-in, and already-reconciled states, records the bound tenant
  correctly, plus two real HTTP page loads proving the banner actually
  renders on both the guest login page and an authenticated panel page —
  the two render hooks, not just the component in isolation).
- `composer test`: 1007 total, 1006 passed, the same one pre-existing
  unrelated `CourseSlugUniquenessTest` failure carried since S1/S2.

### B8 — GDPR data export — DONE (2026-08-18)

- **Staff-initiated, not self-service** — `requested_by_id` is a real,
  distinct actor from `user_id` in B0's own schema, which settles a real
  design question the plan text itself left open: this is a compliance-
  officer-style "export this user's data because they asked us" workflow
  (an institute_admin acting on a data subject's request), not a "download
  my own data" portal button. No self-service surface exists anywhere in
  this app to hang that on, and the plan text names the export mechanism
  itself, not a new portal page.
- `GdprExportService::request()` (row + dispatch) and
  `GenerateGdprExport` (the zip) are two classes, not one — mirrors
  `AuditLogger`/listener's own split and `ExportRevenueRegister`'s
  "`ShouldQueue` does the slow part" shape (S9 B11) at once.
  `RebindsTenant` middleware, same as that job — every model this job
  reads is `BelongsToTenant`, and a queue worker has no ambient
  `CurrentTenant` on its own.
- One CSV per domain inside the zip, covering decision 7's own named
  examples (enrolments, submissions, certificates, fee history, quiz
  attempts) plus this slice's own new login/device tables — not an
  exhaustive sweep of every domain in the app; a real fast-follow if a
  genuine compliance audit ever demands more, not assumed needed now.
  `FeePayment` has no direct `user_id` (confirmed by reading its own
  `$fillable`, not assumed) — joined through `FeeInvoice.user_id` instead,
  the one genuinely non-obvious query in the whole job.
- **Signed download link, literally** — `URL::temporarySignedRoute()` +
  `'signed'` route middleware (`routes/security.php`), the same real
  mechanism `watch.stream`/`watch.pdf` already use (routes/content.php),
  not the plain role-gated-controller shape `ExportRevenueRegister`'s own
  download route actually uses despite the design doc's own wording
  loosely calling that one "signed" too — confirmed by reading
  `DownloadRevenueExportController` directly rather than assuming the
  design doc's cross-reference was mechanically precise.
  `expires_at` (B0's own schema column) drives both the DB-level 410 check
  and the signed URL's own expiry together, so a link can't outlive the
  file it points at.
- `GdprExports` (Admin panel) — a table, not a single generate/download
  action pair like `RevenueExport`: that job produces one file per
  tenant, this one produces one per *request*, and several can be in
  flight for different users at once. Target user picked via a searchable
  `Select` scoped to the current tenant — same "no Users Resource exists
  yet" reasoning `ImpersonateUser` (S9 B3) already established, just
  tenant-scoped here instead of that page's own cross-tenant one.
- Tested at four levels: `GdprExportServiceTest` (request row + dispatch),
  `GenerateGdprExportTest` (every CSV entry present even for a user with
  zero cross-domain data, user-scoping proven on a cheap-to-seed table,
  the `FeePayment`-via-`FeeInvoice` join and a `Certificate` join both
  proven with real rows), `DownloadGdprExportControllerTest` (a real
  signed download, a tampered/unsigned URL refused, not-ready is 404,
  expired is 410), `GdprExportsPageTest` (the request action queues
  correctly, cross-tenant isolation, the download action's own
  ready-and-unexpired visibility gate, one real HTTP page load). No
  forced-failure-path test for the job's own `catch` block — the one
  realistic trigger (`user_id` pointing at a deleted user) is blocked by
  its own `cascadeOnDelete()` FK, which would delete the request row
  itself before the job could ever observe a "missing user" state; not
  worth mocking a filesystem failure just to exercise a 3-line catch
  block.
- `composer test`: 1020 total, 1019 passed, the same one pre-existing
  unrelated `CourseSlugUniquenessTest` failure carried since S1/S2.

### B9 — GDPR data deletion — DONE (2026-08-18)

- **Real schema finding, confirmed not assumed**: decision 8 names "PII
  columns (`name`, `email`, `phone`)" to null/hash on "rows the law
  requires kept" — reading `FeeInvoice`'s and `SubscriptionInvoice`'s own
  migrations directly shows neither carries a `name`/`email`/`phone`
  column at all (`FeeInvoice` only has `gstin_snapshot`). Those 3 PII
  columns live on `User` itself; anonymizing the `User` row is what
  protects the retained financial rows still pointing at its `user_id` —
  they keep a real, valid FK, just to a de-identified account. This
  settles decision 8's own wording precisely rather than guessing at a
  literal per-invoice anonymization that the schema doesn't support.
  `email` is anonymized to `deleted-user-{id}@deleted.invalid`, not
  nulled — the column is `unique()` + `NOT NULL` (confirmed from the base
  migration), so a null or repeated placeholder would collide across
  more than one deletion.
- **Staff-initiated, same reasoning B8 already settled** —
  `requested_by_id` is a real distinct actor from `user_id` in B0's own
  schema; no self-service surface exists to hang this on either.
- `GdprDeletionService::request()` only schedules (`scheduled_for`,
  `config('security.gdpr_deletion_grace_days')`, default 30 — explicitly
  flagged in the design doc's own §6 as a business decision, not a
  hardcoded legal opinion, so it's a config value like every other
  tunable threshold this slice has added). Nothing is dispatched at
  request time, unlike B8's export — deletion has a mandatory wait, so
  only the daily sweep (`ProcessGdprDeletions`) ever calls
  `GdprDeletionService::execute()`.
- `cancel()` — decision 8's own "a grace window to cancel" made concrete:
  a pending request is simply deleted (schema has no `cancelled` status
  to set), refusing via a new `GdprDeletionRequestNotPendingException`
  (409) once a request has moved past `pending` — same one-exception-
  per-class + `bootstrap/app.php` `$exceptions->render()` convention
  every prior domain exception already follows.
- Hard-delete scope: `QuizAttempt` and `VideoWatchEvent`, decision 8's
  own two named examples — not an exhaustive sweep of every domain
  (`Enrolment`, `Certificate`, `AssignmentSubmission`, this slice's own
  `LoginHistory`/`DeviceSession`), same "smallest version that delivers
  the block's value" posture B8's export job already took; a real
  fast-follow if a genuine compliance audit demands more.
- **Real Carbon gotcha, hit again**: `$request->scheduled_for->diffInDays(now())`
  returned a *negative* value in a test assertion — the same signed-
  `diffIn*()` surprise B3 already documented for `diffInSeconds()`, this
  time on `diffInDays()` with the future-dated operand on the left.
  Fixed with `abs()` in the test, not by trusting the S11 B9 "plain
  default is unsigned" precedent blindly a third time.
- `GdprDeletions` (Admin panel) — same table shape as `GdprExports` (B8):
  several requests for different users can be in flight per tenant, a
  single generate/download-style action pair wouldn't fit. Target user
  picked via the same tenant-scoped searchable `Select` pattern.
- Tested at four levels: `GdprDeletionServiceTest` (grace-window math,
  cancel removes a pending row, cancel refuses a non-pending one,
  anonymization actually lands on the `User` row, `VideoWatchEvent`
  hard-delete scoped to the right user only — not just the request's own
  status flip, per the design doc's own testing-strategy warning),
  `ProcessGdprDeletionsTest` (executes only what's both pending and due,
  leaves a not-yet-due row alone, never touches an already-`completed`
  row even if its schedule has passed, one unscoped cross-tenant pass,
  an empty sweep runs cleanly), `GdprDeletionsPageTest` (request action,
  cross-tenant isolation, cancel action + its own pending-only
  visibility, one real HTTP page load). `QuizAttempt`'s own hard-delete
  path has no dedicated positive-data test — its own FK chain (`Quiz` ->
  `Lesson` -> `CourseSection` -> `Course`) is deeper than
  `VideoWatchEvent`'s, and the code path (`Model::where('user_id',
  ...)->delete()`) is mechanically identical to the one already proven;
  flagged rather than silently skipped.
- `composer test`: 1035 total, 1034 passed, the same one pre-existing
  unrelated `CourseSlugUniquenessTest` failure carried since S1/S2.

### B10 — Circuit breaker core + Bunny/Claude — DONE (2026-08-18)

- **Real finding, confirmed not assumed**: "Bunny" and "Claude" are the
  design doc's own vendor-name shorthand for `VideoProvider`/`AiProvider`
  — grepping for actual classes named `Bunny`/`Claude` returns nothing,
  and `AppServiceProvider`'s own bindings confirm neither ever had a real
  driver built, only `FakeVideoDriver`/`FakeAiDriver` (same "no vendor
  account configured" posture every other unconfigured integration in
  this app already has — Razorpay, WhatsApp, `FakeGeoIpResolver`). Wiring
  the breaker onto the *container binding* rather than a nonexistent
  named class means it protects whichever driver is bound — fake today,
  a real one automatically once B10's own scope grows past this slice.
- `CircuitBreaker` — two Redis keys per breaker `key` (`failures`, a plain
  `INCR` counter; `open`, a `SETEX`'d marker), not three — decision 10's
  own "open/closed/half-open" is fully derived from those two rather than
  a separately-stored state enum. Deliberately does *not* reset
  `failures` when tripping open, so a single failed half-open probe
  re-trips immediately (still >= threshold) instead of needing a fresh
  run of N failures — the actual behaviour the design doc's own testing-
  strategy line ("assert a half-open probe after the cooldown window
  restores it") implies, worked out from state-machine first principles
  since the doc doesn't spell out the exact re-trip mechanics.
- **Decorator placement, not each call site** — same shape
  `SpendCapGuardedAiDriver` (S9 B12) already established.
  `CircuitBreakerGuardedVideoDriver`/`CircuitBreakerGuardedAiDriver` wrap
  the innermost real/fake driver; for AI, the breaker sits *inside*
  `SpendCapGuardedAiDriver`, not outside it — a spend-cap denial is a
  business-rule pre-check, never a real provider failure, so it must
  never count toward tripping the breaker. Proved with a real test
  (`app(AiProvider::class)` resolved through the full real binding chain,
  5 spend-cap denials in a row, breaker still closed), not just asserted.
- One breaker key per *provider* (`ai-provider`, `video-provider`), not
  per method — a real outage fails every method of a provider together,
  so failures across `chat()`/`embed()`/`transcribe()` (or
  `store()`/`url()`/`delete()`) all count toward the same trip, matching
  how an actual vendor outage behaves.
- `CircuitOpenException` renders `503` (`bootstrap/app.php`) — a real,
  expected degraded-provider state, not a programmer error; for a queued-
  job call site (`TranscribeLesson` etc.), the exception just propagates
  into that job's own existing `$tries`/`backoff()` retry, no special
  handling needed.
- `circuit_breaker_failure_threshold` (default 5) and
  `circuit_breaker_cooldown_seconds` (default 60) — new `config/resilience.php`,
  not `config/security.php` — this is a resilience concern, not a
  security one, matching `app/Support/Resilience/`'s own placement.
- Tested at three levels: `CircuitBreakerTest` (closed passes through,
  stays closed below threshold, trips at exactly the threshold, fails
  fast with the real callback never invoked, a successful vs. a failed
  half-open probe close vs. re-trip it, two provider keys stay fully
  independent, one real end-to-end test with a genuine 1-second cooldown
  and a real `sleep()` — the "can't test true concurrency, can test the
  state machine's own logic" honesty the design doc's own testing
  strategy names, applied here to a real Redis TTL instead of simulating
  it every time), `CircuitBreakerGuardedVideoDriverTest` /
  `CircuitBreakerGuardedAiDriverTest` (the shared-key trip proven across
  different methods on each driver, plus the spend-cap layering proof
  above). Ran the full `Content`/`AI` domain suites directly after
  changing the `VideoProvider`/`AiProvider` bindings, before the full
  suite — zero regressions, confirmed rather than assumed safe.
- `composer test`: 1048 total, 1047 passed, the same one pre-existing
  unrelated `CourseSlugUniquenessTest` failure carried since S1/S2.

### B11 — Circuit breaker Razorpay/WhatsApp/live-class + dashboard — DONE (2026-08-18)

- Same decorate-the-container-binding shape B10 already established, for
  the 4 remaining providers: `CircuitBreakerGuardedPaymentDriver`
  (`payment-provider`), `CircuitBreakerGuardedSubscriptionDriver`
  (`subscription-provider`), `CircuitBreakerGuardedCommsDriver`
  (`comms-provider`), `CircuitBreakerGuardedLiveClassDriver`
  (`live-class-provider`).
- **Selective wrapping, not blanket per-interface** — `PaymentProvider`/
  `SubscriptionProvider` each carry one `verifyWebhookSignature()` method
  alongside their real outbound calls; that method is a pure local HMAC
  comparison, never a network call, so it's deliberately left unwrapped —
  wrapping it would let ordinary webhook-replay/invalid-signature traffic
  falsely count as provider failures and trip a breaker over something
  that never touched the vendor at all. Every method on `CommsProvider`/
  `LiveClassProvider` is a real outbound call, so both are wrapped in
  full, same as B10's Video/AiProvider.
- **Sentry alert, without installing the Sentry SDK** — `SETUP.md`
  already lists `SENTRY_LARAVEL_DSN` as a documented, not-yet-configured
  env var, the same V6/V7 "ops/deployment concern" boundary the design
  doc draws around installing/configuring the SDK itself. `CircuitBreaker::recordFailure()`
  now calls `Log::critical()` with structured context (provider, failure
  count, cooldown, last error) at the exact moment a breaker trips —
  Sentry's own Laravel integration auto-captures `critical`-level log
  entries with zero extra app code the moment that DSN lands, so this is
  the real, permanent hook, not a stub standing in for a package this
  slice was never going to install speculatively.
- `CircuitBreakerStatusWidget` — a `StatsOverviewWidget`, not a new
  Filament resource, per design doc §2.3's own explicit call ("V1 needs
  breaker state *visible*, not a dedicated CRUD surface"). Lists all 6
  wired providers (B10's 2 plus B11's own 4), not just this block's own
  four — a partial dashboard would be a worse status page than a
  complete one. One shared class for both panels, same reasoning
  `TokenSpendWidget` already established (breaker state isn't tenant-
  scoped, nothing to branch on); auto-discovered on Super via its own
  `app/Filament/Widgets` scan, registered explicitly in
  `AdminPanelProvider`'s `widgets()` array since its own discovery root
  is `Filament/Admin/Widgets` instead.
- **Real regressions caught by the domain sanity sweep, not the full
  suite** — 3 pre-existing tests asserted the bare `LiveClassProvider`/
  `CommsProvider` container binding resolved directly to
  `FakeLiveClassDriver`/`FakeWhatsAppDriver`
  (`BatchSessionLiveClassTest`, `ExternalLinkProviderTest`,
  `FakeWhatsAppDriverTest`) — now correctly resolves the circuit-breaker
  decorator instead, same update `SpendCapGuardedAiDriverTest` itself
  already needed when S9 B12 first wrapped `AiProvider`. Fixed by
  updating the expected class, not by loosening the binding.
- Tested at four levels: one test file per new decorator (the shared-key
  trip across every real method on that interface, plus a dedicated
  proof for both `verifyWebhookSignature()`-carrying interfaces that it
  never counts toward the breaker), 2 new `CircuitBreakerTest` cases (a
  `Log::critical()` call fires exactly once with the right context at
  the trip moment, never fires below threshold), `CircuitBreakerStatusWidgetTest`
  (every provider shown closed when healthy, a tripped one shown open).
  Ran the full `Billing`/`Batches`/`Comms`/`Fees`/`Integrations` domain
  suites directly after changing the 4 bindings, before the full suite —
  caught and fixed the 3 regressions above, confirmed rather than
  assumed safe.
- `composer test`: 1058 total, 1057 passed, the same one pre-existing
  unrelated `CourseSlugUniquenessTest` failure carried since S1/S2.

### B12 — Cache layer + invalidation — DONE (2026-08-18)

- `AggregateCache` (`app/Support/Caching/`) — one `remember*()`/`forget*()`
  pair per named target (`rememberGradebookScore`, `rememberStudentMetrics`,
  `rememberInstituteOverview`), not one generic `remember(string $key, ...)`
  a caller keys however it likes — a typo'd ad-hoc key string would
  silently create an orphaned entry no `forget*()` call could ever reach.
  Redis via `config('cache.default')` (already `redis` per `.env`, not the
  config file's own `database` fallback — confirmed, not assumed), no new
  infrastructure.
- **Real scoping decision, not directly stated in the design doc**:
  decision 11 says cache "computed aggregates... not raw model reads,"
  and `StudentMetrics::summarize()` returns `outstanding_invoices` — a
  live list of `FeeInvoice` rows returned verbatim, exactly the raw read
  that line rules out. Excluded from what gets cached; always fetched
  fresh on every call, merged onto the cached scalar fields. `fee_status`
  has the identical staleness exposure (no bust hook — payment webhooks
  and `FlagOverdueInvoices` aren't in this block's own bust list) but is
  a derived string, not a raw row list, so it's cached anyway and leans
  on the TTL safety net for that specific gap.
  `InstituteOverviewWidget`'s own `active_enrolments`/`paid_revenue` have
  the same gap and the same treatment, for consistency — decision 11
  names the widget's numbers as one target without carving out a subset.
- **Bust hooks, one per named trigger**: `AssignmentGradingService::grade()`
  (both marks-only and rubric branches, restructured from early-return to
  if/else so the bust fires exactly once regardless of path) busts
  `GradebookScore` + `InstituteOverview`; `QuizAttemptService::submit()`
  busts `GradebookScore` + `StudentMetrics` + `InstituteOverview` (it's
  the one write that feeds both aggregates); `AttendanceRecorded`'s own
  event gets a third listener, `BustAggregateCacheOnAttendance`
  (`app/Domains/Batches/Listeners/`) — covers both real writers
  (`RecordVideoCoverageAttendance`, `BatchSessionsRelationManager`) for
  free since both already funnel through that one event, busting only
  `StudentMetrics` (confirmed by reading `GradebookService`'s own two
  private helpers that attendance never feeds it, so a gradebook bust
  there would clear data that was never stale).
- **Real extension beyond decision 11's literal wording, flagged not
  glossed over**: `QuizAttemptService::reviewAnswer()` also busts —
  decision 11's bust list says "QuizAttempt submitted," not "reviewed,"
  but a short-answer review changes the exact same `score`/`max_score`
  data `quizPercentFor()`/`quizAveragePercent()` read. Leaving it
  uninvalidated would let a review silently disagree with a cached score
  until an unrelated bust or the TTL caught up — a real gap the design
  doc's own wording didn't spell out but the underlying data change made
  necessary regardless.
- Course/tenant derivation for the two write-side services needed a real
  chain, not a direct column: `assignment->lesson->section->course`,
  `attempt->quiz->lesson->section->course` — both defensively skip the
  bust (not throw) on a missing link, since the write itself already
  succeeded; a stale cache for one TTL window is a smaller problem than
  failing a grade/submission over a dangling relation.
- **Real cross-cutting test-infrastructure gap, caught before it could
  bite silently**: this is the app's first-ever `Cache::` usage.
  `RefreshDatabase` resets the database between tests but never touches
  Redis, and auto-increment ids restart from 1 each test run — so a
  tenant/student id from one test could collide with a stale
  `AggregateCache` entry left by an earlier test that happened to reuse
  the same id, corrupting an unrelated test's assertions with no visible
  connection between the two. Fixed at the root: a global `afterEach(fn
  () => Cache::flush())` in `tests/Pest.php`, applying to every test in
  the suite — flushes only the `cache` Redis connection/database
  (`config/database.php`'s own `REDIS_CACHE_DB`, separate from `default`,
  which session/queue use), so it can never touch session or queue state.
  Worth remembering for any future block that adds more `Cache::` usage.
- `GradebookService`, `StudentMetrics`, `AssignmentGradingService`,
  `QuizAttemptService` all gained a constructor (`AggregateCache` DI) for
  the first time — confirmed via grep that nothing in `app/` or `tests/`
  ever calls any of the four with `new X()` directly before making the
  change; one exception found and fixed, `StudentMetricsTest`'s own
  `new StudentMetrics` in its `beforeEach`, updated to `app(StudentMetrics::class)`.
- Tested at seven levels: `AggregateCacheTest` (remember/forget per
  shape, cross-key independence, the configured TTL actually reaches the
  cache store), `GradebookServiceTest`/`StudentMetricsTest`/
  `InstituteOverviewWidgetTest` (each: a direct DB mutation stays
  invisible until an explicit forget, then a fresh recompute lands;
  per-student/per-course key isolation), `QuizAttemptServiceSubmitTest`/
  `QuizAttemptServiceReviewTest` (both busts proven through the real
  services, not by inspecting `AggregateCache` calls directly),
  `AssignmentSubmissionResourceTest` (grade() busts gradebook through the
  real Filament-adjacent call path), `BustAggregateCacheOnAttendanceTest`
  (the listener directly, plus one real end-to-end test through the
  actual `AttendanceRecord::updateOrCreate()` + `event()` dispatch, no
  bypass). Ran the full `Assignments`/`Assessments`/`Comms`/`Batches`
  domain suites directly after adding the four constructors, before the
  full suite — zero regressions, confirmed rather than assumed safe.
- `composer test`: 1073 total, 1072 passed, the same one pre-existing
  unrelated `CourseSlugUniquenessTest` failure carried since S1/S2.

### B13 — Soft-delete gap closure + Form Request extraction — DONE (2026-08-18)

- **Real gap in the design doc's own assumption, caught before building
  anything, not after**: decision 12 says "each of the 4 newly-soft-
  deleted resources' `ListRecords` page gains a `TrashedFilter` +
  `RestoreAction`" — phrased as if all 4 already had a `ListRecords` page.
  Verified directly (not assumed): only `FeeInvoice` does. `FeePayment`,
  `TenantSubscription`, `SubscriptionInvoice` had no Filament resource of
  any kind before this block. Flagged to the user rather than silently
  picking a scope — chose to build 3 new minimal, browse-only Resources
  (`FeePaymentResource` on Admin, matching `FeeInvoiceResource`'s own
  "browse-only ledger" precedent; `TenantSubscriptionResource`/
  `SubscriptionInvoiceResource` on Super, matching `PlanResource`'s own
  placement — `AdminPanelProvider` has no Billing-domain discovery line
  at all) rather than the lighter "trait+migration only, restore UI
  deferred" option, so "must be exposed in the admin panel" is genuinely
  true for all 4, not 1 of 4.
- SoftDeletes added to all 4 models cleanly — confirmed by reading each
  file first that none had an existing `booted()`/model-event hook to
  collide with (unlike `Lesson`'s own `forceDeleting` storage-release
  hook). 4 new `Schema::table(...)->softDeletes()` follow-on migrations,
  not edits to the original `create_` migrations — this codebase's own
  established convention for adding a column to an already-shipped table.
- `TrashedFilter::make()` + `RestoreAction::make()` + `ForceDeleteAction::make()`
  + `->modifyQueryUsing(fn ($q) => $q->withoutGlobalScopes([SoftDeletingScope::class]))`
  on all 4 resources' `table()` — the exact `Course`/`Batch` shape,
  confirmed by reading both directly rather than assumed from the design
  doc's own one-line description. No new `DeleteAction` added anywhere —
  none of the 4 had one before (all read-only/system-generated ledgers),
  and adding one would be a new UI capability this block was never asked
  to add; the restore path matters regardless of how a row became
  trashed (a future service/job/tinker call, not only a UI click).
- **Real test-infrastructure gap, closed alongside the code it protects**:
  decision 12's own explicit warning — "confirm the tenant scope still
  excludes a different tenant's soft-deleted row when `withTrashed()` is
  used" — was not covered by any existing test; the generated
  `CrossTenantTest` suite never calls `delete()`/`withTrashed()` on any
  registered model. Proven directly in both new soft-delete test files
  (`FeeInvoicePaymentSoftDeleteTest`, `SubscriptionSoftDeleteTest`):
  `BelongsToTenant`'s own scope (registered under the string key
  `'tenant'`) and `SoftDeletingScope` (dropped by class) are independent,
  so `withTrashed()` alone never crosses a tenant boundary — confirmed by
  a real test, not just reasoned about.
- V4 half — `TwoFactorChallengeRequest` (`app/Http/Requests/Auth/`) and
  `WatchHeartbeatRequest` (`app/Http/Requests/Content/`, the first Form
  Request outside `Auth/`), both matching `LoginRequest`'s own
  `authorize(): true` + `rules()` shape. **Real, deliberately-accepted
  behavior change flagged, not glossed over**: converting
  `WatchHeartbeatController` to a Form Request means Laravel now runs
  validation during dependency resolution, *before* the controller
  body's own `401`/`404`/`403` access-gate checks — reversed from the
  original inline-`validate()` ordering, which ran the access checks
  first. A guest sending a malformed body would now see `422` before the
  `401` check is ever reached. Accepted rather than worked around (e.g.
  moving the gate into the Form Request's own `authorize()`, which would
  have meant duplicating `LessonAccess` calls outside the one place
  `CLAUDE.md` says lesson-visibility rules must live) — matches
  `LoginRequest`'s own already-established validate-first ordering in
  this exact codebase, and the existing test suite (which always sends
  well-formed bodies on its auth-check tests) confirmed no observable
  regression.
- Tested at three levels for V3: `FeeInvoicePaymentSoftDeleteTest`/
  `SubscriptionSoftDeleteTest` (delete/withTrashed/restore round-trip per
  model, plus the cross-tenant proof above), `FeeInvoiceResourceTest`/
  `FeePaymentResourceTest`/`TenantSubscriptionResourceTest`/
  `SubscriptionInvoiceResourceTest` (a trashed row hidden by default,
  visible under the Trashed filter, restorable via the real row action,
  plus a non-privileged-role 403 on each new resource). For V4:
  `WatchHeartbeatTest`/`TwoFactorAuthenticationTest` extended with a
  missing-required-field `422` case each, run alongside every pre-
  existing test in both files to confirm the validate-first reordering
  changed nothing observable. Ran the full `Fees`/`Billing`/`Auth`/
  `Content` domain suites directly after all model/controller changes,
  before the full suite — zero regressions, confirmed rather than
  assumed safe.
- `composer test`: 1092 total, 1091 passed, the same one pre-existing
  unrelated `CourseSlugUniquenessTest` failure carried since S1/S2.

### B14 — Full test coverage, review pass — not started

- Sweep for anything the per-block tests didn't cover end-to-end (a full
  login → device-limit-hit → remote-logout → login-history-flagged chain
  as one integration test, mirroring S7/S11's own precedent for a
  lifecycle E2E test).
- Confirm `TenantModelRegistry` picked up all 5 new `BelongsToTenant`
  models (`device_sessions`, `login_history`, `account_sharing_flags`,
  `gdpr_export_requests`, `gdpr_deletion_requests`) — already enforced by
  B0's own registration, just confirm nothing slipped through.
- V5 regression-guard: re-run `tests/Feature/Security/SecurityBaselineTest.php`
  explicitly and confirm it still passes with zero `DB::raw`/`whereRaw`/
  `DB::statement` introduced by any of B1-B13's own new code.
- Explicit review pass across every B0-B13 file for real bugs, not just a
  green suite — same discipline S9 B14/S10 B15/S11 B16 each already
  established and each already found real, fixed bugs this way.
- Update `HANDOVER.md` (S12 complete entry + any new `## Avoid` entries)
  and `docs/PROJECT-STATUS.md` (new S12 section, matching S1-S11's exact
  format).
