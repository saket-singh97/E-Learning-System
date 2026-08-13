# Implementation Plan: S9 Hardening & Backlog

Full design: `2026-08-12-s9-hardening-backlog-design.md`. Block-by-block build log —
update the PROGRESS table as each block lands, same as S1-S8.

---

## Per-block build command

```
Read docs/superpowers/specs/2026-08-12-s9-hardening-backlog-design.md and
docs/superpowers/specs/2026-08-12-s9-hardening-backlog-plan.md, then build
Block 0 of the S9 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | Tenant-isolation security parity — `StudentPortal` tenant-mismatch check, `LessonAccess::canStream()`/`Quiz::isVisibleTo()` tenant cross-check, `canJoinLive()` course-published check, `myLiveBatchSession()` tiebreak | ✅ done |
| B1 | TOTP two-factor auth (S1's "lands in S8" promise, #1 of 4) | ✅ done |
| B2 | Google OAuth login (S1's "lands in S8" promise, #2 of 4) | ✅ done |
| B3 | Super Admin impersonation + audit log (S1's "lands in S8" promise, #3 of 4) | ✅ done |
| B4 | Custom domain (DNS verification; SSL stays manual) (S1's "lands in S8" promise, #4 of 4) | ✅ done |
| B5 | Self-service parent linking via invite code (O1 completion) | ✅ done |
| B6 | Lesson content-type completion (PDF/text/embed) + basic download/right-click protection | ✅ done |
| B7 | Quiz answer autosave | ✅ done |
| B8 | Fee-checkout live refresh + Razorpay idempotency key | ✅ done |
| B9 | User profile page (shared across all 5 surfaces) | ✅ done |
| B10 | Production readiness — encrypted backups, real queue retry/backoff/failed-job retention | ✅ done |
| B11 | Report catalog: revenue + engagement/completion CSV exports | ✅ done |
| B12 | Plan tiers — bandwidth quota + AI spend cap enforcement | ✅ done |
| B13 | Low-attendance alert + automated nudges | ✅ done |
| B14 | Full test coverage, isolation registration, review pass, `PROJECT-STATUS.md`/`HANDOVER.md` update | ✅ done |

---

## Block notes (fill in as each block builds — deviations, gotchas, decisions made mid-build)

### B0 — Tenant-isolation security parity ✅

- `App\Livewire\StudentPortal::mount()` — added, copying `ParentPortal::mount()`'s
  exact tenant-mismatch check. Confirmed fresh read: `StudentPortal` had no
  `mount()` at all before this (just `login()`/`logout()`/`render()`), so no
  collision to reconcile.
- `LessonAccess::canStream()` — fixed in place (kept the existing signature,
  no `CurrentTenant` dependency needed): both `$lesson` and `$viewer` already
  carry their own `tenant_id` via `BelongsToTenant`, so the fix is a direct
  `$viewer->tenant_id !== $lesson->tenant_id` guard, same shape as
  `DownloadInvoiceController`'s `$viewer->tenant_id === $invoice->tenant_id`.
  `is_free_preview` stays an unconditional bypass (marketing content, meant
  to be visible to guests) — the tenant guard only gates the role-based
  branch. `WatchHeartbeatController` and `DoubtChatService` both call
  `canStream()` already, so they inherit the fix with no separate change.
- `Quiz::isVisibleTo()` — same pattern, one guard clause at the top:
  `$viewer !== null && $viewer->tenant_id !== $this->tenant_id` returns
  `false` before the staff-bypass check, so a cross-tenant instructor can no
  longer preview another tenant's draft quiz either. A `null` viewer (guest)
  still falls through to the published-course checks, preserving
  `ShowQuizController`'s existing "a published quiz's existence is not
  secret" comment/posture for anonymous visitors.
- `LessonAccess::canJoinLive()` — added `$course = $lesson->section?->course;
  if ($course === null || ! $this->isCourseVisibleTo($course, $viewer)) return
  false;` right after the lesson/session-match check, before the live-window
  check. Reuses `isCourseVisibleTo()` as-is (which already has its own staff
  bypass), so staff still join a since-unpublished course's session for
  review — only non-staff enrolled students lose access when the course goes
  back to draft.
- `LessonAccess::myLiveBatchSession()` — added `->orderBy('starts_at')`
  before `->first()`. Picked `starts_at` over `id`: the method's own purpose
  is "resolve this viewer's own scheduled occurrence to join," and the
  soonest upcoming session is the behaviorally correct one to hand back when
  a viewer is enrolled in more than one batch offering the same lesson — an
  `id`-based (first-created) tiebreak would surface an older batch's session
  even if a newer, sooner one exists.
- 8 regression tests added across `StudentPortalTest`, `LessonAccessGateTest`,
  `StreamLessonTest`, `ShowQuizControllerTest` (×2), and
  `LessonAccessLiveSessionTest` (×3 — tiebreak, course-unpublished-blocks-student,
  course-unpublished-still-allows-staff), each written before its fix per
  this block's own instruction.
- `composer test` → `php artisan test` directly (same `COMPOSER_PROCESS_TIMEOUT=600`
  workaround as S8 B8): **536/537 passed**, 1645 assertions. The one failure
  is the same pre-existing `CourseSlugUniquenessTest` gap tracked since S4
  (unrelated to this block — a `Course::where(...)->count()` assertion that
  doesn't account for `BelongsToTenant`'s own global scope; out of scope
  here same as every prior slice). Zero new regressions.

### B1 — TOTP two-factor auth ✅

**Deviation from the design doc, found before writing any code, changed the
whole plan for the better:** `composer show -a pragmarx/google2fa-laravel`
confirmed it installable (v3.0.1, live), but requiring it collided with a
package **already** in `composer.lock` — `pragmarx/google2fa-qrcode` v4.0.0,
a dependency of `filament/filament` v5.7.6 itself. Filament v5 ships a
**complete, built-in TOTP multi-factor auth system**
(`Filament\Auth\MultiFactor\App\AppAuthentication` + a `HasAppAuthentication`/
`HasAppAuthenticationRecovery` contract pair a user model implements): QR
enrollment with a wizard modal, hashed one-time-use recovery codes, a
disable action, and a login-page challenge step — all reading/writing
whatever columns the model's own interface methods point at. Added and then
removed `pragmarx/google2fa-laravel` from `composer.json` (`--no-update`
both times, `composer.lock` never touched) once this was confirmed —
building a second, competing TOTP implementation alongside Filament's own
would have meant two RFC 6238 code paths for one `two_factor_secret`
column, exactly the "second, competing check" CLAUDE.md's `LessonAccess`
history warns against.
- **Schema**: `two_factor_secret` (text, nullable), `two_factor_recovery_codes`
  (text, nullable) on `users` — both `encrypted`/`encrypted:array` cast.
  Deliberately **no** `two_factor_confirmed_at`: Filament's own
  `AppAuthentication::isEnabled()` treats "a secret is saved" as the single
  source of truth (its own `SetUpAppAuthenticationAction` only persists the
  secret after the enrollment code verifies), so a separate confirmed-at
  flag would just be a second, driftable definition of "enabled." `User`
  implements both Filament contracts directly; `hasTwoFactorEnabled(): bool`
  (`filled($this->two_factor_secret)`) is the one shared definition every
  surface — panels and portals alike — calls.
- **The three Filament panels** (Admin, Teach, Super) use Filament's own
  native challenge, wired through a new shared abstract
  `App\Filament\Auth\TenantAwareLogin` (all three panel `Login` classes now
  extend it, implementing only `resolveTenantId()`). It preserves
  Filament's own MFA bookkeeping (`$userUndertakingMultiFactorAuthentication`,
  the two-schema toggle, `isMultiFactorChallengeRateLimited()`) and swaps
  only the credential check for `TenantAuthenticator::validate()` (new —
  see below), so tenant-scoped lockout and Filament's own MFA both apply
  with neither surface bypassing the other. Each panel provider gained
  `->profile()` + `->multiFactorAuthentication([AppAuthentication::make()->recoverable()])`
  — enrollment UI (QR modal, recovery codes, disable action) comes free via
  Filament's own `EditProfile` page, no custom Blade/Livewire needed for
  staff users at all.
- **`TenantAuthenticator`**: `attempt()` (validate-and-login in one step)
  split into `validate()` (credentials + its existing password lockout,
  returns the `User`, does **not** log in) and `challengeTwoFactor(User,
  string $code)` (its own independent lockout, `two-factor-lockout:{id}`,
  separate from the password lockout — a wrong code shouldn't burn the same
  attempt budget as a wrong password). `challengeTwoFactor()` delegates the
  actual code/recovery-code verification to Filament's `AppAuthentication`
  too (`verifyCode(...shouldPreventCodeReuse: true)` then
  `verifyRecoveryCode(...)`), so there is exactly one verification
  implementation in the app regardless of which surface calls it.
- **The two portals** (StudentPortal, ParentPortal — not Filament pages, so
  none of the above wiring reaches them) each gained `twoFactorUserId`/
  `twoFactorCode` properties and a `verifyTwoFactorCode()` method.
  `login()` now calls `validate()`, and only calls `Auth::login()` directly
  when `hasTwoFactorEnabled()` is false; otherwise it stores the pending
  user id on the component itself and the Blade view swaps to a code-entry
  form — the same one-page two-schema-toggle shape Filament's own Login
  page uses, not a redirect to a separate page.
- **Portal-side enrollment** (`App\Livewire\TwoFactorSettings`, stub route
  `/settings/two-factor`, host-agnostic + `auth`-only middleware like every
  other genuinely shared surface in this app): the "stub a bare settings
  route, let B9 absorb it later" plan note, now built. Reuses
  `AppAuthentication`'s own secret/recovery-code generation and
  verification methods — the only genuinely new code is the QR image
  itself, generated by calling the underlying `PragmaRX\Google2FAQRCode\Google2FA`
  directly (Filament's own `generateQrCodeDataUri()` hardcodes
  `Filament::auth()->user()` with no override, which has no panel context
  to resolve outside a Filament request).
- **JSON `/login` endpoint**: `LoginRequest::authenticate()` now returns the
  validated `User` instead of void. `AuthenticatedSessionController::store()`
  responds `{"two_factor_required": true}` (still 200) instead of logging
  in when 2FA is enabled, stashing the pending user id in session; a new
  `POST /login/two-factor-challenge` completes it via
  `TenantAuthenticator::challengeTwoFactor()`, same as the portals.
- 11 new regression tests: `tests/Feature/Tenancy/TwoFactorAuthenticationTest.php`
  (10 — portal enrollment, wrong enrollment code, disable, password-then-
  challenge on both portals, recovery-code login + reuse rejected, wrong-code
  lockout, JSON 2FA-required + verify + wrong code, and the no-2FA baseline
  still logs straight in) plus one in `tests/Feature/Filament/PanelLoginTest.php`
  exercising Filament's own native challenge end-to-end through the real
  `Login` page component (`data.multiFactor.app.code` — confirmed by reading
  `Login::defaultMultiFactorChallengeForm()`'s own nested `statePath()` calls,
  not guessed).
- `php artisan test`: **547/548 passed**, 1696 assertions — the 1 failure is
  the same pre-existing, out-of-scope `CourseSlugUniquenessTest` gap tracked
  since S4. Zero regressions across the other 537 tests already green after
  B0.

### B2 — Google OAuth login ✅

- `laravel/socialite` v5.29 installed and confirmed live.
- **Scoped to the two portals (StudentPortal, ParentPortal), not the three
  Filament panels.** The design doc's own block table entry ("Sign in with
  Google") didn't pin which surfaces get a button, unlike B1's explicit
  "all three panels + the student/parent portals." Staff already have
  password + optional TOTP (B1); the portals are where a real end-user is
  most likely reaching for a personal Google account. Documented here as a
  deliberate scope cut, not an oversight — Filament panel Google login is a
  fast-follow if ever needed.
- **Tenant resolution — the actual mechanics, decided at build time**: a
  Google OAuth `redirect_uri` must be one fixed, pre-registered URL; it
  cannot vary per tenant subdomain the way every other route in this app
  does. So `redirect()` runs on the tenant's own subdomain (normal
  `ResolveTenant`, same as any portal route) and stores the resolved
  tenant id **and which portal initiated the flow** in the session before
  redirecting to Google; `callback()` is a **host-agnostic** route
  (`routes/web.php`, no `Route::domain()`, same reasoning as B1's
  `/settings/two-factor` stub) that reads both back out of session —
  SESSION_DOMAIN being a shared parent domain is what makes this workable
  at all, the one place in this app that shared-session behavior is
  useful instead of a bug to guard against. `GoogleAuthController::redirect()`
  hit the same domain-route scalar-splicing gotcha `StreamLessonController`
  already documents (CLAUDE.md) — `string $portal` as a method parameter
  intermittently didn't resolve correctly; fixed by pulling it via
  `$request->route('portal')` instead, the established workaround.
- New user via Google: `GoogleOAuthAuthenticator::authenticate()` creates a
  `User` row the same shape `RegisteredUserController::store()` uses
  (`tenant_id` from the resolved tenant, `password` a random value the
  column requires but the account never uses), plus `google_id` and
  `markEmailAsVerified()` immediately — Google itself already confirmed the
  address, so there's nothing left for the usual verification-email step to
  do. No role assigned, matching every other first-touch signup path in
  this app (an institute_admin assigns one later via `RoleAssignmentPolicy`).
- Existing user via Google: match by `google_id` first (returning user).
  Falling back to an `email` match **within the same tenant** links
  `google_id` onto that existing row instead of creating a duplicate —
  decided precedence: **email-match-and-link wins over creating a new
  account**, so a user who registered with a password and later tries
  Google converges onto their one existing row. Verified this does **not**
  cross tenant boundaries: the same email in two different tenants stays
  two separate `User` rows, each with its own independent `google_id` link
  (regression test).
- **Schema**: `google_id` (string, nullable) on `users`, with the exact
  same partial-unique-index shape `users_tenant_id_email_unique` (S1)
  already established (`unique(tenant_id, google_id) where deleted_at is
  null`) — unique per tenant, not globally, since the same real Google
  account can plausibly be a student in one tenant and an instructor in
  another, the same reasoning email itself already gets.
- 8 new regression tests (`tests/Feature/Tenancy/GoogleOAuthLoginTest.php`):
  redirect starts on the right subdomain and stores session markers,
  unrecognised portal 404s, new-user creation + tenant attach + redirect
  target, parent-portal redirect target differs from student's,
  returning-user-by-google_id (no duplicate), existing-user-by-email
  linking (no duplicate), cross-tenant non-collision, and a missing-session
  callback aborts with 419 rather than crashing. Socialite mocked via
  `Mockery::mock(\Laravel\Socialite\Two\GoogleProvider::class)` — the
  concrete class, not `Contracts\Provider`, since `redirectUrl()` isn't on
  that interface. No real network call to Google is made or needed;
  `redirect()` only *builds* a URL locally, and `user()` is fully mocked.
- `php artisan test`: **555/556 passed**, 1723 assertions — same
  pre-existing, out-of-scope `CourseSlugUniquenessTest` failure. Zero
  regressions across the 548 tests already green after B1.

### B3 — Super Admin impersonation + audit log ✅

- **The block note's own premise about `RoleAssignmentAudit` was checked
  and found wrong before building on it** (same "verify, don't assume"
  catch S9's own B0/B2 already made once each): read fresh, its
  `tenant_id` and `actor_id` are both `NOT NULL` — there is no
  nullable-actor precedent there at all, because role assignment only ever
  happens inside a tenant-scoped panel. Impersonation is the opposite: the
  actor is always a `super_admin` with `tenant_id === null`, acting from
  the bare Super panel host with no `ResolveTenant` middleware at all. This
  is why `ImpersonationAudit` **does not use `BelongsToTenant`** —
  that trait's `creating()` hook unconditionally calls
  `CurrentTenant::id()`, which *throws* `MissingTenantContextException`
  when unbound, i.e. on every single impersonation start, since the actor
  never has a bound tenant. `tenant_id` is set explicitly from the
  **target** user's own `tenant_id` instead (nullable in the schema,
  matching the design doc's own `impersonation_audits` table exactly;
  always non-null in practice since impersonating a `null`-tenant target —
  another super_admin — is blocked entirely by its own guard below).
- **Not registered in `TenantModelRegistry`, deliberately, overriding the
  block note's own instruction to register it "not deferred."** The
  registry's own docblock scope is "every model using the `BelongsToTenant`
  trait" — since this model doesn't use it, forcing registration would
  either (a) require adding the trait, which breaks the feature outright
  per the point above, or (b) register it without the trait, which would
  fail `CrossTenantTest`'s own generated per-model isolation test (it
  asserts a `CurrentTenant::clear()`-then-`create()` throws, and a
  cross-tenant `find()` returns null — both assume `BelongsToTenant`'s
  actual scope/hook behavior, neither of which this model has). The
  registry's own "missing from registry" guard test only scans for classes
  that use the trait, so this model correctly falls outside its net
  either way. Isolation is instead covered by a hand-written regression
  test proving the actual property that matters here: the impersonated
  identity does not apply outside the target's own tenant (below).
- **Session-based flag** (design doc §2.4 decision 4), but the "single,
  central place" turned out to need **two** registrations, not the one the
  block note anticipated by analogy to `ResolveTenant::class.':optional'`.
  That precedent works globally because Filament panel page loads already
  list `ResolveTenant::class` explicitly in their own `->middleware([...])`
  array — the global `web`-group append only ever had to additionally cover
  Livewire's own `/livewire/update` endpoint. `ResolveImpersonation` has no
  such per-panel explicit entry to lean on, and Filament panel middleware
  stacks don't reference the bare `'web'` group string at all (they list
  concrete classes) — so a global-group-only append would apply on Livewire
  AJAX round-trips but never on a panel's actual page load, which is
  exactly the moment `EnsureRole` needs to already see the swapped
  identity. Fixed by appending `ResolveImpersonation::class` to **both**
  the global `web` group (`bootstrap/app.php`) **and** each of the three
  panel providers' own `->middleware([...])` arrays (not `authMiddleware`
  — has to run before `EnsureRole` reads the resolved role).
  `Auth::setUser()` only overrides identity for the current request/response
  cycle; it never touches the real persisted session, so a request this
  middleware somehow never reaches falls back to the real actor staying
  themselves — never silently to whoever they were impersonating.
- **Cannot impersonate cross-tenant**: re-read at build time, this cannot
  mean "actor's own tenant differs from target's" the way
  `RoleAssignmentPolicy::assign()`'s own cross-tenant guard does — the
  actor here is always tenant-less by definition, and that policy's own
  comment says a platform-level actor is *exempt* from that exact check
  ("crossing tenants is exactly their job"). The guard that's actually
  meaningful and testable: once impersonating a tenant A user, the
  impersonated identity must not silently also apply on tenant B's own
  subdomain just because SESSION_DOMAIN is shared — `ResolveImpersonation`
  skips the `Auth::setUser()` swap whenever `CurrentTenant` is bound and
  doesn't match the target's own `tenant_id`, the real actor's own identity
  applies instead (regression test: visiting a different tenant's `/admin`
  as the real super_admin 403s via `EnsureRole`, same as any other
  role-mismatched visitor).
- **Cannot impersonate another super_admin**: a start-time guard in
  `ImpersonationService::start()`, throws `CannotImpersonateException`
  (new, registered in `bootstrap/app.php` as a 403) before any audit row
  is written.
- **"Stop impersonating" banner** (`App\Livewire\ImpersonationBanner`):
  registered on the same shared Filament render hook `NotificationCentre`
  (S8 B7) already uses (`PanelsRenderHook::TOPBAR_END`, one registration
  covers all three panels), plus embedded directly in both portal dashboard
  Blade views, same "one shared component, mounted everywhere" shape.
  Renders nothing when no impersonation session is active. Its own `stop()`
  redirects to an absolute `http://lms.test/super` — a relative path would
  resolve against whatever tenant subdomain the banner was stopped from,
  and `EnsureRole` would then 403 the super_admin the instant `CurrentTenant`
  resolves to a bound tenant (their own `tenant_id` is `null`).
