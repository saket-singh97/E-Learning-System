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
| B0 | Domain groundwork — `Billing` scaffold, `plans`/`tenant_subscriptions`/`subscription_invoices`/`tenant_storage_usage` migrations + models, `tenants.plan_id`, tenant registry, `PlanSeeder` (free/starter/growth/enterprise) | ⬜ not started |
| B1 | Plan catalog admin (Q1 pt.1) — Super panel `PlanResource` CRUD | ⬜ not started |
| B2 | `TenantPlanLimits` resolver; `BandwidthQuota`/`AiSpendCap` migrated onto it (plan_id-aware, legacy fallback preserved) | ⬜ not started |
| B3 | `SeatQuota` + enforcement at staff registration (Q2 pt.1) | ⬜ not started |
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

### B0 — Domain groundwork — not started

- Scaffold `app/Domains/Billing/{Models,Services,Filament,Jobs}` (no
  `Events/`/`Livewire/`/`Views/`/`Policies/` yet — add only when a block
  actually needs one, matching S6 B0's "don't pre-scaffold empty folders"
  precedent).
- Migrations: `plans` (central, no `tenant_id`), `tenant_subscriptions`,
  `subscription_invoices`, `tenant_storage_usage` (all three
  `tenant_id ... cascadeOnDelete()`), plus `tenants.plan_id` (nullable FK,
  `nullOnDelete()` — a deleted plan must not take a tenant row down with
  it). Check `plans.code` gets a real unique index.
- Models: `Plan` (no `BelongsToTenant`), `TenantSubscription`,
  `SubscriptionInvoice`, `TenantStorageUsage` (all three
  `BelongsToTenant`). Explicit `$fillable` on every one (no `$guarded =
  []`, enforced by existing test suite).
- Register `TenantSubscription`, `SubscriptionInvoice`,
  `TenantStorageUsage` in `TenantModelRegistry::MODELS` **in this block**,
  full `seedAttributes()`/`updateAttributes()` — register-without-seed
  throws immediately once `CrossTenantTest` iterates `MODELS` (every prior
  domain-groundwork block's own note applies verbatim). `Plan` is **not**
  registered (decision 2 — un-tenanted).
- `PlanSeeder` — 4 rows (`free`/`starter`/`growth`/`enterprise`), round
  placeholder numbers for limits/price (design doc explicitly doesn't
  invent real business pricing, same posture as S9 B12's own caps).
- Run `composer test`, confirm 807/808 (+3, one new `CrossTenantTest`
  dataset entry per newly-registered model), same 1 pre-existing failure.

### B1 — Plan catalog admin (Q1 pt.1) — not started

- New Super-panel-only `Filament/Resources/Plans/PlanResource.php` (+
  `Pages/List|Create|Edit`) in `app/Domains/Billing/Filament/`. Registered
  via `SuperPanelProvider`'s `->discoverResources(...)`, not Admin/Teach.
- Form: `code` (locked/immutable after create — changing it would orphan
  existing `tenants.plan_id`/`razorpay_plan_id` references), seat/storage/
  bandwidth/AI caps, price, `razorpay_plan_id` (nullable text — populated
  once a real Razorpay account exists, same "field exists, wiring waits
  for real credentials" posture as `RazorpayProvider`), `is_active`.
- Delete guard: hide delete once any tenant references the plan (mirrors
  `AssignmentResource`'s own submission-guard pattern from S10 B1) —
  deactivate (`is_active = false`) instead.

### B2 — `TenantPlanLimits` resolver — not started

- New `Billing/Services/TenantPlanLimits.php` implementing design doc
  §3.2's four methods, each branching on `$tenant->plan_id === null`
  (legacy `CAPS_*` const lookup) vs. real `$tenant->plan` columns.
- `BandwidthQuota::capFor()` and `AiSpendCap::capFor()` become one-line
  delegations to the new resolver; their own `CAPS_BYTES`/`CAPS_USD`
  consts stay put as the legacy-fallback data source (not deleted —
  `TenantPlanLimits` reads them directly when `plan_id` is null).
- Existing `BandwidthQuotaTest`/`AiSpendCapTest` must keep passing
  unmodified (plan_id-null tenants, decision 3's whole point); add new
  cases alongside them for the plan_id-present path.

### B3 — `SeatQuota` + enforcement (Q2 pt.1) — not started

- New `Billing/Services/SeatQuota.php` — `usedNow()` counts active
  `admin`+`instructor` role users scoped to the tenant (confirm exact
  Spatie role-query shape already used elsewhere, e.g.
  `RoleAssignmentPolicy`, reuse it rather than reinventing).
- Locate the real staff-registration entry point at build time
  (`RegisteredUserController` was the lead from design-doc research —
  confirm it's actually reachable in tenant context for admin/instructor
  signup, not just students) and add the pre-create check there. Clear,
  specific error message on over-limit, not a raw DB constraint failure.

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
