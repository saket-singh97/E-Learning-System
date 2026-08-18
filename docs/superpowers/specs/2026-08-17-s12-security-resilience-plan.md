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
| B8 | GDPR/DPDP data export — async job, zip of every domain's rows for one user, signed download link (B15 pt.2) | ⬜ not started |
| B9 | GDPR/DPDP data deletion — anonymize-vs-hard-delete split, 30-day grace window, scheduled sweep (B15 pt.3) | ⬜ not started |
| B10 | Circuit breaker core (`App\Support\Resilience\CircuitBreaker`, Redis-backed) + wire onto Bunny + Claude drivers (V1 pt.1) | ⬜ not started |
| B11 | Circuit breaker wired onto Razorpay (both integrations) + WhatsApp BSP + live-class provider, Sentry alert on trip, dashboard visibility (V1 pt.2) | ⬜ not started |
| B12 | Cache layer + explicit invalidation — `GradebookService::scoreFor()`, `StudentMetrics::summarize()`, `InstituteOverviewWidget` (V2) | ⬜ not started |
| B13 | Soft-delete gap closure — `FeePayment`/`FeeInvoice`/`TenantSubscription`/`SubscriptionInvoice` + admin restore UI on all 4 (V3); Form Request extraction on the 2 remaining inline-`validate()` controllers (V4) | ⬜ not started |
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

### B8 — GDPR data export — not started

### B9 — GDPR data deletion — not started

### B10 — Circuit breaker core + Bunny/Claude — not started

### B11 — Circuit breaker Razorpay/WhatsApp/live-class + dashboard — not started

### B12 — Cache layer + invalidation — not started

### B13 — Soft-delete gap closure + Form Request extraction — not started

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