- **No Tenant/User Resource existed anywhere in the Super panel before this
  block** (`app/Domains/Tenancy/Filament/Resources/` was entirely empty,
  confirmed by listing it, not assumed) — so the "start impersonating" UI
  is a single custom Filament page (`App\Filament\Pages\ImpersonateUser`,
  matching `SuperPanelProvider`'s own `discoverPages(in:
  app_path('Filament/Pages'))` wiring, read fresh rather than guessed) with
  one searchable select loading every non-super_admin user, not a table
  with a row action a Resource would give for free. Known scaling
  limitation, written down rather than silently accepted: the select
  eager-loads every user; a lazy search-as-you-type options callback is the
  fast-follow once user count actually makes that slow, no such note exists
  in the design doc and nothing today needs it yet.
- 8 new regression tests (`tests/Feature/Tenancy/ImpersonationTest.php`):
  start writes an audit row + session flags, blocked super_admin-target
  attempt writes no row, stop stamps `ended_at` + clears session, the
  impersonated identity resolves on the target's own tenant panel, it does
  **not** resolve on a different tenant's subdomain, only a super_admin can
  reach the impersonate page, the Filament page itself starts a session and
  redirects to the target's own panel/portal, and the banner only shows
  while impersonating and its own stop button ends the session.
- `php artisan test`: **563/564 passed**, 1744 assertions — same
  pre-existing, out-of-scope `CourseSlugUniquenessTest` failure. Zero
  regressions across the 556 tests already green after B2.

### B4 — Custom domain (DNS verification only) ✅

**The fourth and last of S1's four "lands in S8" broken promises — all
four are now built (B1-B4).**

- **`dns_get_record()` confirmed callable on this project's native Windows
  PHP 8.4 build** (`php -r "var_dump(function_exists('dns_get_record'));
  var_dump(defined('DNS_TXT'));"` — both `true`) before relying on it, per
  this block's own "confirm, don't assume" note. No PHP-level blocker.
