# Implementation Plan: S11 Plan Tiers + Institute Billing

Full design: `2026-08-16-s11-plan-billing-design.md`. This is the
block-by-block build log — update the PROGRESS table as each block lands,
same as every S1-S10 plan did. 17 blocks (B0-B16), same granularity as
S10's 16.

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-16-s11-plan-billing-design.md and
docs/superpowers/specs/2026-08-16-s11-plan-billing-plan.md, then build
Block 0 of the S11 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | Domain groundwork — `Billing` scaffold, `plans`/`tenant_subscriptions`/`subscription_invoices`/`tenant_storage_usage` migrations + models, `tenants.plan_id`, tenant registry, `PlanSeeder` (free/starter/growth/enterprise) | ✅ done |
| B1 | Plan catalog admin (Q1 pt.1) — Super panel `PlanResource` CRUD | ✅ done |
| B2 | `TenantPlanLimits` resolver; `BandwidthQuota`/`AiSpendCap` migrated onto it (plan_id-aware, legacy fallback preserved) | ✅ done |
| B3 | `SeatQuota` + enforcement at staff registration (Q2 pt.1) | ✅ done |
| B4 | `TenantStorageUsage` + `StorageQuota`, wired into every file-store/delete call site (Q1/Q2 pt.2 — storage) | ✅ done |
| B5 | `SubscriptionProvider` interface + `RazorpaySubscriptionProvider` + `FakeRazorpaySubscriptionDriver` (Q3 pt.1) | ✅ done |
| B6 | `TenantSubscription` creation — `TenantResource` "Plan" section, `PlanChangeService::subscribe()` (Q3 pt.2) | ✅ done |
| B7 | Webhook — `RazorpaySubscriptionWebhookController` + `SubscriptionReconciler`, `SubscriptionInvoice` writes (Q3 pt.3) | ✅ done |
| B8 | Grace-period sweep — `SuspendOverdueTenantSubscriptions` job, `grace_ends_at` logic, scheduled daily (Q4) | ⬜ not started |
| B9 | Suspension enforcement — confirm/wire `Tenant::STATUS_SUSPENDED` gate for a billing-suspended tenant, grace-window banner in Admin panel | ⬜ not started |
| B10 | Plan upgrade/downgrade + proration — `PlanChangeService::changePlan()`, `proration`-kind invoice row (Q5) | ⬜ not started |
| B11 | Institute-facing billing view — Admin panel `BillingOverview` page: plan, usage bars, subscription status, invoice history | ⬜ not started |
| B12 | Super-panel cross-tenant billing visibility — subscription list/filter, `withoutGlobalScope` applied up front | ⬜ not started |
| B13 | Cancellation flow — `PlanChangeService::cancel()`, `SubscriptionProvider::cancelSubscription()`, reverts to `plan_id = null` fallback | ⬜ not started |
| B14 | Grace/suspension notifications — WhatsApp/email reminder on grace-entry and T-3-before-suspension, reuses Comms domain driver (mirrors Fees P7) | ⬜ not started |
| B15 | Edge cases + concurrency — webhook double-delivery idempotency, soft-deleted-tenant handling in the sweep, race guards | ⬜ not started |
| B16 | Full test coverage, isolation registration sweep, review pass, `PROJECT-STATUS.md`/`HANDOVER.md` update | ⬜ not started |

**Test suite:** 804/805 at slice start (1 pre-existing, unrelated
`CourseSlugUniquenessTest` failure carried since S1/S2, out of scope here
same as every prior slice). Design doc §3.5 estimates ~55-65 new tests by
B16 (~860-870 total) — treat as a rough anchor, not a target; S10's own
estimate undershot its actual by a wide margin.

---

## Block notes (fill in as each block builds — deviations, gotchas,
## decisions made mid-build)

### B0 — Domain groundwork — DONE (2026-08-17)

- Scaffolded only `app/Domains/Billing/Models/` — deviation from this
  block's own bullet list above, which named `Services/Filament/Jobs` too.
  Followed S10 B0's actual precedent instead (only `Models/` created at
  domain-groundwork time; other subfolders appear when the block that
  first needs them lands), not this note's own more literal wording.
