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
| B4 | `TenantStorageUsage` + `StorageQuota`, wired into every file-store/delete call site (Q1/Q2 pt.2 — storage) | ⬜ not started |
| B5 | `SubscriptionProvider` interface + `RazorpaySubscriptionProvider` + `FakeRazorpaySubscriptionDriver` (Q3 pt.1) | ⬜ not started |
| B6 | `TenantSubscription` creation — `TenantResource` "Plan" section, `PlanChangeService::subscribe()` (Q3 pt.2) | ⬜ not started |
| B7 | Webhook — `RazorpaySubscriptionWebhookController` + `SubscriptionReconciler`, `SubscriptionInvoice` writes (Q3 pt.3) | ⬜ not started |
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

### B4 — `TenantStorageUsage` + `StorageQuota` (Q1/Q2 pt.2) — not started

- Grep for every model/controller that stores a user-uploaded file
  counted toward institute storage: assignment submission files
  (`AssignmentSubmissionService`), lesson downloadable resources, issued
  certificate PDFs, branding uploads (`BrandingService`). Enumerate the
  real list here before writing any hook — design doc deliberately left
  this open rather than guessing.
- New `Billing/Services/StorageQuota.php` (`capFor`/`usedNow`/
  `remainingFor`, reading `TenantStorageUsage.bytes_used`).
- Increment on successful store, decrement on delete, at each enumerated
  site. Soft-deletes that don't remove the underlying file do **not**
  decrement (design doc §3.3).
- Pre-check `remainingFor()` before accepting a new upload at each site,
  same "block at the point of action" posture as `SeatQuota`.

### B5 — `SubscriptionProvider` + Razorpay drivers (Q3 pt.1) — not started

- `app/Integrations/SubscriptionProvider.php` — `createSubscription()`,
  `changePlan()`, `cancelSubscription()`, `verifyWebhookSignature()`.
- `Drivers/RazorpaySubscriptionProvider.php` — real driver against
  Razorpay's Subscriptions API, unit-tested via `Http::fake()` (mirrors
  `RazorpayProviderTest`'s own shape). Not yet bound in
  `AppServiceProvider` (no real credentials), same posture as
  `RazorpayProvider` itself.
- `Drivers/FakeRazorpaySubscriptionDriver.php` — the actually-bound
  implementation until real credentials exist.

### B6 — `TenantSubscription` creation (Q3 pt.2) — not started

- `TenantResource` (Tenancy domain, Super panel) edit page gains a "Plan"
  section: assign/change `plan_id`, trigger subscribe.
- `Billing/Services/PlanChangeService::subscribe()` — creates
  `TenantSubscription` (status `trialing` or `active` depending on
  whether a trial period is configured — confirm against design doc, no
  trial concept was locked, so default straight to `active` unless a
  reason to add trialing surfaces here), calls
  `SubscriptionProvider::createSubscription()`.

### B7 — Webhook + reconciler (Q3 pt.3) — not started

- `routes/api.php`: `Route::post('/webhooks/razorpay-subscriptions',
  RazorpaySubscriptionWebhookController::class)`, same "outside every
  tenant domain group" posture as the existing Fees webhook route.
- `Http/Controllers/Billing/RazorpaySubscriptionWebhookController.php` —
  thin, delegates to `SubscriptionReconciler`, mirrors
  `RazorpayWebhookController` exactly.
- `Billing/Services/SubscriptionReconciler.php` — verifies signature
  (same HMAC pattern as `PaymentReconciler`), handles
  `subscription.charged`/`subscription.completed`/`payment.failed`/
  `subscription.cancelled`, writes `SubscriptionInvoice` rows, updates
  `TenantSubscription.status`. `payment.failed` sets
  `grace_ends_at = now()->addDays(7)` per design doc decision 7.

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