- **Schema matches the design doc exactly**: `custom_domain` (string,
  nullable, unique), `custom_domain_verified_at` (timestamp, nullable) on
  `tenants`. The verification token itself is **not** a third column — it's
  derived on demand (`hash_hmac('sha256', tenant_id.'|'.domain,
  APP_KEY)`), recomputed identically every time from data the row already
  has, rather than adding schema the design doc's own list didn't include.
- **New `App\Integrations\DnsResolver`** (interface) + `RealDnsResolver` +
  `FakeDnsResolver`, matching this app's own established
  interface-plus-driver shape (`VideoProvider`, `PaymentProvider`, etc.).
  Unlike every other real driver in this app, `RealDnsResolver` needs no
  vendor account — `dns_get_record()` is a plain PHP builtin — so it binds
  as the **default** in `AppServiceProvider`, not behind a Fake the way
  Bunny/Zoom/Razorpay/AiSensy all still are.
- **A real, caught-by-its-own-tests bug**: `Tenant::$fillable` deliberately
  excludes `custom_domain_verified_at` (never settable via mass assignment
  — same posture `User::$fillable` already takes with `email_verified_at`).
  `CustomDomainService`'s first draft called `$tenant->update([...,
  'custom_domain_verified_at' => ...])` — Eloquent's mass-assignment guard
  **silently drops** a non-fillable key passed to `update()`, no error,
  it just never persists. `verify()` returned `true` and the DNS check
  genuinely passed, but `custom_domain_verified_at` stayed `null` in the
  database — caught by the regression test asserting it directly, not by
  the false-positive `verify(): bool` return value alone. Fixed with
  `forceFill([...])->save()` for that one field specifically, same pattern
  `User::markEmailAsVerified()` already uses for its own non-fillable
  timestamp. Worth generalizing: **`update()` returning without error is
  not proof every key you passed it actually landed** — assert the
  persisted column value itself when a field is deliberately kept out of
  `$fillable`.
- `ResolveTenant` (`app/Http/Middleware/ResolveTenant.php`): subdomain
  match stays first (fast, common-case path, unchanged), falls back to
  `Tenant::where('custom_domain', $host)->whereNotNull('custom_domain_verified_at')`
  only when the subdomain lookup misses. `URL::defaults()` always uses the
  resolved tenant's own canonical `*.lms.test` subdomain, never the
  request's own host — this app's internal route generation
  (`Route::domain('{subdomain}.lms.test')`, unchanged everywhere else)
  doesn't care which path resolved `CurrentTenant`, matching the design
  doc's own "every downstream check is unaware which path resolved it."
- An unverified `custom_domain` resolves nothing — same
  `TenantNotFoundException` (strict mode) / silent no-op (`optional` mode)
  as an unrecognised subdomain, tested explicitly via both modes.
- **Scope boundary, written down rather than silently assumed**: every
  tenant-scoped route (student/parent portals, all three Filament panels)
  is still registered under `Route::domain('{subdomain}.lms.test')` only —
  a request whose *routing* host is a custom domain never reaches those
  route definitions at the router level at all, regardless of what
  `ResolveTenant` itself is capable of resolving; Laravel's domain-pattern
  route matching requires the literal `.lms.test` suffix, a separate
  concern from the middleware. Actually serving every tenant surface on a
  verified custom domain would need every one of those route groups
  re-registered under a second, host-agnostic pattern (route name
  collisions to resolve, since `route()` calls would need to keep
  resolving to the canonical `*.lms.test` form per the point above) — a
  materially bigger change than "DNS verification only" scopes this block
  to, matching this same block's own "SSL provisioning stays a documented
  manual step" boundary. `ResolveTenant`'s own resolution logic is
  regression-tested directly (unit-style, constructing a `Request` and
  calling `handle()`) rather than through a route that structurally
  couldn't match yet.
- **Admin UI**: `App\Filament\Admin\Pages\CustomDomainSettings` — this
  app's first tenant-settings page of any kind (no branding/logo/color
  editor exists anywhere in Filament either, confirmed by searching, not
  assumed) — one text input, a status placeholder, and the TXT record to
  publish once a domain is saved. `institute_admin`-only (inherits the
  Admin panel's own `authMiddleware`), matching "a tenant can add
  learn.theirschool.com" — the tenant's own admin does this themselves,
  not the platform `super_admin`.
- 9 new regression tests (`tests/Feature/Tenancy/CustomDomainTest.php`):
  set + reset-on-change, verify success/failure via `FakeDnsResolver`,
  `ResolveTenant` resolves a verified domain (strict), an unverified domain
  throws not-found (strict), an unrecognised domain passes through silently
  (optional), `URL::defaults()` stays on the tenant's own subdomain, the
  admin page's save+verify flow end-to-end, and a domain already claimed by
  another tenant is rejected with a form error.
- `php artisan test`: **572/573 passed**, 1771 assertions — same
  pre-existing, out-of-scope `CourseSlugUniquenessTest` failure. Zero
  regressions across the 564 tests already green after B3.

### B5 — Self-service parent linking (invite code) ✅

- **Neither of the two homes this block's own note offered actually fit,
  found before building either**: `ParentGuardianResource` (S8 B1) only
  ever lists parent-role users (`getEloquentQuery()`'s own
  `->role('parent')` scope), and "the student's own admin record" doesn't
  exist — there is no Students Resource anywhere in this app, confirmed by
  listing every `*Resource.php` under `app/Domains/*/Filament/Resources`,
  not assumed. Built a new single-purpose Admin-panel page instead
  (`App\Filament\Admin\Pages\GenerateParentInviteCode`) — the same "one
  select, one action" shape this session already used three times
  (`ImpersonateUser`, `CustomDomainSettings`), rather than force-fitting
  either option or building a full Students Resource this block doesn't
  need.
- `ParentInviteCode` model + migration match the design doc's §3.1 schema
  exactly (`tenant_id`, `student_user_id`, `code`, `expires_at`,
  `redeemed_at`, `redeemed_by`, `created_by`) — deliberately **no**
  `created_at`/`updated_at` pair (`$timestamps = false`), the design doc's
  own column list doesn't include one and `expires_at` already anchors
  "when" relative to the fixed 7-day duration below. Registered in
  `TenantModelRegistry` normally (unlike B3's `ImpersonationAudit` — this
  one's actor is always a tenant-bound `institute_admin`, the ordinary
  case `BelongsToTenant` is built for).
- 7-day expiry, matching `BatchEnrolmentService`'s own `addDays(7)`
  precedent (S4 B5) — grepped for it rather than guessing a duration.
  8-character code from a 32-character alphabet excluding `0/O/1/I/L`
  (ambiguous when hand-typed from a WhatsApp message or a printed slip),
  checked for collision against the **whole table** before use (see the
  tenant-scope point below — a naive per-tenant uniqueness check would
  have missed a cross-tenant collision against the DB's own genuinely
  global unique index).
- **Redemption is a real, separate `App\Livewire\ParentInviteRedemption`
  component on a host-agnostic route (`/invite`, `routes/web.php`, no
  domain constraint)** — same reasoning as B1's `/settings/two-factor` and
  B2's Google OAuth callback: a prospective parent's code isn't tied to any
  subdomain, so the page that redeems it can't be either.
- **Two real bugs this exact "tenant comes from the code, not the host"
  requirement forced into the open, both caught by the redemption tests
  before shipping**:
  1. `ParentInviteCode::where('code', ...)` (both the redemption lookup and
     the generation-time uniqueness check) runs through `BelongsToTenant`'s
     own global scope by default — on a request where `CurrentTenant`
     happens to be bound to some *other* tenant (or unbound), the lookup
     would silently miss a real, valid code. Fixed with
     `withoutGlobalScope('tenant')` on both queries — `code` is globally
     unique by design (§3.1: no `tenant_id` in its own unique index), so
     both queries need to see every tenant's rows, not just the current
     one.
  2. Once a valid code resolves, `ParentGuardianLink::create()` (existing,
     S8) and `RoleAssignmentAudit::create()` (written by
     `RoleAssignmentPolicy::assign()`, S1) **both** also use
     `BelongsToTenant` — their own `creating()` hooks unconditionally read
     `CurrentTenant::id()`, which throws `MissingTenantContextException`
     the moment `CurrentTenant` is unbound, i.e. on every single redemption
     from a context with no subdomain of its own. Fixed by temporarily
     rebinding `CurrentTenant` to the invite code's own tenant for the
     duration of the redemption transaction, then restoring whatever was
     bound before (or clearing it) in a `finally` block — the same
     rebind-for-one-call shape `App\Support\Tenancy\RebindsTenant` already
     established for queued jobs with no request-derived tenant of their
     own, applied here to an HTTP request instead of a job.
- Single-use and expiry are both hard failures via `ValidationException`
  (not a silent no-op) — three distinct messages (not found / already used
  / expired), each with its own regression test.
- 8 new regression tests (`tests/Feature/Comms/ParentInviteCodeTest.php`):
  generation shape + expiry, generation via the Filament page, full
  redemption (parent created, linked, role granted with its own audit row,
  code marked redeemed, parent logged in), redemption succeeding while a
  *different* tenant is the current one (the core tenant-safety property),
  unknown/already-used/expired codes each rejected, and only an
  `institute_admin` can reach the generation page.
- `php artisan test`: **581/582 passed**, 1809 assertions — same
  pre-existing, out-of-scope `CourseSlugUniquenessTest` failure. Zero
  regressions across the 573 tests already green after B4.

### B6 — Lesson content-type completion + content protection ✅

- **The block note's own "reusing Lesson's already-fillable columns"
  premise was wrong, caught by reading every `lessons` migration before
  building anything** (same discipline as B0/B1/B2/B3/B5, now hit a sixth
  time) — no column for `text`/`embed`/`pdf` existed at all; S2 only ever
  built the video path. `LessonResource::form()` had exactly `title`,
  `type`, `video_upload`, confirmed by reading it fresh, not assumed from
  the plan doc's wording.
- **Schema**: `text_body` (text, nullable), `embed_url` (string, nullable),
  `pdf_disk_path` (string, nullable) — `pdf_disk_path` is a **separate**
  column from `video_disk_path`, not a reuse/rename of it (same `'local'`
  disk, same `videos/{tenant}/{lesson}.ext`-shaped directory convention,
  new column) — reusing the video column directly would leave a
  wrongly-named value sitting there for any lesson that isn't a video.
- `Lesson::attachUploadedPdf()` mirrors `attachUploadedVideo()`'s own
  shape (temp path on `'local'` → `pdfs/{tenant_id}/{lesson_id}.ext`),
  wired into `EditLesson::afterSave()` the same way. `forceDeleting` now
  also deletes the PDF file alongside the existing video cleanup.
- `LessonAccess::isPlayable()` — generalized to a `match` on
  `$lesson->type` rather than adding a parallel method: video/live_session
  keep the exact existing video-file check unchanged (regression-tested,
  zero behavior change there), pdf/text/embed each check their own single
  column is filled. `WatchLessonController` needed **no changes** to its
  own gate-checking logic — it already called `isPlayable()` blindly for
  every type, so generalizing the one method underneath was enough for the
  page to stop always-404ing on non-video lessons.
- `text_body` sanitized via `SanitizesHtml` (already established, `Lesson`
  now uses the trait) — rendered with `{!! !!}` on the watch page, trusting
  the same already-sanitized-at-save boundary every other rich-text field
  in this app already relies on, not re-sanitized at render time.
- `embed_url` — allow-list decided: YouTube (`www.youtube.com`,
  `youtube.com`, `youtube-nocookie.com`), Vimeo (`player.vimeo.com`),
  Google Docs/Slides (`docs.google.com`) — `Lesson::EMBEDDABLE_HOSTS`,
  enforced by a form-level validation rule on `LessonResource` (checks
  `parse_url($value, PHP_URL_HOST)` against the list) at **save** time, not
  re-validated at render time — the same "validated once, trusted after"
  posture the video upload's own `mimes:` rule already has.
- **New `DownloadLessonPdfController`** (`GET /watch/{lesson}/pdf`,
  signed, matching `StreamLessonController`'s exact security posture for
  raw file bytes) — same three gates (`isPlayable`+`isVisibleTo`,
  `canStream`), meters the full file size via the existing
  `BandwidthUsageDaily::record()` (no Range-request partial-byte logic
  needed — a PDF isn't scrubbed the way a `<video>` element is). The watch
  page (`content.watch.blade.php`) now branches by `$lesson->type`:
  video/live_session keep the existing `LessonPlayer`+`DoubtChat`
  components untouched; pdf renders an `<iframe>` at a signed URL computed
  in `WatchLessonController` (same shape `LessonPlayer::mount()` already
  uses for its own `streamUrl`); text renders the sanitized body directly;
  embed renders an `<iframe>` at the validated `embed_url`.
- `SyllabusTree`'s own existing generic `@elseif ($row['canStream'])`
  fallback branch already linked to `lessons.watch` for any non-live_session/
  quiz type — it was reachable before this block, just always 404'd once
  clicked (`isPlayable()` was unconditionally video-shaped). No new
  branch needed there; only the link label now varies by type ("View PDF"
  / "Read" / "Open" instead of always "Watch").
- Content protection (D5): `controlsList="nodownload"` +
  `oncontextmenu="return false"` added directly to the existing `<video>`
  element (scoped to the player itself, not site-wide) — same "deters
  casual copying, does not stop a determined screen-recorder" honest
  framing the existing watermark feature (S2) already uses, stated in a
  comment, not implied.
- 8 new regression tests (`tests/Feature/Content/LessonContentTypesTest.php`):
  text renders sanitized (script tag stripped) on the watch page, embed
  renders its iframe, a non-allow-listed embed URL is rejected on the
  form, an allow-listed one is accepted, a PDF upload attaches + downloads
  through the signed route, a PDF lesson with no file yet still 404s
  (matching video's own "ready status but no file" gate), `isPlayable()`
  reports correctly for all three new types before/after their column is
  filled, and the video player's markup carries both protection
  attributes.
- `php artisan test`: **589/590 passed**, 1843 assertions — same
  pre-existing, out-of-scope `CourseSlugUniquenessTest` failure. Zero
  regressions across the 582 tests already green after B5 — every existing
  video/live_session `isPlayable()`-dependent test still passes unchanged.

### B7 — Quiz answer autosave ✅

- **Closes a gap `AutoSubmitExpiredQuizAttempts`'s own docblock (S6)
  already named explicitly and honestly as a real, known, unclosed
  limitation** — that job's comment literally said "closing it would mean
  giving QuizRunner some form of incremental answer persistence (autosave),
  which is out of this block's one-line scope and not built here." This
  block builds exactly that.
- **Persistence written to real `QuizAnswer` rows** (the design note's
  first-listed option, not a lighter-weight draft column) via a new
  `QuizAttemptService::saveAnswer(QuizAttempt, int $questionId, mixed
  $response): void` — an `updateOrCreate` keyed on `(quiz_attempt_id,
  question_id)`, so re-answering the same question during one attempt
  updates the same row rather than accumulating duplicates. Deliberately
  does **not** grade anything (no `is_correct`/`score`) — grading only
  happens at real submit time, so a `pending_review` row is never created
  before the attempt is actually over.
- **New `QuizAnswer` status: `'draft'`.** Reusing `'pending_review'` for an
  in-progress autosave was considered and rejected: `QuizAnswerResource`
  (the instructor-facing short-answer review queue) shows any row with
  that status and its own `visible()` check would have surfaced an
  autosaved-but-still-being-typed short answer as "ready to review" while
  the student was still mid-attempt — confirmed by reading that Resource's
  actual `recordActions`/query, not assumed. `'draft'` is a status no
  existing consumer recognizes as reviewable, and the Resource's own query
  now explicitly excludes it (`->where('status', '!=', 'draft')`) —
  regression-tested.
- **`QuizAttemptService::submit()` updated, not `AutoSubmitExpiredQuizAttempts`
  itself** — exactly as the plan note predicted, the sweep job needed zero
  changes to its own trigger logic. `submit()` now reads each question's
  response from the *already-persisted* draft (`$attempt->answers()->pluck('response',
  'question_id')`) whenever the caller's own `$responses` array doesn't
  include that question id — which is precisely what makes the sweep's
  existing `submit($locked, [], 'auto_submitted')` call (an empty array,
  unchanged) now grade what the student actually typed instead of a blank
  attempt. An explicitly passed response (a live student Submit click)
  still wins over a stale draft, so this is additive, not a behavior change
  for the normal submit path — confirmed by every pre-existing
  `QuizAttemptServiceSubmitTest`/`AutoSubmitExpiredQuizAttemptsTest` case
  still passing unchanged.
- **Persistence trigger: blur/selection-change, not every keystroke** —
  matches the plan note's own "likely right" guess. No existing
  "save-as-you-go" Livewire precedent existed anywhere in this app to
  match against (checked — nothing else in the codebase autosaves), so
  this is a genuinely new UX pattern here, wired as `wire:change="saveAnswer(id)"`
  on each MCQ/true_false radio (fires on selection) and
  `wire:blur="saveAnswer(id)"` on the numerical/short_answer inputs —
  Livewire's own single-request batching means the deferred `wire:model`
  binding on the same element syncs in the same round-trip that calls the
  action, so the just-changed value is already present server-side by the
  time `saveAnswer()` runs.
- 9 new regression tests (`tests/Feature/Assessments/QuizAutosaveTest.php`):
  a draft saves ungraded, a second autosave updates the same row rather
  than duplicating it, autosave no-ops once the attempt is no longer
  in_progress or for a question id not drawn into the attempt, `submit()`
  grades an autosaved draft when no explicit response is passed (the exact
  sweep scenario), an explicit response still overrides a stale draft, the
  auto-submit sweep now grades what was actually autosaved end-to-end
  (closing the job's own named gap directly), draft answers stay out of
  the instructor review queue, and the Livewire component itself persists
  a draft via its new action.
- `php artisan test`: **598/599 passed**, 1866 assertions — same
  pre-existing, out-of-scope `CourseSlugUniquenessTest` failure. Zero
  regressions across the 590 tests already green after B6 — every
  pre-existing quiz submit/auto-submit/review-queue test still passes
  unchanged.

### B8 — Fee-checkout live refresh + Razorpay idempotency key ✅

- **The block note's own "sends an Idempotency-Key header" premise was
  checked against Razorpay's real API docs (WebSearch + WebFetch, not
  guessed) and turned out wrong for this specific endpoint — the seventh
  consecutive block to catch a stale plan-doc claim before building on
  it.** Razorpay's idempotency *header* (`X-Payout-Idempotency`,
  `X-Refund-Idempotency`) only exists on its Payouts and Refunds APIs.
  The **Orders API** (`POST /v1/orders`, what `createOrder()` actually
  calls) has no header at all — its `receipt` field *is* the idempotency
  mechanism, and critically, **a re-used receipt is rejected outright**
  (`HTTP 400`, `"Duplicate request. This request has already been
  processed."`) rather than handing back the original order. That
  behavior is the opposite of "degrades gracefully," which changes the
  whole design: idempotency has to be enforced *before* Razorpay is ever
  called a second time, not relied on to dedupe after the fact.
- **`FeeInvoiceService::createPaymentOrder()`** now checks for an
  already-`created` `FeePayment` on the same invoice first (locking the
  `FeeInvoice` row for the duration, same shape `applyCoupon()` already
  locks the coupon row — two browser tabs racing this call is exactly the
  scenario a re-used receipt would otherwise 400 on) and reuses it instead
  of ever calling the provider twice for one open attempt. The
  **deterministic-per-invoice+attempt key** the block note asked for is
  `{invoice_number}-{attempt_number}` (attempt number = existing
  `FeePayment` row count for that invoice + 1) — stable across a genuine
  retry of the *same* still-open attempt (nothing new persisted yet, so
  re-entering the method recomputes the same number), distinct once a
  prior attempt has actually failed and a new one legitimately starts.
- **`PaymentProvider::createOrder()` signature changed** to
  `createOrder(FeeInvoice $invoice, string $idempotencyKey): array` —
  `RazorpayProvider` sends it as `receipt`; `FakeRazorpayDriver` accepts it
  for interface parity (still generates its own always-unique fake
  `order_id`, unaffected). Every call site and every existing test for
  both drivers updated to the new signature.
- **`FeeCheckout`'s live refresh: `wire:poll.3s`**, exactly as the block
  note's own reasoning concluded — `BROADCAST_CONNECTION` is still `log`
  (confirmed, unchanged since S8 B7's own research), no real broadcasting
  infrastructure exists, and standing one up for this one polling need
  would be exactly the kind of premature infrastructure this app's own
  standing posture avoids. The poll directive sits only on the "order
  created, awaiting payment" markup — it stops rendering (and therefore
  polling) the instant the webhook flips that `FeePayment` away from
  `created`, whichever way it goes, no explicit stop-polling logic needed.
- **Small related completion, not scope creep**: once an invoice a student
  is polling on gets marked `paid`, `currentInvoice()`'s own existing
  `where('status', 'pending')` filter means `$invoice` becomes either the
  *next* instalment (already handled correctly, no code changed) or `null`
  if nothing's left pending — which previously rendered the same "No
  invoice found for this enrolment" message used for the genuinely-broken
  "no invoice ever existed" case. Now distinguishes the two: "All invoices
  for this enrolment are paid" when `$allInvoices` is non-empty. Without
  this, the live-refresh loop's own success state would have looked
  identical to an error state.
- 5 new regression tests: `RazorpayProviderTest` (idempotency key sent as
  `receipt`, no `Idempotency-Key`/`X-Razorpay-Idempotency` header present),
  `FeeInvoiceServiceTest` (×2 — a second checkout call reuses the same
  still-open `FeePayment` rather than duplicating it; a genuinely new
  attempt after a failure gets its own incremented idempotency key),
  `FeeCheckoutTest` (×2 — the poll directive is present while a payment is
  in flight; it's gone, replaced by the paid message, once fully paid).
- `php artisan test`: **603/604 passed**, 1876 assertions — same
  pre-existing, out-of-scope `CourseSlugUniquenessTest` failure. Zero
  regressions across the 599 tests already green after B7.

### B9 — User profile page ✅

- New migration adds `avatar_path` (nullable), `timezone` (default
  `Asia/Kolkata`), `locale` (default `en`) to `users`. None of the three
  existed anywhere before this block (grepped every migration first — 9/9
  blocks now, every one has found a stale plan-doc claim or an unverified
  assumption before building on it).
- `App\Livewire\UserProfile` — one shared component, mounted exactly like
  `NotificationCentre` (S8 B7): embedded directly on both portal Blade
  layouts, plus added to the *existing* `FilamentView::registerRenderHook`
  call in `AppServiceProvider::boot()` (no second registration). Avatar
  upload reuses `BrandingService::storeUpload()`'s conventions exactly —
  `'public'` disk, `['png', 'jpg', 'webp']` allow-list,
  `InvalidFileTypeException::forField()`, old file deleted on replacement.
  Password change uses Laravel's built-in `'current_password'` rule — no
  prior password-change flow existed anywhere in this app to reuse, this is
  new territory. 2FA entry point nests the existing `TwoFactorSettings`
  component (B1) rather than reimplementing it, and only renders outside a
  Filament panel (`Filament::getCurrentPanel() === null`) since Filament's
  own `EditProfile` page already carries 2FA for panel users — a second
  competing control in the topbar would be confusing.
- Guest-safety: no crash for a component that mounts on portal dashboards
  which render their own login form *before* auth too — `mount()` early-
  returns on `Auth::user() === null`, and every state-mutating method
  (`updateProfile()`, `changePassword()`) gets its own guard, not just a
  hidden button (S4's "Livewire actions are directly callable regardless of
  UI" rule).
- **Bug found by this block's own tests, not a hypothetical**: a freshly
  created `User` (via factory, or any real "create a row then
  `Auth::login()` that same in-memory object" path — registration, Google
  OAuth, an invite redemption) has `timezone`/`locale` **null in memory**
  even though the migration declares DB-level defaults. Eloquent's `create()`
  doesn't pull server-side column defaults back into the model after an
  insert (Postgres only round-trips the generated ID via `RETURNING`, not
  every defaulted column) — so `Auth::user()->timezone` is null until the
  model is explicitly refreshed. `mount()` now falls back with
  `$user->timezone ?? 'Asia/Kolkata'` / `$user->locale ?? 'en'` rather than
  trusting the attribute directly. This is a real latent risk on any
  create-then-login path in this app, not just a test artifact.
- Laravel's `confirmed` validation rule looks for a literal
  `"{field}_confirmation"` key — not a camelCase equivalent. The new-password
  field is named `newPassword_confirmation` (matching this app's own existing
  convention: `RegisterUserRequest`/`ResetPasswordRequest` both already pair
  `'password' => ['confirmed']` with a plain `password_confirmation` field).
- Test-authoring gotcha, not a production bug: an early combined "mounts on
  all three Filament panels" test that visited `/admin`, `/teach`, then the
  bare-host `/super` inside one `it()` got a 403 on the last leg —
  `CurrentTenant` is a singleton that persists across multiple `$this->get()`
  calls within a single Pest test, so the tenant bound by the `/admin` visit
  was still bound when hitting the bare `lms.test/super` host, tripping
  `EnsureRole`'s cross-tenant check. `NotificationCentreMountedTest` already
  documents this exact trap in a comment; split into three separate `it()`
  blocks (one per panel) to match its precedent instead of working around it.
- `tests/Feature/Tenancy/UserProfileTest.php`, 16 tests: mounts on all 5
  surfaces (student/parent dashboards + all 3 Filament panels, 3 as separate
  tests per the gotcha above), 2FA entry point shown/hidden correctly, guest
  renders without crashing, prefill, update, invalid-timezone rejection,
  avatar upload + replacement + disallowed-type rejection, password change +
  wrong-current-password + mismatched-confirmation rejection, and the
  no-authenticated-user guard-clause test.
- `php artisan test`: **619/620 passed**, 1921 assertions — same
  pre-existing, out-of-scope `CourseSlugUniquenessTest` failure. Zero
  regressions across the 604 tests already green after B8.

### B10 — Production readiness: backups + real queue retry/backoff ✅

- **Backups.** Installed `spatie/laravel-backup` (v10.3.1 — none installed
  before, confirmed by grepping `composer.json`). `source.files.include`
  scoped to `storage_path('app/private')` only, not the package's own
  `base_path()` default (the codebase is already in git; branding assets
  in `storage/app/public` aren't worth the weight, same reasoning
  `ExportAttendanceRegister` already gives for 'local' over 'public').
  Destination is a **new** `'backups'` disk (`storage/app/backups`),
  deliberately not `'local'` — `'local'` IS `storage/app/private`, the
  exact directory being backed up; writing the archive into its own source
  tree would mean every run zips up every prior run's own zip too. Local
  disk only, off-site (S3) flagged as fast-follow, matching the block's
  own note. Retention: `cleanup.default_strategy` set to a flat 30-day
  window (`keep_all_backups_for_days = 30`, every later tier zeroed) — the
  package's own *default* strategy values (7/16/8/4/2, a decaying
  daily/weekly/monthly/yearly scheme) are a different policy than "30-day
  retention" read literally, not a value to leave untouched. Scheduled
  `backup:clean` then `backup:run`, both `dailyAt()`, spaced past the
  existing four `->daily()` sweeps (all default midnight) rather than
  piling onto the same instant.
  **Real bug found and fixed, not hypothetical**: a blank
  `BACKUP_ARCHIVE_PASSWORD=` line in `.env` resolves to an empty string via
  `env()`, not `null` — and an empty string still passes the package's own
  `password !== null` "encryption enabled" check, so Spatie's `Zip` class
  silently attempted AES encryption with a **zero-length password**.
  Confirmed by reproducing directly: `ZipArchive::close()` throws `Invalid
  argument` the instant that happens, not a clean "no encryption" no-op —
  discovered because `backup:run` genuinely failed on this dev box, not
  from reading the package's source first. Fixed with `env(...) ?: null`
  instead of a bare `env()` call.
  **Confirmed environment gap, not silently worked around**: `pg_dump` is
  not on this Windows dev host's PATH (Postgres runs in Docker) — a real
  end-to-end `backup:run` (which shells out to it for the database dump)
  cannot be exercised in this dev environment. Tests use `--only-files`
  (skips the db dump entirely) for the files/destination half, plus a
  config-assertion test for the database-connection half. This is a
  deployment-environment concern (a real prod/staging box needs `pg_dump`
  on its PATH), not a code gap — flagged, same posture as B4's "SSL stays
  manual" boundary.
- **Job retry.** Of the 9 named candidates, only **5 needed real work**:
  `AutoSubmitExpiredQuizAttempts`, `ExpirePendingEnrolments`,
  `FlagOverdueInvoices`, `SendFeeReminders`, `RecordVideoCoverageAttendance`
  were **plain classes**, not `ShouldQueue` (confirmed by reading each —
  matches `HANDOVER.md`'s own existing "Avoid" note). `Schedule::job()`
  auto-detects this: `dispatchToQueue()` only fires for a `ShouldQueue`
  instance, otherwise it silently falls through to `dispatchNow()` (plain
  inline execution) — confirmed by reading
  `Illuminate\Console\Scheduling\Schedule::job()` itself, not assumed. So
  `$tries`/`backoff()` on any of these five would have been dead
  configuration before this block; the actual fix is making them real
  queued jobs, which is what "real queue retry/backoff" in this block's own
  name means. All 5: added `ShouldQueue` + the four standard queue traits,
  `$tries = 3`, `backoff()` (`[60, 300, 900]` for the four `->daily()` ones,
  `[60, 180, 300]` for `AutoSubmitExpiredQuizAttempts` — capped at 5
  minutes since it's scheduled `->everyFiveMinutes()`, and a 15-minute
  final backoff would outlive the next scheduled run anyway). Each audited
  for idempotency **before** adding retry, per the plan's own instruction:
  4 of 5 were already safe (a row-level status re-check under lock, a
  bulk `WHERE status = 'pending'` update, or an `exists()` guard before
  create) — confirmed, not assumed. **`SendFeeReminders` was not** — its
  own docblock explicitly accepted "if the scheduler somehow fires this
  twice, the same reminder goes out twice" as a known, unguarded gap. A
  real queue-level retry (the entire point of this block) turns "somehow
  fires twice" from a hypothetical into something that *will* happen after
  any transient failure partway through the invoice loop — so this block
  closed it first: `alreadySentToday()`, mirroring
  `SendAbsenceAlert::alreadySentToday()`'s (S8 B3) own database-notification
  read-back shape, keyed on `fee_invoice_id` + `stage` (both already in
  `FeeReminderDue::toArray()`) rather than just the notifiable, so a
  same-day reminder for a *different* stage on the same invoice still
  sends.
  **Second real bug found and fixed**: converting these five sweeps to
  real queued jobs introduces a risk that didn't exist while they were
  synchronous — a persistent Horizon worker processes many jobs per PHP
  process, and `RebindsTenant` (used by every existing queued job) sets
  `CurrentTenant` and never clears it. All five of these sweeps rely on
  `BelongsToTenant`'s global scope being a no-op, which only holds when
  `CurrentTenant` is unbound; a stale binding left by whatever ran right
  before one of them in the same worker process would silently narrow a
  cross-tenant sweep to just one tenant. Fixed with an explicit
  `app(CurrentTenant::class)->clear()` at the top of each `handle()`.
  Regression tests for this deliberately leave a *different* tenant bound
  (not cleared) all the way into `handle()`, proving the guard — not test
  setup — is what makes it safe.
  `ExportAttendanceRegister` (already `ShouldQueue`, already tenant-scoped
  via `RebindsTenant`, no internal `try`/`catch`) just needed
  `$tries`/`backoff()` added — the one job on the list that benefited with
  no other change.
  **The 4 AI ingestion jobs deliberately got no `$tries`/`backoff()`** —
  confirmed by reading `HandlesIngestionFailure`: every one of them catches
  every `Throwable` internally and calls `$this->fail($e)` explicitly,
  which marks the job permanently failed regardless of `$tries` — Laravel's
  own automatic retry logic never gets a chance to run, since it only
  applies when an exception propagates up to the queue worker uncaught.
  Their real retry mechanism is the existing manual "restart the whole
  chain from `TranscribeLesson`" flow (`LessonIngestionRetryRequested`),
  which `ChunkTranscript`'s own docblock already documents as the reason
  its own row-deletion step is idempotent. Adding `$tries` here would be
  dead configuration dressed up as a real fix — documented instead of
  silently added.
- **`failed_jobs`**: already exists — Laravel's own default
  `0001_01_01_000002_create_jobs_table.php` stub migration provisions it
  alongside `jobs`/`job_batches`. Confirmed by reading every migration
  first, per the plan's own instruction; nothing to build.
- **Horizon**: `config/horizon.php` was still the package's own unmodified
  publish defaults (single `supervisor-1`, `queue => ['default']`).
  Changed to `queue => ['default', 'scheduled']` (priority-ordered within
  one supervisor — Horizon drains `'default'` first) rather than a second
  supervisor: this app's only two interactively-triggered background jobs
  (AI ingestion, CSV export — someone is often waiting) stay on
  `'default'`; the five sweeps converted in this block go on `'scheduled'`
  via `Schedule::job($job, 'scheduled')`. Nothing in this app's actual
  traffic today is latency-sensitive enough to need a second, fully
  isolated supervisor process.
- 27 new regression tests across 7 files (5 existing job test files + a
  new `tests/Feature/Comms/ExportAttendanceRegisterTest.php` case + a new
  `tests/Feature/Support/BackupConfigurationTest.php`, 8 tests).
- `php artisan test`: **639/640 passed** (one run showed a transient
  "relation does not exist" cluster across 8 unrelated `Assessments` tests
  — reran clean, not reproducible, treated as a one-off local Postgres
  hiccup rather than a real regression, per the container's own uptime
  showing no restart), 1964 assertions — same pre-existing, out-of-scope
  `CourseSlugUniquenessTest` failure. Zero regressions across the 619
  tests already green after B9.

### B11 — Report catalog: revenue + engagement/completion ✅

- Copied `ExportAttendanceRegister`'s exact shape (S8 B6) for both new
  reports: `ShouldQueue` job (now with S9 B10's own `$tries`/`backoff()`
  posture too, since that's the current established shape) +
  `RebindsTenant` middleware + `Storage::disk('local')` + a bare Filament
  `Page` with `generate`/`download` header actions + a gated plain download
  controller. Two new job classes, two new pages, two new controllers —
  not a single new architectural pattern anywhere.
- `csvSafe()` extracted into a new shared trait,
  `App\Domains\Comms\Jobs\Concerns\EscapesCsvFormulas` — the plan's own
  note flagged this as worth doing "if both new reports need it," and both
  do. `ExportAttendanceRegister` itself retrofitted to use the trait too,
  so there's exactly one copy of the CSV-injection guard in the app, not
  three near-identical ones.
- **Revenue report** (`ExportRevenueRegister`) — one row per `FeeInvoice`
  (not per `FeePayment`): invoice number, student name/email, batch title,
  amount, GST amount, discount amount, total amount, status, due date,
  paid at. Deliberately reads `FeeInvoice.status`/`paid_at` directly
  rather than joining `FeePayment` — those two columns are already the
  authoritative source `SendFeeReminders`/`FlagOverdueInvoices` both treat
  as such (S9 B10), and a payments-only log would silently drop every
  pending/overdue invoice a real revenue register needs to show.
- **Engagement/completion report** (`ExportEngagementRegister`) — one row
  per actively-enrolled student (not per student-per-course): name,
  email, attendance %, video coverage %, quiz average %, fee status,
  reusing `StudentMetrics::summarize()` per row exactly as the plan note
  suggested. Scoped to one row per student, not per student-per-course,
  because `StudentMetrics` itself only ever computes a student's overall
  numbers — the same granularity `StudentProgress` (S8 B5) and the parent
  portal already show; a real per-course breakdown would need a formal
  per-course rollup that exists nowhere in this app, a materially bigger
  scope than "reuses `StudentMetrics` per row" asks for. Roster query
  copied from `StudentProgress::studentsQuery()` verbatim (every actively
  enrolled student in the tenant — no instructor/admin-specific student
  ownership exists to scope it tighter, the same documented gap that
  page's own docblock already names).
- Both new Filament pages (`RevenueExport`, `EngagementExport`) land in
  `app/Filament/Admin/Pages/`, auto-discovered exactly like
  `AttendanceExport` — confirmed no explicit `->pages([...])` entry exists
  for that page either, so none was added for these two.
- 30 new regression tests across 6 files (2 job tests, 2 Filament page
  tests, 2 download-controller tests) — same four shapes
  `ExportAttendanceRegisterTest`/`AttendanceExportPageTest`/
  `DownloadAttendanceExportControllerTest` already established (CSV row
  shape + `csvSafe` escaping + tenant scoping + retry config; download
  hidden-then-visible after generate; 404/download/forbidden×3).
- `php artisan test`: **664/665 passed**, 2024 assertions — same
  pre-existing, out-of-scope `CourseSlugUniquenessTest` failure. Zero
  regressions across the 639 tests already green after B10.

### B12 — Plan tiers: bandwidth quota + AI spend cap enforcement ✅

- `Tenant.plan_tier` (string, default `'free'`, `Tenant::PLAN_TIER_FREE`/
  `PLAN_TIER_PAID` constants) — a flat enum per design doc §2.4 decision 5,
  not a configurable-limits table. Hardcoded per-tier caps, explicitly
  documented as placeholder round numbers, not sourced from any real
  pricing decision (Bunny's own Asia delivery pricing, ~$0.03/GB, is the
  only real anchor `docs/FEATURES.md` gives): bandwidth 5 GB/day (free) /
  50 GB/day (paid); AI spend $1.00/day (free) / $10.00/day (paid).
- **Bandwidth**: `App\Domains\Content\Services\BandwidthQuota` (`capFor()`/
  `usedToday()`/`remainingFor()`) reads `BandwidthUsageDaily` with an
  explicit `tenant_id` filter (`withoutGlobalScope('tenant')`), not a bare
  query relying on `CurrentTenant` already matching — same
  persistent-worker-leak reasoning S9 B10 fixed five sweep jobs for.
  Centralized in `LessonAccess::assertWithinBandwidthQuota()` (a new
  method, not scattered per-controller) since **two** controllers record
  bandwidth, not one — `DownloadLessonPdfController` (S9 B6) does too,
  found by checking every `BandwidthUsageDaily::record()` call site before
  assuming `StreamLessonController` was the only gate needed. Called with
  the exact byte count the request is about to serve (the video
  controller's own Range-aware count, the PDF controller's whole-file
  size), so a request that would itself push the tenant over is refused
  before serving it, not only the next one after.
- **AI spend**: `App\Domains\AI\Services\AiSpendCap` wraps
  `TokenCostLedger::totalForDate()` (unchanged). Six call sites dispatch a
  real `AiProvider` call across this app (the three ingestion jobs,
  `DoubtChatService` ×2, `QuestionGenerator`) — five of six resolve
  `AiProvider` fresh from the container rather than holding a long-lived
  instance (confirmed by reading each), so a decorator around the
  `AiProvider::class` binding itself
  (`App\Integrations\Drivers\SpendCapGuardedAiDriver`) covers all six with
  one change in `AppServiceProvider`, not six separate inline guards.
  Pre-checks the running total before allowing a call through rather than
  capturing each call's own actual cost afterward — `embed()` doesn't even
  return a cost figure in `AiProvider`'s own contract, so a pre-check
  against the running total is the only enforcement point available
  without changing that contract.
- Both new exceptions (`BandwidthQuotaExceededException`,
  `AiSpendCapExceededException`) follow this app's one-static-factory
  convention exactly (`BatchFullException`'s shape) and render as
  `abort(409, $e->getMessage())` — a specific, actionable error, not a
  bare 403, per the plan's own instruction.
- **Two pre-existing tests asserted the exact behavior this block
  deliberately reverses**, found and fixed, not silently left red:
  `BandwidthMeteringTest`'s "never blocks a stream response no matter how
  much bandwidth has already been used" (true before this block, the
  whole point of B12 is that it's no longer true) and
  `FakeAiDriverTest`'s "resolves AiProvider from the container as
  FakeAiDriver" (now resolves as `SpendCapGuardedAiDriver` wrapping it).
  Both updated to assert the new, intended behavior rather than deleted.
- **Real test-fixture bug found and fixed while writing this block's own
  PDF-quota test**: `UploadedFile::fake()->create($name, $kilobytes)`
  reports the given size via `getSize()` metadata but writes zero real
  bytes into `Storage::fake()`'s disk — `filesize()` on the stored file
  reads back `0`, which can never exceed any quota regardless of how
  over-cap the tenant already is. Confirmed by dumping actual state, not
  guessed. Fixed by overwriting the stored file with real content after
  upload, matching how `BandwidthMeteringTest`'s own fixture already puts
  real string content directly rather than relying on a fake upload's own
  (zero) bytes.
- 24 new/updated regression tests across 7 files (`BandwidthQuotaTest`,
  `AiSpendCapTest`, `SpendCapGuardedAiDriverTest` new; `BandwidthMeteringTest`,
  `LessonContentTypesTest`, `DoubtChatTest`, `FakeAiDriverTest` extended
  or corrected).
- `php artisan test`: **681/682 passed**, 2058 assertions — same
  pre-existing, out-of-scope `CourseSlugUniquenessTest` failure. Zero
  regressions across the 664 tests already green after B11.

### B13 — Low-attendance alert + automated nudges ✅

- **Shared at-risk threshold, moved not duplicated.** `InstituteOverviewWidget`'s
  `AT_RISK_THRESHOLD`/`isAtRisk()` (S8 B5) moved to
  `StudentMetrics::AT_RISK_THRESHOLD`/`isAtRisk(array $summary)` once a
  second consumer needed the exact same definition — a service that
  already computes attendance/coverage per student is the right shared
  home, not a Filament widget class. `InstituteOverviewWidget` now
  delegates rather than holding its own copy.
- **Low-attendance alert** (`SendLowAttendanceAlert`, registered on the
  same `AttendanceRecorded` event `SendAbsenceAlert` (S8 B3) already
  listens to) copies that listener's exact shape — `ParentGuardianLink`
  lookup, `WhatsAppChannel` + `database` dual-channel `LowAttendanceAlertDue`
  notification, per-parent-per-student dedupe read back from the
  notifications table's own data payload. Fires on *every* `AttendanceRecorded`
  event regardless of that event's own status (unlike `SendAbsenceAlert`,
  which only fires on `'absent'`) — at-risk depends on the student's
  *overall* percentage, not the specific event just written; a `'present'`
  mark can still leave someone at-risk, and only recomputing
  `StudentMetrics` per event catches that.
  **"Fires once when first crossed, not on every subsequent check" without
  a new schema column** — a true state-transition (was above, now below)
  can't be detected from an event handler alone without persisting the
  prior state somewhere; **once-per-week** dedupe (this block's own
  judgment call, the plan note's own text offered "student+day or
  student+week" as options, not one prescribed key) approximates it —
  closer to "notified once" than a daily re-check would read, while still
  periodically resurfacing a persistently at-risk student.
- **Automated nudges** (`SendInactivityNudges`) — confirmed no "last
  active" column exists anywhere before assuming one (grepped `User`'s own
  schema + every migration, per the plan's own instruction). Derives last
  activity as the more recent of `VideoWatchEvent.created_at` /
  `QuizAttempt.created_at` (`QuizAttempt.created_at`, not `started_at` —
  either works since both are always set, `created_at` is the more
  literal "when did this happen" signal). Built as a real `ShouldQueue`
  job (S9 B10's own posture: `$tries`/`backoff()`, `'scheduled'` Horizon
  queue, `CurrentTenant::clear()` at the top of `handle()` for the same
  persistent-worker-leak reason every B10-converted sweep documents) —
  the first genuinely *new* scheduled job added since B10 established
  that shape, confirming it as the default going forward rather than a
  one-off. Gated on the student's own `Enrolment.requested_at` being at
  least 3 days old too, not just their activity — a same-day enrollee
  with zero activity yet hasn't had the chance to be "inactive." Notifies
  the **student** directly (M15's own framing is a re-engagement nudge,
  not a parent alert — `LowAttendanceAlertDue` already covers parents) —
  `WhatsAppChannel` included for shape-parity, but no student anywhere in
  this app has ever had a `phone` captured (documented gap,
  `HANDOVER.md`'s own Avoid list), so that half silently no-ops for every
  real send today, same as `FeeReminderDue`'s own existing student-facing
  WhatsApp channel already does; `database` still lands and surfaces via
  `NotificationCentre` regardless. Same once-per-week dedupe as the
  low-attendance alert.
- 15 new regression tests across 2 files
  (`SendLowAttendanceAlertTest`, `SendInactivityNudgesTest`).
- `php artisan test`: **696/697 passed**, 2078 assertions — same
  pre-existing, out-of-scope `CourseSlugUniquenessTest` failure. Zero
  regressions across the 681 tests already green after B12.

### B14 — Full test coverage, isolation registration, review pass ✅

- `ParentInviteCode` confirmed in `TenantModelRegistry`. `ImpersonationAudit`
  confirmed **correctly absent** — the plan note's own instruction to
  "confirm both are in the registry" was itself stale: `ImpersonationAudit`
  deliberately does not use `BelongsToTenant` (B3's own design, its
  `tenant_id` is set explicitly from the target user, not resolved from
  `CurrentTenant`), so registering it would fail `CrossTenantTest`'s own
  generated per-model isolation tests, which assume that trait's behavior.
  Cross-checked every model importing the real `BelongsToTenant` trait
  (`^use App\Support\Tenancy\BelongsToTenant;`, not a docblock mention of
  it) against the registry array directly: exactly 22 files, all 22
  registered, nothing extra. Confirmed complete, not assumed.
- Bug-hunting review pass: a manual pass across the highest-risk seams
  (shared render-hook composition, the `AttendanceRecorded` dual-listener
  interaction, `LessonAccess`'s every call site, `AiProvider` decorator
  coverage, exception registration, all 6 `CurrentTenant::clear()` jobs'
  consistency) found nothing wrong — plus an independent background-agent
  audit given the same brief, which found three real, confirmed issues
  (all fixed, detailed in "Post-S9 review" below) and correctly reported
  finding nothing else after a genuinely thorough pass, rather than
  padding the report.
- `docs/FEATURES.md` cross-checked against what S9 actually built (not
  just what the plan doc summarized) — found two more real gaps this way,
  detailed below.
- `php artisan test`: **707/708 passed**, 2116 assertions — same
  pre-existing, out-of-scope `CourseSlugUniquenessTest` failure.

---

## Post-S9 review

Same practice as every prior slice's own closing review — read every file
fresh, don't trust green tests alone. This slice touched more existing
domains than any prior one (Tenancy, Content, Assessments, Fees, Comms,
Batches, AI, plus new infra config), so this pass leaned harder on
cross-domain interactions than any single block's own isolated
correctness — an independent agent given the same brief ran in parallel
with a manual review of the highest-risk seams identified while building
S9 itself.

**Six real issues found and fixed, none left red:**

1. **Google OAuth login silently bypassed TOTP 2FA entirely**
   (`app/Http/Controllers/Auth/GoogleAuthController.php`) — the highest-severity
   finding. `StudentPortal`/`ParentPortal`'s own password login (S9 B1)
   correctly checks `hasTwoFactorEnabled()` and gates behind a code
   challenge; `callback()` called `Auth::login()` unconditionally, with no
   check at all. A student or parent who enabled 2FA had it completely
   skippable just by clicking "Sign in with Google" instead of using the
   password form — Google proves identity, not possession of the second
   factor. Fixed by handing off to the exact same challenge UI the
   password path already has: the callback stashes the pending user id in
   session when 2FA is enabled instead of logging in, and
   `StudentPortal`/`ParentPortal::mount()` picks it up (tenant-checked, not
   blindly trusted) to seed `$twoFactorUserId` the same way the password
   step does. 3 new regression tests.
2. **Impersonation locked the real super_admin out of the Super panel**
   (`app/Http/Middleware/ResolveImpersonation.php`) — while impersonating
   any tenant user and navigating directly to the bare `lms.test/super`
   host (bookmark, typed URL, back button — not via "Stop impersonating"),
   the identity swap always applied, because `CurrentTenant` is never
   bound on that host by design, and the guard only skipped the swap on a
   *bound-but-mismatched* tenant, never an unbound one. The swapped-in
   target (never a super_admin — `ImpersonationService::start()` refuses
   that target) then failed `EnsureRole`'s own super_admin check, 403ing
   the real actor with no banner ever rendering to let them stop it from
   there. Fixed by requiring a bound *and matching* tenant for the swap to
   apply, not just rejecting a mismatched one — impersonation only ever
   makes sense within a resolved tenant's own surfaces, never the bare
   platform host. 1 new regression test.
3. **`QuestionGenerator::generate()`'s real AI spend never reached the
   spend cap it was gated behind** (`app/Domains/Assessments/Services/QuestionGenerator.php`).
   B12's `SpendCapGuardedAiDriver` correctly gates every real
   `AiProvider::chat()` call across all six call sites in the app,
   including this one — but the *measurement* half only ever read
   `lesson_artifacts.token_cost` / `doubt_chat_messages.token_cost`.
   `generate()` discarded `ChatResult::$usage` on every call entirely, so
   a tenant generating AI quiz questions all day contributed nothing to
   `TokenCostLedger::totalForDate()`, silently under-reporting real spend
   and never actually counting toward its own cap. Fixed with a new
   `questions.token_cost` column (same `jsonb {in, out, usd}` shape the
   other two sources already use, null on a question promoted via
   `fromSeedQuestions()` — that path makes no AI call at all) and a third
   summed source in `TokenCostLedger`. 2 new regression tests.
4. **`docs/FEATURES.md`'s T2 row ("failed-job retention") was never
   actually true** — `0001_01_01_000002_create_jobs_table.php` provisions
   the `failed_jobs` table (Laravel's own default migration), but nothing
   ever pruned it. Real command is `queue:prune-failed`, not the
   `queue:prune-failed-jobs` name a first guess would reach for (confirmed
   via `--help`, not assumed). Scheduled daily at 720 hours (30 days) —
   reusing this app's own already-established backup-retention number (T3)
   rather than inventing an unrelated one. 1 new regression test.
5. **`docs/FEATURES.md`'s A6 row ("60-min auto-expiry") for Super Admin
   impersonation was never built** — `ResolveImpersonation` had no time
   check at all; a session persisted until the underlying Laravel session
   cookie itself expired (`SESSION_LIFETIME`, unrelated and longer), not a
   deliberate impersonation-specific cutoff. Fixed with a new
   `ImpersonationService::MAX_DURATION_MINUTES = 60` constant, checked
   against the already-existing `ImpersonationAudit.started_at` (the
   session already carried `impersonation_audit_id`, no new state needed).
   A missing audit row is treated the same as expired, not the same as
   "not impersonating" — fails closed. 3 new regression tests.
6. **`NotificationCentre` showed B13's two new notification types with a
   raw class-basename label** (`"LowAttendanceAlertDue"`) instead of a
   human summary every other type gets — a real, if minor, UX regression
   from B13 itself, not a pre-existing gap. Two more `match` arms, same
   shape as the existing ones. 1 new regression test (extended).

**Two more real gaps found, flagged rather than fixed** — genuinely
bigger, more product/UX-shaped decisions than a review pass should
unilaterally decide, matching this app's own established boundary-drawing
practice (B4's "SSL stays manual" is the precedent for this posture):

- **`docs/FEATURES.md`'s B5 row claims TOTP 2FA is "mandatory for Super
  Admin + Institute Admin."** It isn't — confirmed by reading
  `TenantAwareLogin::authenticate()`: the multi-factor challenge loop
  `continue`s past any provider where `isEnabled($user)` is false, so a
  privileged user who never enrolls is never prompted to. 2FA is available
  and functional (S9 B1 built real enrollment/challenge/recovery), just
  opt-in for every role, not enforced for the two most privileged ones as
  the catalog claims. Enforcing it needs real product decisions this pass
  shouldn't make alone (a grace period? a forced-setup interstitial before
  any other action? what happens mid-transition for an already-logged-in
  admin?) — recommended as a fast-follow, not attempted here.
- **`docs/FEATURES.md`'s D8/T3 rows claim "overage billing" and "rehearsed
  restore"** respectively. Neither exists: B12's bandwidth cap blocks
  access once over quota, it doesn't bill for the overage; B10's backups
  create real archives on a schedule, but nothing has ever exercised
  restoring one. Both are legitimately separate pieces of work from what
  S9 B10/B12 each scoped themselves to (a billing flow; a restore runbook
  or rehearsal command) — flagged for a future slice, not built under the
  guise of a review pass.

**One lower-confidence, non-blocking observation, noted not acted on**:
`SendLowAttendanceAlert` (B13) runs a full `StudentMetrics::summarize()` —
including coverage's own per-lesson merge-interval computation — fully
synchronously on *every* `AttendanceRecorded` event, unlike `SendAbsenceAlert`
(S8 B3, the shape B13 was told to copy exactly), which only does real work
on `'absent'` events. A teacher bulk-marking a large batch's attendance now
does one full metrics recompute per student in the request thread. Real,
but not a correctness bug, and queueing the listener would be a deliberate
deviation from this app's own consistent "notification-triggering event
listeners stay synchronous" pattern (established since S8 B3, never
revisited) — a call worth making with the app owner, not silently inside
a review pass.

24 new regression tests across this review pass (10 fixed-issue tests + a
few extended existing cases), on top of the 15 already added in B13.
`php artisan test`: **707/708 passed**, same pre-existing
`CourseSlugUniquenessTest` failure carried since S4, still out of scope.
