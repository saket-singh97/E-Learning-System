# Implementation Plan: S21 Platform Scale & Migration

Full design: `2026-08-28-s21-platform-scale-migration-design.md`. This is
the block-by-block build log — update the PROGRESS table as each block
lands, same as every S1-S20 plan did. 16 blocks (B0-B15), finer-grained
than the 6-item real build scope since T5/V11/V14 each split into
multiple independent blocks, plus a registry sweep and a final review
block.

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-28-s21-platform-scale-migration-design.md and
docs/superpowers/specs/2026-08-28-s21-platform-scale-migration-plan.md, then build
Block 0 of the S21 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | V10 pt.1 — `feature_flags` schema + `FeatureFlag` model | ✅ done |
| B1 | V10 pt.2 — `FeatureGate` service + middleware enforcement on one real gated route | ✅ done |
| B2 | V10 pt.3 — Admin/Super panel flag toggle UI | ✅ done |
| B3 | T5 pt.1 — `api_keys` schema + issuance/revocation Filament UI | ✅ done |
| B4 | V12b — bearer-token auth (Sanctum) on `/api/v1/`'s existing webhook/health endpoints | ✅ done |
| B5 | T5 pt.2 — `webhook_endpoints`/`webhook_deliveries` schema + HMAC-signed dispatch job | ✅ done |
| B6 | T5 pt.3 — 5-retry delivery logic + delivery log admin UI | ✅ done |
| B7 | V13a — Google Workspace SSO: tenant-configurable allowed domain + domain-restricted OAuth gate | ✅ done |
| B8 | V11 pt.1 — Card EMI: `PaymentProvider::createOrder()` EMI option + eligibility | ✅ done |
| B9 | V11 pt.2 — EMI checkout UI option + webhook/payment-status handling | ✅ done |
| B10 | V14 pt.1 — course/category CSV import (dry-run + per-row error report) | ✅ done |
| B11 | V14 pt.2 — enrolment CSV import | ✅ done |
| B12 | V14 pt.3 — payment-history CSV import (historical backfill, no live gateway calls) | ✅ done |
| B13 | V9 — stateless-readiness audit + `ARCHITECTURE.md`/`DECISIONS.md` update | ✅ done |
| B14 | `TenantModelRegistry` sweep — all 7 new `BelongsToTenant` models confirmed registered | ✅ done |
| B15 | Full test coverage, lifecycle E2E, review pass, `PROJECT-STATUS.md`/`HANDOVER.md`/`ROADMAP.md` update | not started |

**Test suite baseline:** 1797+/1798+ passing at slice start per S20's own
final count (1 pre-existing, unrelated `CourseSlugUniquenessTest` failure
carried since S1/S2, out of scope here same as every prior slice).

---

## Block detail (starting checklist — expand into a build narrative as each block actually lands, same as every prior plan file)

### B0 — V10 pt.1: feature flag schema — done 2026-08-28

- [x] `feature_flags` migration (`tenant_id`, `key`, `enabled` default
      `false`, unique `(tenant_id, key)`) + `FeatureFlag` model (Tenancy),
      registered in `TenantModelRegistry`.
- [x] Tests: creates a flag, unique constraint holds per tenant, two
      tenants can independently set the same key differently.

Built: `database/migrations/2026_08_28_090000_create_feature_flags_table.php`,
`app/Domains/Tenancy/Models/FeatureFlag.php` (boolean-cast `enabled`, no
service logic yet — `FeatureGate` arrives in B1). Registered in
`TenantModelRegistry::MODELS` + `seedAttributes()`/`updateAttributes()`.
Own test file `tests/Feature/Tenancy/FeatureFlagTest.php` (4 tests: create,
default-false, unique-per-tenant rejects duplicate, two tenants independent).
Full suite: 1803 tests, 1802 passed, 1 pre-existing unrelated failure
(`CourseSlugUniquenessTest`, carried since S1/S2, out of scope per plan's own
baseline note).

### B1 — V10 pt.2: FeatureGate + first real gate — done 2026-08-28

- [x] `FeatureGate::enabled(Tenant $tenant, string $key): bool` — the one
      place a flag is ever checked (design doc §4 decision 2), defaults
      `false` for an unset key.