- Migrations, in order: `create_plans_table` (central, no `tenant_id`,
  `code` unique), `add_plan_id_to_tenants_table` (nullable FK,
  `nullOnDelete()`), `create_tenant_subscriptions_table`,
  `create_subscription_invoices_table` (`plan_id` denormalized — a
  snapshot, not a live reference), `create_tenant_storage_usage_table`
  (`tenant_id` unique — one row per tenant). `tenant_id`/
  `tenant_subscription_id` columns all `cascadeOnDelete()`; `plan_id`
  references left at Postgres's default (no action / restrict) on both
  child tables — deleting a referenced plan must fail, not silently
  cascade or null out billing history, so `PlanResource` (B1) has to
  deactivate a plan instead of deleting one that is actually in use.
- Models: `Plan` (no `BelongsToTenant`, `CODE_FREE`/`STARTER`/`GROWTH`/
  `ENTERPRISE` consts), `TenantSubscription` (`STATUS_*` consts,
  `plan()`/`invoices()`), `SubscriptionInvoice` (`KIND_*`/`STATUS_*`
  consts, `subscription()`/`plan()`), `TenantStorageUsage` (`bytes_used`
  only). All four explicit `$fillable`, no `$guarded = []`.
- **Real bug, caught by the first test run, not a review pass:**
  `TenantStorageUsage` — Eloquent's default table-name convention
  pluralizes "Usage" to "Usages" (`tenant_storage_usages`), but the
  migration (matching design doc §3.1's literal schema) created
  `tenant_storage_usage` (singular). First `composer test` run failed
  with `SQLSTATE[42P01]: Undefined table: tenant_storage_usages`. Fixed
  with an explicit `protected $table = 'tenant_storage_usage';` on the
  model rather than renaming the migration — the design doc's schema name
  is the one worth keeping stable, not Eloquent's pluralization guess.
  `tenant_subscriptions`/`subscription_invoices` both pluralize correctly
  on their own, no override needed there.
- Registered `TenantSubscription`, `SubscriptionInvoice`,
  `TenantStorageUsage` in `TenantModelRegistry::MODELS`, full
  `seedAttributes()`/`updateAttributes()` for all three (`Plan` correctly
  left unregistered — decision 2). `SubscriptionInvoice`'s seed attributes
  nest a nested `TenantSubscription::create()` inside a nested
  `Plan::create()`, mirroring the existing multi-level nesting shape
  `FeeInvoice`/`FeePayment` already used for their own parent chains —
  confirmed against `CrossTenantTest`'s own "no tenant context bound"
  branch before writing it: the nested `TenantSubscription::create()`
  correctly throws `MissingTenantContextException` on its own (via
  `BelongsToTenant`'s `creating()` hook calling `CurrentTenant::id()`)
  when the outer test clears tenant context, so no special-casing was
  needed to satisfy that assertion.
- `PlanSeeder` — 4 rows (`free`/`starter`/`growth`/`enterprise`), round
  placeholder numbers; the free tier's bandwidth (5 GB/day) and AI cap
  ($1/day) intentionally match `BandwidthQuota`/`AiSpendCap`'s existing
  hardcoded free-tier constants for continuity. `updateOrCreate` keyed on
  `code`, safe to re-run. Registered in `DatabaseSeeder` after
  `TenantSeeder`.
- `composer test`: **807/808 passing** (+3, one new `CrossTenantTest`
  dataset entry per newly-registered model, exactly as predicted), same 1
  pre-existing, unrelated `CourseSlugUniquenessTest` failure carried since
  S1/S2, still out of scope.

### B1 — Plan catalog admin (Q1 pt.1) — DONE (2026-08-17)

- New Super-panel-only `Filament/Resources/Plans/PlanResource.php` (+
  `Pages/List|Create|Edit`) in `app/Domains/Billing/Filament/`. Added a
  second `->discoverResources(...)` call to `SuperPanelProvider` (it
  previously had exactly one, scoped to Tenancy) — same multi-domain
  stacking shape `AdminPanelProvider`/`TeachPanelProvider` already use for
  their own multiple domains.
- `code` locked on edit via the standard Filament idiom —
  `disabled(fn (?Plan $record) => $record !== null)` +
  `dehydrated(fn (?Plan $record) => $record === null)` — disabled on edit
  so it's never submitted in the update payload, dehydrated only on
  create so the value is actually saved there. `->unique(Plan::class,
  'code', ignoreRecord: true)` for the duplicate-code case, same pattern
  `BatchResource`/`CourseResource` already use for their own unique
  fields.
- Delete guard checks **two** things, not one: `tenants()->doesntExist()`
  (a tenant currently assigned this plan via `tenants.plan_id`) **and**
  `subscriptions()->doesntExist()` (any billing history at all, even from
  a tenant that's since moved off it). Added a `Plan::tenants()` `HasMany`
  (`app/Domains/Tenancy/Models/Tenant::class`) alongside B0's own
  `subscriptions()` to support this — `Tenant::$fillable` also gained
  `plan_id` here (B0 added the column but correctly left the model alone,
  scoped to Billing's own files only; this block is the first thing that
  actually needs to read/write it). Applied the guard on both the table's
  row action and the Edit page's own header `DeleteAction` — a small
  addition beyond what `AssignmentResource`'s own precedent covers (that
  one only guards the table row, not the Edit page's header action); kept
  here since the underlying DB FK is a real `restrict`, not
  `cascadeOnDelete()`/`nullOnDelete()`, so guarding both call sites turns
  a would-be raw constraint-violation error into a hidden button instead.
- `composer test`: **814/815 passing** (+7 — `PlanResourceTest`'s
  create/blank-seat-limit-unlimited/duplicate-code-rejected/
  edit-code-locked/hide-delete-tenant-assigned/
  hide-delete-subscription-history/allow-delete-unused), same 1
  pre-existing, unrelated `CourseSlugUniquenessTest` failure, still out of
  scope.
- **Not a code issue, an environment one:** Docker Desktop wasn't running
  when this block's first `composer test` attempt started — it doesn't
  fail fast, it hangs retrying the Postgres/Redis connection until the
  whole process timeout (900s) is exhausted with no useful error. Started
  Docker Desktop, waited for the engine, `docker compose up -d`, then
  reran clean. Worth checking `docker ps` first if a run times out with no
  test-level failure message at all.

### B2 — `TenantPlanLimits` resolver — DONE (2026-08-17)

- New `Billing/Services/TenantPlanLimits.php` — `seatLimit()`,
  `storageLimitBytes()`, `bandwidthLimitBytesPerDay()`,
  `aiSpendCapUsdPerDay()`, each branching on `$tenant->plan_id === null`.
  Legacy branch reads `BandwidthQuota::CAPS_BYTES`/`AiSpendCap::CAPS_USD`
  directly (one source of truth, not a duplicated copy of those numbers)
  — a genuinely new `LEGACY_STORAGE_LIMIT_GB` const had to be added on
  `TenantPlanLimits` itself for storage specifically, since no pre-S11
  storage cap ever existed anywhere to reuse (free 5 GB / paid 50 GB,
  matching bandwidth's own free/paid split for continuity). `seatLimit()`
  returns `null` (unlimited) for every plan_id-null tenant — no legacy
  seat concept existed before S11 either, and `null` is the only answer
  that keeps every pre-S11 tenant's behavior genuinely unchanged.
- Added `Tenant::plan(): BelongsTo` (`Tenancy` domain model reading
  `Billing\Models\Plan` directly — cross-domain *model* read, the
  established precedent, not a cross-domain service call).
- `BandwidthQuota`/`AiSpendCap` both gained a `TenantPlanLimits`
  constructor dependency; `capFor()` on each is now a one-line delegation.
  Confirmed no call site anywhere does `new BandwidthQuota()`/
  `new AiSpendCap()` before adding the constructor param — every real
  caller resolves through the container (`app(...)` in tests,
  constructor injection in `SpendCapGuardedAiDriver`/`StreamLessonController`
  elsewhere), so this was a safe addition. `CAPS_BYTES`/`CAPS_USD` consts
  themselves untouched, still public, still the legacy data source.
- Existing `BandwidthQuotaTest`/`AiSpendCapTest` — every pre-existing case
  passed **unmodified**, exactly as decision 3 requires; added one new
  `it(...)` to each for the plan_id-present path, plus a dedicated
  `TenantPlanLimitsTest` (4 cases: legacy free, legacy paid, real plan
  columns, `null` seat_limit means genuinely unlimited).
- `composer test`: **820/821 passing** (+6 — `TenantPlanLimitsTest`'s 4
  cases + 1 new case each in `BandwidthQuotaTest`/`AiSpendCapTest`), same
  1 pre-existing, unrelated `CourseSlugUniquenessTest` failure, still out
  of scope.

### B3 — `SeatQuota` + enforcement (Q2 pt.1) — DONE (2026-08-17)

- **Real deviation from this block's own plan, found exactly the way the
  note above anticipated ("confirm... at build time"):**
  `RegisteredUserController::store()` creates a plain `User` row with
  **no role at all** — every role grant in this codebase (parent invites
  today; a future admin/instructor-invite UI eventually) goes through
  `RoleAssignmentPolicy::assign()` instead, and grepping the whole `app/`
  tree turned up **zero** existing production call sites that ever assign
  `'institute_admin'`/`'instructor'` — only test fixtures do
  `$user->assignRole(...)` directly, bypassing the policy. So the
  designed hook (`RegisteredUserController`) genuinely does not exist for
  staff; `RoleAssignmentPolicy::assign()` is the real, already-established
  chokepoint every role grant passes through, and enforcing there means
  it's automatically wired up the moment a real staff-invite feature
  lands (S12+), no separate hookup needed then.
- New `Billing/Services/SeatQuota.php` — `usedNow()` = `User::where(
  'tenant_id', ...)->role(['institute_admin', 'instructor'])->count()`.
  `User` has no `BelongsToTenant` (roles are global to a user, per
  `RoleAssignmentPolicy`'s own existing note), so this is an explicit
  filter, not an ambient-scope query; soft-deleted users are already
  excluded by `User`'s own `SoftDeletes` scope, no separate "active" flag
  exists to check.
- `RoleAssignmentPolicy::assign()` gained a `guardSeatLimit()` private
  method, called after the existing privilege-escalation check passes and
  before `syncRoles()`. Only fires for `'institute_admin'`/`'instructor'`;
  skips entirely if the target already holds either (a tier change, not
  new headcount) or has no `tenant_id` (platform-level user).
  **Deliberate cross-domain call**, documented inline: `Tenancy\Policies`
  calling `Billing\Services\SeatQuota` directly is a precedented exception
  to "domains talk through Events only" — `Comms\Services\
  ParentInviteCodeService` and `Comms\Filament\...\CreateParentGuardian`
  already call straight into this exact policy class from a different
  domain, and a synchronous "block this action" check cannot be expressed
  as an event in the first place (events react after the fact, they don't
  gate before it).
- New `SeatLimitExceededException` (`app/Exceptions/`), registered in
  `bootstrap/app.php` as `abort(409, ...)` — same shape as
  `BandwidthQuotaExceededException`/`AiSpendCapExceededException`, a
  capacity conflict, not an authorization failure like
  `PrivilegeEscalationException` (`abort(403)`, no message).
- `composer test`: **828/829 passing** (+8 — `SeatQuotaTest`'s 5 cases +
  `RoleAssignmentTest`'s 3 new cases: blocked-at-cap, tier-change-allowed-
  at-capacity, non-staff-role-never-checked), same 1 pre-existing,
  unrelated `CourseSlugUniquenessTest` failure, still out of scope.
  One real test bug caught by the first run, not a design flaw: the
  tier-change test originally had an `institute_admin` actor promote a
  target to `institute_admin` — `RoleHierarchy` correctly refuses that as
  privilege escalation regardless of seats (an admin can never grant its
  own level), so the actor had to be `super_admin` instead for the test
  to actually exercise the seat-tier-change path it meant to test.

### B4 — `TenantStorageUsage` + `StorageQuota` (Q1/Q2 pt.2) — DONE (2026-08-17)

- Grep confirmed exactly the 4 sites the design doc named, plus one it
  didn't name explicitly by column but is the same underlying method:
  `AssignmentSubmissionService::storeVersion()`, `Lesson::
  attachUploadedPdf()` (+ its `forceDeleting` hook), `CertificateService::
  issue()`, and `BrandingService::storeUpload()`/`storeTempUpload()` — the
  latter is a **single shared method already** for all 4 branding fields
  (logo, favicon, certificate-signature, certificate-background), so one
  hook there covers all four, not four separate ones.
- New `Billing/Services/StorageQuota.php` — `capFor`/`usedNow`/
  `remainingFor` (same `withoutGlobalScope` pattern `BandwidthQuota::
  usedToday()` already established) + one `adjust(Tenant, int $delta)`
  doing both directions (positive records, negative releases, floored at
  0) rather than separate `record()`/`release()` methods.
- **Real design refinement found at build time, not in the original
  plan:** `Lesson::attachUploadedPdf()` and `BrandingService::
  storeUpload()` both **overwrite the same computed disk path** on a
  same-extension re-upload (no new path, no delete call at all) — a naive
  "always increment on store" would inflate the counter on every
  re-upload of an unchanged file, unlike assignment submissions
  (always a new versioned path, safe to only ever increment) and
  certificates (always a new path, never overwritten). Fixed by reading
  the *previous* file's size before overwriting and calling
  `adjust(newBytes - previousBytes)` — a net-zero delta on an identical
  re-upload, a correct partial delta otherwise. `BrandingService::
  storeUpload()`/`storeTempUpload()` both gained a `?string $previousPath`
  parameter so each of the 4 public callers can read the tenant's current
  column value before calling in.
- Pre-checked at 3 of the 4 sites (assignment submissions, lesson PDFs,
  branding) — all genuinely interactive, blockable actions, matching
  `SeatQuota`'s "point of action" posture, each throwing the new
  `StorageLimitExceededException` (409, registered in `bootstrap/app.php`
  same as every other quota exception). **Deliberately not pre-checked at
  the 4th (`CertificateService::issue()`)** — it runs from S10's own
  unscoped daily sweep with no interactive user watching to retry;
  blocking a student's already-earned certificate over an institute-level
  storage cap is a worse failure mode than letting one certificate go
  through slightly over budget. Usage is still tracked there (increment
  only), just never gates the action. Documented inline and in
  `CertificateServiceTest`.
- `Lesson::attachUploadedPdf()` and `BrandingService::storeUpload()` both
  resolve `StorageQuota` via `app(...)` rather than constructor injection
  — a Model can't take constructor dependencies (same reason
  `attachUploadedVideo()` already resolves `VideoProvider` the same way);
  `AssignmentSubmissionService`/`CertificateService`/`BrandingService`
  (real Service classes) all got real constructor injection instead.
- `AssignmentSubmit` Livewire component gained a catch clause for the new
  exception (same shape as its 3 existing catches) — without it the
  exception would have bubbled up as a raw 500 inside a Livewire action
  instead of a clean form error, same class of gap B3 didn't have to fix
  only because `RoleAssignmentPolicy::assign()` isn't reached through a UI
  yet.
- Every cross-domain call here (`Assignments`→`Billing`,
  `Content`→`Billing`, `Tenancy`→`Billing`) is the same deliberate,
  documented exception S11 B3 already established for `SeatQuota` — not
  re-argued at length per call site, just flagged inline.
- One real test bug, not a code bug, caught by the first run: the
  branding re-upload test failed with 550 KB recorded instead of the
  expected 350 KB (200+350, i.e. it looked like a genuine double-count).
  Root cause was in the test, not `storeUpload()` — `UploadedFile::
  fake()->create()` writes no real bytes into `Storage::fake()` (the
  identical gotcha `LessonContentTypesTest`'s own bandwidth-quota test
  already documents for the `'local'` disk, here hitting `'public'`
  instead): the *new* file's size is read from the `UploadedFile` object's
  own metadata (always correct), but the *previous* file's size is read
  back via `Storage::size()`, which needs real bytes physically on the
  fake disk to report anything but 0. Fixed by writing real content into
  the first upload's path before the second call, matching the same
  workaround already established elsewhere in this codebase — production
  code was never wrong, since a real re-upload's previous file genuinely
  has real bytes on disk.
- `composer test`: **844/845 passing** (+16 — `StorageQuotaTest`'s 5
  cases + `AssignmentSubmitTest`'s 2 + `CertificateServiceTest`'s 2 +
  `BrandingServiceTest`'s 3 + `LessonContentTypesTest`'s 4), same 1
  pre-existing, unrelated `CourseSlugUniquenessTest` failure, still out of
  scope.

### B5 — `SubscriptionProvider` + Razorpay drivers (Q3 pt.1) — DONE (2026-08-17)

- `app/Integrations/SubscriptionProvider.php` — `createSubscription(
  TenantSubscription)`, `changePlan(TenantSubscription, Plan)`,
  `cancelSubscription(TenantSubscription)`, `verifyWebhookSignature()`.
  Method signatures take the actual `Billing` models built in B0, not
  placeholder scalars — `createSubscription()` reads
  `$subscription->plan->razorpay_plan_id` itself rather than the caller
  having to pass it separately.
- `Drivers/RazorpaySubscriptionProvider.php` — real driver, unit-tested
  via `Http::fake()` (7 cases, mirrors `RazorpayProviderTest`'s own
  shape). `total_count: 100` on `createSubscription()` — Razorpay's
  Subscriptions API requires a finite cycle count even for an
  effectively-open-ended subscription; 100 monthly cycles is ~8 years,
  comfortably renewed by a fresh subscription long before it would ever
  lapse from this alone. `changePlan()` sends `schedule_change_at: 'now'`,
  not `'cycle_end'` — this app's own proration ledger (decision 9)
  already accounts for the switch happening immediately. New guard:
  refuses to create a subscription against a `Plan` with no
  `razorpay_plan_id` set yet, with a clear message rather than sending
  Razorpay a null plan reference. Not yet bound in `AppServiceProvider`
  (no real credentials), same posture as `RazorpayProvider` itself.
- `Drivers/FakeRazorpaySubscriptionDriver.php` — the actually-bound
  implementation (`AppServiceProvider::register()`), same "hash-seeded,
  never repeats a subscription_id across calls" posture
  `FakeRazorpayDriver` already established for orders.
- New `services.razorpay.subscription_webhook_secret` config key
  (`RAZORPAY_SUBSCRIPTION_WEBHOOK_SECRET`) — deliberately separate from
  the existing Orders-API `webhook_secret`, since B7's subscription
  webhook is a different URL Razorpay can (and should) sign with its own
  secret, not reuse the Fees domain's.
- `composer test`: full suite run below (`RazorpaySubscriptionProviderTest`
  7 cases + `FakeRazorpaySubscriptionDriverTest` 4 cases = 11 new), same 1
  pre-existing, unrelated `CourseSlugUniquenessTest` failure, still out of
  scope.

### B6 — `TenantSubscription` creation (Q3 pt.2) — DONE (2026-08-17)

- **Real structural gap found at build time:** there was no `TenantResource`
  anywhere in this codebase to add a "Plan" section *to* —
  `ImpersonateUser`'s own docblock already said so explicitly ("there is
  no Tenant/User Resource anywhere in this panel yet"), independently
  confirmed by grep. Built a **deliberately minimal** one now: `name`/
  `status` editing + the list (name, subdomain, status, plan) — no Create
  page (tenant provisioning is a separate, still-unbuilt gap, `FEATURES.md`
  A5/A8, out of scope for a billing slice) and no branding/custom-domain
  fields (also out of scope). Noted, not silently expanded into.
  Registered for free — `SuperPanelProvider`'s existing
  `discoverResources(in: '.../Tenancy/Filament/Resources', ...)` call
  (already there since S1) picks up the new `Tenants/` subfolder with no
  provider edit needed.
- Plan assignment is a dedicated `assignPlan` header `Action` on the Edit
  page (`->schema([...])->action(...)`, the same modal-form-action shape
  `AssignmentSubmissionResource`'s own `grade` action already
  established), **not** a raw `plan_id` field on the main form — decision
  10's whole point is that assignment goes through
  `PlanChangeService::subscribe()`, which also creates the
  `TenantSubscription` row; a bare column edit would leave `plan_id` set
  with no subscription behind it, a state nothing else in `Billing`
  expects. Hidden once `plan_id` is already set — assigning a *different*
  plan later is B10's `changePlan()` (proration), a different operation.
- `Billing/Services/PlanChangeService::subscribe()` — creates
  `TenantSubscription` with status **`trialing`**, not `active`: no trial
  concept was ever locked in the design, but the vendor's own initial
  response status (`'created'`, before the customer completes the hosted
  mandate) maps naturally onto "started, no successful charge yet" —
  exactly what `trialing` already means here. B7's webhook is what
  actually flips it to `active` once a real payment webhook lands, not
  this method. `current_period_start`/`end` get placeholder values (now /
  +1 month) for the identical reason — no real billing cycle exists until
  a charge succeeds.
- Guards against double-subscribing: refuses (new
  `TenantAlreadySubscribedException`, 409) if the tenant already has a
  `trialing`/`active`/`grace` subscription. A `cancelled` one doesn't
  block a fresh `subscribe()` call — tested explicitly.
- Runs `TenantSubscription::create()` inside `withoutEvents()` with an
  explicit `tenant_id` — same shape every other `BelongsToTenant` row
  created from an unscoped position already uses (`CertificateService`,
  `RecordVideoCoverageAttendance`): a Super-panel action has no
  `CurrentTenant` bound to the tenant being acted on.
- `composer test`: full suite run below (`PlanChangeServiceTest` 3 cases +
  `TenantResourcePlanSectionTest` 3 cases = 6 new), same 1 pre-existing,
  unrelated `CourseSlugUniquenessTest` failure, still out of scope.

### B7 — Webhook + reconciler (Q3 pt.3) — DONE (2026-08-17)

- `routes/api.php`: `Route::post('/webhooks/razorpay-subscriptions',
  RazorpaySubscriptionWebhookController::class)`, same "outside every
  tenant domain group" posture as the existing Fees webhook route.
- `Http/Controllers/Billing/RazorpaySubscriptionWebhookController.php` —
  thin, delegates to `SubscriptionReconciler`, mirrors
  `RazorpayWebhookController` exactly.
- `Billing/Services/SubscriptionReconciler.php` — verifies signature via
  `SubscriptionProvider` (same HMAC discipline as `PaymentReconciler`),
  looks the local `TenantSubscription` up by its own
  `razorpay_subscription_id` column rather than trusting the payload's
  notes for tenant resolution (a cleaner variant of `PaymentReconciler`'s
  own "verify first, resolve tenant only from something we ourselves
  wrote" approach — possible here because, unlike `FeePayment`,
  `TenantSubscription` already has a real vendor-id column to key off).
- `subscription.charged` → `status = active`, clears `grace_ends_at`,
  updates `current_period_start`/`end` from the payload (falls back to
  the existing value if absent), writes a `paid`-status
  `SubscriptionInvoice`. Deduped on `razorpay_payment_id` — a resent
  webhook does not write a second invoice row (Razorpay retries delivery
  on anything but a 2xx response).
- `payment.failed` → `status = grace`, `grace_ends_at = now()->addDays(7)`
  per decision 7 — but only when it was previously `null`. A *second*
  distinct failure while already in grace does not push the deadline
  further out (`?? now()->addDays(7)`, not an unconditional set) —
  otherwise a subscription stuck retrying-and-failing could extend its
  own grace period indefinitely, defeating the point of a 7-day cap.
  Tested explicitly with two different `razorpay_payment_id`s two days
  apart.
- **Real design refinement, not in the original plan:**
  `subscription.completed` and `subscription.cancelled` both collapse to
  the same local `STATUS_CANCELLED` — `TenantSubscription`'s status enum
  (locked at B0) has no separate "completed" value, and every downstream
  reader this slice builds (grace/suspension enforcement B9, the billing
  views B11/B12) only ever needs "is this still billing," not why it
  stopped. Adding a fifth status for a case that's ~8 years out in
  practice (100-cycle `total_count`, B5) would be state nothing reads.
  Documented inline rather than silently decided.
- `composer test`: full suite run below
  (`SubscriptionReconcilerTest` 9 cases +
  `RazorpaySubscriptionWebhookControllerTest` 2 cases = 11 new), same 1
  pre-existing, unrelated `CourseSlugUniquenessTest` failure, still out of
  scope.

### B8 — Grace-period sweep (Q4) — not started

- `Billing/Jobs/SuspendOverdueTenantSubscriptions.php` — daily, flips
  `grace`→`suspended` once `grace_ends_at` has passed with no successful
  payment landing in between. Mirror `FlagOverdueInvoices`'s own shape.
- `bootstrap/app.php` `withSchedule()`: add alongside S9's five + S10's
  one existing scheduled jobs.
- Guard against a tenant with no active subscription at all (never
  subscribed, or already cancelled) — sweep must skip those cleanly, not
  throw.

### B9 — Suspension enforcement — not started

- Confirm the actual current enforcement point for
  `Tenant::STATUS_SUSPENDED` (likely `TenantAuthenticator` or
  `ResolveTenant` — read both before assuming) and verify a
  billing-suspended tenant is genuinely blocked end-to-end, not just
  flagged in the DB.
- Grace-window banner: Admin panel shows a visible "N days left in grace
  period" notice once `TenantSubscription.status === 'grace'`.

### B10 — Upgrade/downgrade + proration (Q5) — not started

- `PlanChangeService::changePlan()` — computes
  `(days_remaining_in_period / period_days) × (new_plan.price −
  old_plan.price)` per design doc decision 9, writes a `proration`-kind
  `SubscriptionInvoice`, updates `TenantSubscription.plan_id`.
- Downgrade below current usage (e.g. new plan's seat_limit < current
  active staff count) — decide and document behavior here: block the
  downgrade with a clear error listing what's over, rather than silently
  leaving the tenant over-limit on a plan that can't support it.

### B11 — Institute-facing billing view — not started

- Admin panel `Filament/Pages/BillingOverview.php` — read-only: current
  plan name/limits, live usage (seat/storage/bandwidth, reusing
  `SeatQuota`/`StorageQuota`/`BandwidthQuota` directly, not re-querying),
  subscription status, `SubscriptionInvoice` history table.

### B12 — Super-panel cross-tenant billing visibility — not started

- Subscription list/filter in the Super panel — apply
  `withoutGlobalScope('tenant')` up front on every query here
  (S9 B10's sweep + S10 B13's fix both exist because this got missed
  after the fact elsewhere; build it in from the start this time).

### B13 — Cancellation flow — not started

- `PlanChangeService::cancel()` — `TenantSubscription.status =
  'cancelled'`, `cancelled_at` set, calls
  `SubscriptionProvider::cancelSubscription()`. `tenants.plan_id` reset to
  `null` (decision 11 — falls back to legacy free-tier caps, tenant stays
  active, just capped).

### B14 — Grace/suspension notifications — not started

- Reuse Comms domain's existing WhatsApp driver (same one Fees P7's
  defaulter reminders use) for a grace-entry notice and a T-3-before-
  suspension reminder to the tenant's registered billing contact.

### B15 — Edge cases + concurrency — not started

- Webhook double-delivery: Razorpay resends on non-2xx or timeout —
  confirm `SubscriptionReconciler` is idempotent on a repeated event
  (same event id twice must not double-write `SubscriptionInvoice`).
- Sweep job vs. a soft-deleted tenant/subscription — proactively apply
  S10 B15's own fix shape (`whereHas` chains that implicitly exclude
  soft-deleted parents) rather than waiting for a review pass to find it.
- Any count-then-write race in `PlanChangeService` or the seat/storage
  checks — apply the same transaction+retry pattern S10 B15 had to add
  for `AssignmentSubmissionService::submit()`, if a genuine race exists
  here too.

### B16 — Full test coverage, review pass — not started

- Sweep for anything the per-block tests didn't cover end-to-end (full
  subscribe→charge→grace→suspend→cancel lifecycle as one integration
  test).
- Update `TenantModelRegistry` coverage is already enforced by B0; just
  confirm no model slipped through.
- Update `HANDOVER.md` (S11 complete entry + any new `## Avoid` entries)
  and `docs/PROJECT-STATUS.md` (new S11 section, matching S1-S10's exact
  format).