- [x] Wire it into one real existing route as the proof case — confirm
      the actual call site before this block starts (doubt chat, per
      V10's own FEATURES.md example), not guessed here.
- [x] Tests: gate blocks when unset, blocks when explicitly `false`,
      allows when `true`, a different tenant's flag never leaks across.

Built: `app/Domains/Tenancy/Services/FeatureGate.php` — bypasses the
`BelongsToTenant` global scope on purpose (explicit `tenant_id` param lets a
Super-panel-style caller check a tenant other than the currently-bound one,
needed by B2). Call site: `App\Domains\AI\Livewire\DoubtChat` (embedded via
`<livewire:ai.doubt-chat>` in `content/watch.blade.php`) — gated in
`mount()`/`ask()` directly, **not** via HTTP route middleware. Reason:
CLAUDE.md's own documented gotcha — Livewire actions post to
`/livewire/update` with no relation to the page route, so a route-level
middleware would leave `ask()` unprotected; the component itself is the only
call site that can actually enforce this, same reasoning `LessonAccess`
already established for a different question. View shows "Doubt chat isn't
available on this plan." when gated off instead of the form.
Tests: `tests/Feature/Tenancy/FeatureGateTest.php` (4: unset→false,
false→false, true→true, cross-tenant isolation) +
`tests/Feature/AI/DoubtChatTest.php` (2 new: blocks unset, blocks explicit
false; 2 existing tests updated to seed the flag `true`, since the feature
is opt-in-by-default now).
Full suite: 1809 tests, 1806 passed. 3 failures: 1 pre-existing
(`CourseSlugUniquenessTest`, carried since S1/S2, out of scope per this
plan's own baseline). 2 more
(`DownloadCompletionExportControllerTest`/`DownloadEnrolmentExportControllerTest`,
Comms domain, untouched by this block's diff) — both pass cleanly in
isolation, confirming a pre-existing test-order flake, not a regression from
this block.

### B2 — V10 pt.3: toggle UI — done 2026-08-28

- [x] Admin-panel view (read-only: which flags this tenant has) + Super-
      panel resource (grant/revoke per tenant per key) — same "Super
      grants, tenant sees" shape `plan_id`/`TenantPlanLimits` already
      established for a similar platform-controlled-per-tenant value.
- [x] Tests: institute admin cannot self-grant a flag, Super admin can
      toggle it, list is scoped correctly cross-tenant.

Built: single `FeatureFlagResource` (`app/Domains/Tenancy/Filament/Resources/FeatureFlags/`),
discovered by both Admin and Super panels (same directory both panel
providers already scan) — `canCreate()` gates the Create page to the Super
panel only; List is shared, with `ToggleColumn::disabled()` outside Super
and a `tenant.subdomain` column visible only on Super (Admin's own tenant is
implicit via `BelongsToTenant`'s global scope — no CurrentTenant bound on
Super, so it naturally sees every tenant instead, no `withoutGlobalScope`
needed). `CreateFeatureFlag::handleRecordCreation()` uses
`FeatureFlag::withoutEvents(fn () => FeatureFlag::create($data))` — same
"withoutEvents + explicit tenant_id" idiom `PlanChangeService::subscribe()`
already established for creating a `BelongsToTenant` row from the Super
panel's unscoped position (its `creating()` hook would otherwise overwrite
`tenant_id` from an unbound `CurrentTenant` and throw). Added
`FeatureFlag::tenant(): BelongsTo` for the list column.
Tests: `tests/Feature/Tenancy/FeatureFlagToggleUiTest.php` (4: institute
admin 403s on the create route, super admin creates for a chosen tenant,
super admin toggles an existing flag off, list scoped correctly — admin
sees only its own tenant's row, super sees every tenant's).
Full suite: 1813 tests, 1809 passed. 4 failures, all pre-existing and
unrelated to this block's diff (Tenancy/Filament only) — 1 baseline
(`CourseSlugUniquenessTest`, carried since S1/S2) + 3 test-order flakes
(`DownloadCompletionExportControllerTest`, `DownloadEnrolmentExportControllerTest`,
`BackupConfigurationTest`) that all pass cleanly in isolation.

### B3 — T5 pt.1: API key issuance — done 2026-08-28

- [x] `api_keys` migration + model (Api, new domain), registered in
      `TenantModelRegistry`.
- [x] Admin-panel issuance action showing the real key exactly once,
      storing only `key_hash` (design doc §4 decision 4); revoke action
      sets `revoked_at`.
- [x] Tests: key shown once, hash verifies correctly, revoked key fails
      verification, a different tenant's key never verifies against this
      tenant's own records.

Built: new `Api` domain — `app/Domains/Api/Models/ApiKey.php`,
`Services/ApiKeyService.php` (`issue()`/`verify()`/`revoke()`,
`hash('sha256', $key)`), `Filament/Resources/ApiKeys/` (Admin-panel only via
`canAccess()`; discovery line added to `AdminPanelProvider`). No Edit page —
issuance is a header action on the List page (shows the plain key once in a
persistent notification, never persisted), revoke is a row action setting
`revoked_at`; same "mutate via page actions, not a form" shape
`ListInstructorInvitations`/`EditTenant` already established.
`ApiKeyService::verify()` bypasses the tenant scope on purpose — an inbound
key arrives with no tenant context bound yet, same unscoped-lookup shape
`FeatureGate` uses. Registered in `TenantModelRegistry`.
Tests: `tests/Feature/Api/ApiKeyServiceTest.php` (5: plain-key-once,
verify+last_used_at, garbage key refused, revoked key refused, cross-tenant
never resolves) + `tests/Feature/Api/ApiKeyIssuanceUiTest.php` (2: issue via
header action, revoke via row action).
Full suite: 1821 tests, 1820 passed — only the 1 pre-existing baseline
failure (`CourseSlugUniquenessTest`, carried since S1/S2). No flakes this
run.

### B4 — V12b: bearer-token auth on `/api/v1/` — done 2026-08-28

- [x] Middleware verifying a bearer token against `api_keys.key_hash`,
      binding the resolved tenant the same way `ResolveTenant` does for
      the subdomain path — confirm exact `/api/v1/` route list before
      this block starts (design doc §4 decision 5: additive to existing
      routes, not a new surface).
- [x] Tests: valid key passes, missing/invalid/revoked key is refused,
      resolved tenant matches the key's own owner, cross-tenant key never
      grants access to another tenant's endpoint.

**Route-list verification (done before writing code, per the checklist
above):** grepped the whole tree for a literal `api/v1` or `prefix('v1'`
match — zero hits anywhere. `routes/api.php` (mounted at Laravel's default
`/api` prefix, `bootstrap/app.php`) held exactly 5 routes: `/user`
(Sanctum, app end-users, unrelated) and 4 vendor webhook receivers
(Razorpay ×2, Zoom, Device) — each already authenticated by its own
vendor-specific signature scheme. None of the 4 is a route an institute's
*own* system calls with *its own* key — retrofitting bearer auth onto a
vendor webhook would break it outright (the vendor has no way to supply an
institute's bearer token). So there was no existing "institute calls this
with its key" route to attach middleware to; R4/T1's own "webhook receivers
and health endpoints" wording named a health endpoint as in-scope for P1,
and none existed. Added exactly one — `GET /api/v1/health` — the smallest
possible real call site for the middleware, not a parallel REST surface
(the 4 vendor webhooks are untouched).

Built: `App\Http\Middleware\EnsureApiKey` (alias `api.key`) — reads
`Request::bearerToken()`, calls `ApiKeyService::verify()` (B3), binds
`CurrentTenant` to the resolved tenant on success, throws
`InvalidApiKeyException` (401, registered in `bootstrap/app.php`) on
missing/invalid/revoked. `GET /api/v1/health` in `routes/api.php`, gated by
`api.key`, returns `{ok, tenant}` — confirms both a valid key and which
tenant it resolved to in one response.
Tests: `tests/Feature/Api/EnsureApiKeyTest.php` (5: valid key passes,
missing token refused, invalid token refused, revoked key refused,
cross-tenant key resolves only its own tenant, never the other).
Full suite: 1826 tests, 1824 passed. 2 failures, both pre-existing and
unrelated to this block's diff (`Http/Middleware`, `Exceptions`,
`routes/api.php`, `bootstrap/app.php` only) — 1 baseline
(`CourseSlugUniquenessTest`) + 1 already-confirmed test-order flake
(`BackupConfigurationTest`, verified passing in isolation during B2).

### B5 — T5 pt.2: webhook schema + dispatch — done 2026-08-28

- [x] `webhook_endpoints`/`webhook_deliveries` migrations + models (Api),
      registered in `TenantModelRegistry`.
- [x] HMAC-signed dispatch job (design doc §4 decision 3: queued,
      at-least-once, never synchronous) — confirm which existing domain
      event(s) trigger a first real webhook before this block starts.
- [x] Tests: payload signature verifies with the endpoint's own secret, a
      disabled endpoint receives nothing, delivery creates a real
      `webhook_deliveries` row.

**Trigger event chosen (verified before coding):** `Fees\Events\PaymentCaptured`
— already fired on every captured payment, already listened to twice
(`FinalizeEnrolmentOnPaymentCaptured`, `EnrolFromProgramPayment`), and "a
payment was captured" is the clearest real "an institute's own external
system wants to know" moment already in the app. Added a third listener,
`Api\Listeners\DispatchPaymentCapturedWebhook`, same one-way-ignorance shape
as the other two.
Built: `WebhookDispatcher::dispatch(Tenant, eventType, payload)` — the one
chokepoint that turns a domain event into `webhook_deliveries` rows; only
`active` endpoints get a row at all (a disabled endpoint receives nothing,
not a row that's skipped later). `Api\Jobs\DeliverWebhook` (`ShouldQueue`,
sync-tested via `QUEUE_CONNECTION=sync`) — HMAC-signs the JSON body
(`hash_hmac('sha256', $body, $endpoint->secret)`) into an
`X-Webhook-Signature` header, POSTs, records `delivered`/`failed` +
`attempt_count`/`last_attempted_at`/`delivered_at`. No retry/backoff yet —
that's explicitly B6's own addition, not this block's; a failed attempt is
recorded and left alone, not rethrown into `failed_jobs`.
Tests: `tests/Feature/Api/WebhookDeliveryTest.php` (5: HMAC signature
verifies against the endpoint's own secret, disabled endpoint gets nothing
sent and no row, a row is created per active endpoint, a non-2xx response
marks `failed` without retrying, `PaymentCaptured::dispatch()` end-to-end
produces a delivered webhook).
Full suite: 1833 tests, 1831 passed. 2 failures, both pre-existing and
unrelated to this block's diff (`app/Domains/Api/*`, `AppServiceProvider`,
`TenantModelRegistry`, 2 migrations only) — the 1 baseline
(`CourseSlugUniquenessTest`) + the same already-confirmed test-order flake
(`BackupConfigurationTest`) seen in B2/B4.

### B6 — T5 pt.3: retry + delivery log — done 2026-08-28

- [x] 5-retry backoff on delivery failure (design doc §4 decision 3),
      `attempt_count`/`status` updated per attempt; delivery log
      admin-panel view.
- [x] Tests: a failing endpoint retries up to 5 times then marks
      `failed`, a recovering endpoint on a later attempt marks
      `delivered`, the log shows accurate attempt history.

Built: `DeliverWebhook` gained `$tries = 5` + `backoff(): [60, 300, 900,
3600]` (same shape every other queued sweep in this app already uses) —
the catch block now rethrows after recording `failed`/`attempt_count`, which
is what lets Laravel's own queue worker re-run `handle()` after each
backoff delay; the job itself doesn't loop or sleep. Also noticed no UI
existed yet anywhere to actually *register* a webhook endpoint (B5 only
built the schema+dispatch side) — added `WebhookEndpointResource`
(Admin-panel only, `app/Domains/Api/Filament/Resources/WebhookEndpoints/`,
already discovered via B3's `AdminPanelProvider` line): a header action
registers an endpoint and shows its signing secret once (same idiom
`ListApiKeys` used), row actions disable/enable, and a read-only
`DeliveriesRelationManager` on the Edit page is the "delivery log" the
checklist names — no create/edit/delete on it, rows are system-generated
only.
**Real bug caught while writing the "recovering endpoint" test:** `Http::fake()`
called twice for the same URL pattern in one test does *not* override the
first stub — `Factory::stubUrl()` merges onto the same `stubCallbacks`
collection and `PendingRequest::buildStubHandler()` takes the *first*
matching stub, not the last (confirmed by reading Laravel's own source).
The test looked correct but silently kept hitting the first (500) stub.
Fixed with a single `Http::fake([...=>Http::sequence()->push(...)->push(...)])`
registration instead of two separate `Http::fake()` calls — a genuine
Laravel testing gotcha worth remembering for any future test that needs a
mocked HTTP response to change mid-test.
Tests: `tests/Feature/Api/WebhookRetryTest.php` (4: 5 attempts against a
permanently-down endpoint stays `failed`, a recovering endpoint marks
`delivered` on the attempt that succeeds, attempt history accumulates
correctly across retries, an already-`delivered` row is a no-op) +
`tests/Feature/Api/WebhookEndpointUiTest.php` (3: register shows the secret
once, disable/enable row actions, delivery log visible on the Edit page) +
1 existing B5 test in `WebhookDeliveryTest.php` updated for the new
rethrow-on-failure behavior.
Full suite: 1840 tests, 1837 passed. 3 failures, all pre-existing and
unrelated to this block's diff (`Api/Jobs/DeliverWebhook.php`,
`Api/Filament/Resources/WebhookEndpoints/`, 3 test files only) — the 1
baseline (`CourseSlugUniquenessTest`) + 2 already-confirmed test-order
flakes (`BackupConfigurationTest`, `DownloadEnrolmentExportControllerTest`)
seen in earlier blocks.

### B7 — V13a: Google Workspace SSO — done 2026-08-28

- [x] `tenants.google_workspace_domain` migration (nullable, off by
      default).
- [x] `GoogleOAuthAuthenticator` gains a domain check: when configured,
      only a Google account on that domain may authenticate for this
      tenant (design doc §2.3: no such check exists today).
- [x] Tests: configured domain allows a matching account, refuses a
      non-matching one, unconfigured tenant behaves byte-identical to
      today.

Built: `tenants.google_workspace_domain` (nullable, added to `$fillable`).
`GoogleOAuthAuthenticator::authenticate()` checks it first, before any
`google_id`/email lookup — a mismatched domain can never reach (and thus
never silently link onto) an existing account. Comparison is
`strcasecmp($emailDomain, $tenant->google_workspace_domain)` (exact,
case-insensitive — domains aren't case-sensitive; `Str::is()` would have
been wildcard-glob matching, the wrong tool for an exact check). Mismatch
throws `GoogleWorkspaceDomainMismatchException` (403, registered in
`bootstrap/app.php`). Signature changed from `authenticate($user, int
$tenantId)` to `authenticate($user, Tenant $tenant)` — `GoogleAuthController`
already had the resolved `Tenant` in hand right before this call, so this
avoids a second lookup inside the authenticator rather than adding one.
Unconfigured tenant (`google_workspace_domain === null`) skips the check
entirely — proven byte-identical by the fact all 11 pre-existing
`GoogleOAuthLoginTest` cases (none of which set the column) still pass
unchanged.
Tests: 2 new in `tests/Feature/Tenancy/GoogleOAuthLoginTest.php` — matching
domain signs in, non-matching domain gets 403 and creates no user row.
Full suite: 1842 tests, 1840 passed. 2 failures, both pre-existing and
unrelated to this block's diff (`Tenant` model, `GoogleOAuthAuthenticator`,
`GoogleAuthController`, 1 exception, 1 migration only) — the 1 baseline
(`CourseSlugUniquenessTest`) + 1 already-confirmed test-order flake
(`DownloadEnrolmentExportControllerTest`).

### B8 — V11 pt.1: Card EMI eligibility + order creation — done 2026-08-28

- [x] `fee_payments.emi_duration_months` migration (nullable).
- [x] `PaymentProvider::createOrder()` gains an optional EMI parameter
      (design doc §4 decision 6, not a new interface method) — confirm
      Razorpay's own EMI order-option shape before this block starts.
- [x] Tests: a non-EMI order is byte-identical to today, an EMI order
      carries the duration through to the created `FeePayment`.

Built: `fee_payments.emi_duration_months` (nullable, `$fillable`).
`PaymentProvider::createOrder()` signature gains `?int $emiDurationMonths =
null` (all 3 implementations — `RazorpayProvider`,
`CircuitBreakerGuardedPaymentDriver`, `FakeRazorpayDriver` — updated to
match). `RazorpayProvider` adds `'method' => 'emi', 'emi_duration' =>
$months` to the order payload only when set — omitted entirely for null,
so a non-EMI request body is byte-identical to before this block.
`FeeInvoiceService::createPaymentOrder()` gains the same optional param,
threaded straight to `createOrder()` and stamped onto the created
`FeePayment` row. No eligibility threshold was added — the plan's own
checklist names only the parameter + schema + tests, nothing about a
minimum order value, and the design doc's own decision 6 doesn't mention
one either; "eligibility" here is simply "can this call request EMI at
all," which the optional parameter itself is.
**Real PHP gotcha caught by the test run:** an interface can't have an
implementing class silently drop a trailing optional parameter — PHP's own
interface-compliance check is stricter than assumed; `Declaration ... must
be compatible` fatal errored on the codebase's one anonymous
`PaymentProvider` test double
(`tests/Feature/Support/CircuitBreakerGuardedPaymentDriverTest.php`) until
its `createOrder()` signature was updated to match.
Tests: `RazorpayProviderTest` (2: non-EMI request sends no
`method`/`emi_duration` keys at all, an EMI request sends Razorpay's own
`method: emi` + `emi_duration`) + `FeeInvoiceServiceTest` (2: non-EMI
leaves the column null, EMI carries the duration through to the row).
Full suite: 1845 tests, 1842 passed. 2 failures (1 baseline
`CourseSlugUniquenessTest`, 1 already-confirmed flake
`BackupConfigurationTest`) + 1 DB-connection-timeout error
(`SubscriptionInvoiceResourceTest`, an untouched Billing-domain test) during
an unusually long (~3h) run — re-ran that one test in isolation
immediately after and it passed clean, confirming a transient
infra/connection-pool hiccup during the long run, not a regression from
this block's diff (`Fees`/`Integrations` files + 1 migration only).

### B9 — V11 pt.2: EMI checkout + webhook handling — done 2026-08-29

- [x] Checkout UI option to select an EMI duration (reuses the existing
      checkout flow's own structure — confirm exact component before
      this block starts).
- [x] `PaymentReconciler` correctly captures an EMI payment the same as
      any other (confirm no EMI-specific webhook event exists in
      Razorpay's own docs before assuming one does).
- [x] Tests: EMI selection flows through to a real order, capture works
      identically to a non-EMI payment.

**Component confirmed before starting:** `app/Livewire/FeeCheckout.php`
(`startCheckout()`) — the one existing call site for
`FeeInvoiceService::createPaymentOrder()` from the student-facing checkout
flow (B1's own component, embedded in the batch-catalog row). Added
`public ?int $emiDurationMonths = null` (null = full payment, unchanged
default) and a `<select>` in `fee-checkout.blade.php` (3/6/9/12-month
options) alongside the existing "Pay now" button, threaded straight into
`createPaymentOrder($invoice, $this->emiDurationMonths)`.
**`PaymentReconciler` needed zero changes** — confirmed before touching it:
design doc §4 decision 6 already establishes EMI as an order-*creation*
option only, not a separate payment type or webhook event; Razorpay still
fires the same `payment.captured`/`payment.failed` events regardless, and
`capture()`/`fail()` key off `provider_order_id` alone, which doesn't care
whether that order was EMI or not.
Tests: 2 new in `FeeCheckoutTest.php` (EMI duration carries through to the
`FeePayment` row, plain checkout leaves it null) + 1 new in
`PaymentReconcilerTest.php` (an EMI payment captures identically to a
non-EMI one — same event, same code path).
**Docker Postgres hiccup mid-block:** first full-suite attempt errored with
`connection refused` (container had genuinely restarted, confirmed via
`docker ps` showing 27s uptime) — an infra blip, not a code issue; re-ran
clean once the container was back.
Full suite: 1848 tests, 1845 passed. 3 failures, all pre-existing and
unrelated to this block's diff (`FeeCheckout.php` + its view + 2 test files
only) — the 1 baseline (`CourseSlugUniquenessTest`) + 2 already-confirmed
test-order flakes (`DownloadCompletionExportControllerTest`,
`DownloadEnrolmentExportControllerTest`).

### B10 — V14 pt.1: course/category import — done 2026-08-29

- [x] `course_import_batches` migration + model (Content), registered in
      `TenantModelRegistry`.
- [x] CSV import job reusing `StudentImportBatch`'s own dry-run/
      per-row-error-report shape (design doc §4 decision 7), targeting
      `Course`/`Category`.
- [x] Tests: dry-run reports row-level errors with zero writes, a real
      run creates courses/categories and skips only the bad rows.

**One real difference from `StudentImportBatch`, confirmed before
building:** its own two phases (sync validate, then a *queued* create job)
exist because creating a User goes through `RoleAssignmentPolicy` — real
weight. Creating a Course (and optionally a Category) is a couple of plain
Eloquent inserts, no external I/O, no policy checks — genuinely cheap at
the same up-to-5000-row scale, so B10 runs fully synchronously in one
`CourseCsvImporter::import()` call, no queued job class at all. `dry_run`
is a real toggle here (a schema column `StudentImportBatch` never had) —
checked before any Course/Category write happens, matching the plan's own
explicit "dry-run reports errors with zero writes" requirement.
Built: `course_import_batches` (Content), `CourseImportBatch` model,
`CourseCsvImporter` service (CSV columns: `title, slug, category` —
category optional, uncategorized rows skip the pivot entirely). Real run
creates a `Course` per valid row and `firstOrCreate`s a `Category` by slug
when given; dry run touches neither table, only reads (`Course::where(...)
->exists()` for slug-collision checks). Admin-panel-only
`CourseImportBatchResource` (explicit registration in
`AdminPanelProvider`, same as `CategoryResource`/`ProgramResource` —
Content's directory isn't auto-discovered) with a dry-run checkbox on
upload and a `DownloadCourseImportErrorReportController` (same
role+tenant double-check shape as `DownloadStudentImportErrorReportController`).
Tests: `tests/Feature/Content/CourseCsvImporterTest.php` (6: empty file,
bad headers, dry-run zero-writes with 3 error kinds, real run
creates-and-skips, blank category leaves no pivot row, no report written
when every row is valid) + `tests/Feature/Content/CourseImportBatchUiTest.php`
(3: real-run upload, dry-run upload, cross-tenant download 403 — same
shape the Student import's own cross-tenant test already established).
Full suite: 1858 tests, 1855 passed. 3 failures, all pre-existing and
unrelated to this block's diff (`Content` domain + 1 exception + 1
controller + 1 migration + provider registration only) — the 1 baseline
(`CourseSlugUniquenessTest`) + 2 already-confirmed test-order flakes
(`DownloadCompletionExportControllerTest`, `DownloadEnrolmentExportControllerTest`).

### B11 — V14 pt.2: enrolment import — done 2026-08-29

- [x] `enrolment_import_batches` migration + model (Batches), registered
      in `TenantModelRegistry`.
- [x] CSV import job targeting `Enrolment`, same dry-run/error-report
      shape, validating against already-imported (or pre-existing)
      batches/students.
- [x] Tests: dry-run catches a reference to a non-existent batch/student,
      real run creates valid enrolments only.

Same "sync, no queue" call as B10 (design/reasoning identical — a lookup
plus one insert via `BatchEnrolmentService::addManually()`, no external
I/O). CSV columns: `email, batch` — both must reference a **pre-existing**
student/batch (this is enrolment import, not student import — F9/S15
already owns creating new student rows); a row whose email or batch slug
doesn't already exist is a per-row error, never a new record, per the
design's own "validating against already-imported (or pre-existing)
batches/students" wording.
Built: `enrolment_import_batches` (Batches), `EnrolmentImportBatch` model,
`EnrolmentCsvImporter` service. Real run calls
`BatchEnrolmentService::addManually()` — already idempotent (an existing
enrolment for the same batch+user is returned as-is, never duplicated or
errored), reused rather than re-implemented. `EnrolmentImportBatchResource`
lives under `Batches/Filament/Resources`, which `AdminPanelProvider`
already auto-discovers — no explicit registration needed the way B10's
Content-domain resource required.
Tests: `tests/Feature/Batches/EnrolmentCsvImporterTest.php` (6: empty
file, bad headers, dry-run zero-writes catching both a missing student and
a missing batch, real run creates-and-skips, in-file duplicate email+batch
pair, an already-enrolled pair reuses the row without erroring) +
`tests/Feature/Batches/EnrolmentImportBatchUiTest.php` (3: real-run
upload, dry-run upload, cross-tenant download 403).
Full suite: 1868 tests, 1865 passed. 3 failures, all pre-existing and
unrelated to this block's diff (`Batches` domain + 1 exception + 1
controller + 1 migration + 1 route file only) — the 1 baseline
(`CourseSlugUniquenessTest`) + 2 already-confirmed test-order flakes
(`DownloadCompletionExportControllerTest`, `BackupConfigurationTest`).

### B12 — V14 pt.3: payment-history import — done 2026-08-29

- [x] `payment_history_import_batches` migration + model (Fees),
      registered in `TenantModelRegistry`.
- [x] CSV import job creating historical `FeeInvoice`/`FeePayment` rows
      directly (no live gateway call — this is backfilling settled
      history, not processing new payments).
- [x] Tests: dry-run reports errors with zero writes, real run creates
      invoice/payment pairs correctly, never dispatches a live payment
      event for imported (already-settled) history.

CSV columns: `email, batch, invoice_number, amount, gst_amount,
total_amount, paid_at` — the row's student+batch must already have a
real `Enrolment` on record (checked after the B11-style student/batch
lookups), since `fee_invoices.batch_id`/`enrolment_id` are both `NOT NULL`
foreign keys — a payment history row with no matching enrolment is a
per-row error ("import the enrolment first"), never silently skipped or
auto-created.
Built: `PaymentHistoryCsvImporter` — bypasses
`FeeInvoiceService`/`PaymentReconciler` entirely, writing `FeeInvoice`
(`status: paid`, `paid_at` from the CSV) and `FeePayment`
(`provider: imported`, `status: captured`, synthetic unique
`provider_order_id`/`idempotency_key`) directly inside one transaction —
no live gateway call is possible even in principle, and no
`PaymentCaptured` event fires (confirmed with `Event::fake()` +
`assertNotDispatched()`), since nothing here ever touches the reconciler.
`sequence_number` computed once per import
(`FeeInvoice::max('sequence_number') + 1`, incrementing per row) under a
tenant-row lock, same reasoning `FeeInvoiceService::nextSequenceNumber()`
already established — `invoice_number` itself comes straight from the CSV
(the institute's own historical number), not the app's own
date-based `INV-{tenant}-{FY}-{seq}` builder, since that scheme means
"issued today," which a backfilled historical invoice never is.
`PaymentHistoryImportBatchResource` lives under `Fees/Filament/Resources`,
already auto-discovered — no explicit registration needed.
Tests: `tests/Feature/Fees/PaymentHistoryCsvImporterTest.php` (6: empty
file, bad headers, dry-run zero-writes catching missing student/batch/bad
data, a student+batch pair with no enrolment on record, real run creates
matching paid/captured pairs and confirms zero `PaymentCaptured`
dispatches, no report when every row is valid) +
`tests/Feature/Fees/PaymentHistoryImportBatchUiTest.php` (3: real-run
upload, dry-run upload, cross-tenant download 403).
Full suite: 1878 tests, 1875 passed. 3 failures, all pre-existing and
unrelated to this block's diff (`Fees` domain + 1 exception + 1 controller
+ 1 migration + 1 route file only) — the 1 baseline
(`CourseSlugUniquenessTest`) + 2 already-confirmed test-order flakes
(`DownloadCompletionExportControllerTest`, `BackupConfigurationTest`).

### B13 — V9: stateless-readiness audit — done 2026-08-29

- [x] Grep every `app/Domains/**` and `app/Http/**` for a local
      filesystem write outside `Storage::disk(...)` (design doc §4
      decision 8) — anchored grep, not a bare substring match, same
      discipline the registry sweep already uses.
- [x] Fix any real hit found; if none, document that explicitly (don't
      invent a fix for a problem that doesn't exist).
- [x] Update `ARCHITECTURE.md`/`DECISIONS.md` with the "nothing lives on
      local disk" constraint as a stated, load-bearing rule for anyone
      adding a future feature.

**Audit result: clean, zero violations, no code changed.** Grepped for
`file_put_contents`, `fopen(` against a real path, `mkdir`, `copy`/
`rename`/`unlink`, and a direct `Illuminate\Filesystem\Filesystem` or
`League\Flysystem` use across `app/Domains/**` and `app/Http/**`. Every
`fopen(` hit (11 files — every CSV export/import job in the app, including
S21's own B10/B11/B12 importers) is `fopen('php://temp', 'r+')`, an
in-memory stream, never a real file. Cross-checked with `Storage::disk(`
usage: 97 call sites across 53 files — every export, import, PDF,
certificate, and branding operation in the app already routes through the
facade. No raw filesystem write bypassing it anywhere.
Documented in `DECISIONS.md` (2026-08-29 entry, full audit method +
result) and a new "Stateless readiness (V9)" section in `ARCHITECTURE.md`
stating the constraint as load-bearing for future features: no request
path may write to local disk outside `Storage::disk(...)`, since a second
app server behind a load balancer can't see a file another server wrote
straight to its own filesystem.
No tests added — nothing here has behavior to test, only a documented
constraint and a verified-clean state. Full suite run per this block's own
"run composer test before marking complete": 1878 tests, 1875 passed (same
count as B12 — no code touched). 3 failures, all pre-existing and
unrelated (this block's diff is `ARCHITECTURE.md`/`DECISIONS.md` only) —
the 1 baseline (`CourseSlugUniquenessTest`) + 2 already-confirmed
test-order flakes (`DownloadCompletionExportControllerTest`,
`BackupConfigurationTest`).

### B14 — `TenantModelRegistry` sweep — done 2026-08-29

- [x] Reverse sweep (same anchored-grep method S19 B15/S20 B13 used):
      grep every `app/Domains/*/Models/*.php` for a real
      `use BelongsToTenant` declaration, diff against
      `TenantModelRegistry::MODELS`, confirm zero gaps either direction.
      All 7 new S21 models (`FeatureFlag`, `ApiKey`, `WebhookEndpoint`,
      `WebhookDelivery`, `CourseImportBatch`, `EnrolmentImportBatch`,
      `PaymentHistoryImportBatch`) confirmed present with working
      `seedAttributes()`/`updateAttributes()` entries.

**Result: zero gaps either direction, no code change needed.** Anchored
grep for the real trait-use line (`use (BelongsToTenant|..., BelongsToTenant|BelongsToTenant, ...);`
inside the class body, not just the import) across every
`app/Domains/*/Models/*.php` found exactly **98** models; extracting
`TenantModelRegistry::MODELS`'s own class list found exactly **98**;
`diff` between the two sorted lists produced no output — every model
using the trait is registered, and nothing stale is registered that no
longer uses it. All 7 new S21 models individually confirmed present with
both a `seedAttributes()` and an `updateAttributes()` entry (grepped each
by name). Ran `tests/Isolation/CrossTenantTest.php` directly — the
generated suite itself passed clean (99 tests), proving the registry
isn't just textually complete but functionally exercised.
No code changed — every S21 block from B0 onward already registered its
own new model at build time (per `CLAUDE.md`'s own tenant model registry
rule), so this block found nothing left to fix, same "confirm, don't
invent a fix" posture as B13.
Full suite: 1878 tests, 1875 passed — identical count to B12/B13 (no code
touched this block). 3 failures, all pre-existing and already confirmed
unrelated across multiple prior blocks — the 1 baseline
(`CourseSlugUniquenessTest`) + 2 already-confirmed test-order flakes
(`DownloadCompletionExportControllerTest`, `BackupConfigurationTest`).

### B15 — Full test coverage, review pass

- [ ] Lifecycle E2E (design doc §5): API key mint → webhook endpoint
      registered → event fires → HMAC-signed delivery; feature flag
      enabled for one tenant, gated route reachable there and blocked for
      an unflagged tenant; course CSV dry-run reports errors with zero
      writes, then a real run imports correctly.
- [ ] Dispatch a review pass over the whole slice's diff, same discipline
      every prior slice's final block established.
- [ ] Update `HANDOVER.md` (S21 complete entry), `docs/PROJECT-STATUS.md`
      (new S21 section), `docs/ROADMAP.md` (S21 row → Done, shipped-count
      20→21).
