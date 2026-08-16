# Design: S11 Plan Tiers + Institute Billing — AI-Native LMS

## 1. Why this document exists

Locks scope and architecture for S11 before any block is built, same role
S1-S10's own design docs played. Full block-by-block build log lives in the
companion `2026-08-16-s11-plan-billing-plan.md`.

S11 is the third P2-backlog slice (after S9 hardening, S10 assignments/
gradebook/certificates). Scope: `docs/FEATURES.md` **Section Q — B2B Billing
(platform → institute)**, items Q1-Q5.

---

## 2. S11 scope

### 2.1 In scope

| # | Feature | Source |
|---|---|---|
| Q1 | Plan tiers with seat, storage, and bandwidth limits | REQ-17, NEW |
| Q2 | Seat/storage/bandwidth enforcement at the point of action | REQ-17, NEW |
| Q3 | Razorpay recurring subscriptions for institutes | REQ-17 |
| Q4 | 7-day grace period → suspension | REQ-17 |
| Q5 | Plan upgrade/downgrade with proration | REQ-17 |

### 2.2 Out of scope, and where each goes

- **Q6 (MRR/ARR, per-institute revenue, success/failure rates)** and **Q7
  (revenue export CSV/PDF job)** — both P3, both need real billing history
  (`subscription_invoices` rows) to aggregate. Building analytics before
  there's data to analyze is backwards; fast-follow once Q1-Q5 have been
  live long enough to produce a few billing cycles. Lands as a `Billing`
  domain analytics widget + export job, same shape S10's gradebook export
  jobs (`ExportGradebookCsv`/`Excel`/`Pdf`) already established.
- **Public self-serve pricing/checkout page.** Every tenant in this
  codebase today was provisioned by the platform operator, not
  self-service; a marketing/signup flow is `R`-series growth territory
  (P3), not this slice.
- **Multi-currency / pluggable gateway** (Fees domain's own P10, still
  unbuilt there too). S11 stays Razorpay/INR-only, matching Fees domain's
  current scope exactly — no reason for B2B billing to be ahead of B2C
  billing on this axis.
- **Per-tenant video storage cost** (Bunny origin storage). D8 already
  scoped bandwidth *metering* (delivery), not storage; S11's storage quota
  covers files this app itself stores (assignment submissions, lesson
  resources, certificates, branding), not Bunny-hosted video bytes.

### 2.3 Reviewed before writing this: what's already there

- `Tenant::PLAN_TIER_FREE`/`PLAN_TIER_PAID` — a flat 2-value enum.
  `BandwidthQuota`/`AiSpendCap` (both S9 B12) key hardcoded
  `CAPS_BYTES`/`CAPS_USD` arrays off it. Both classes' own doc comments
  call this out as a deliberate placeholder: *"a richer tiers/limits
  product is fast-follow once real customers need different limits from
  each other."* S11 is that fast-follow.
- `PaymentProvider`/`RazorpayProvider`/`FakeRazorpayDriver` (S7/S9) —
  Razorpay **Orders API** only, typed directly to `FeeInvoice`
  (institute→student, one-off charge). Not reusable for platform→institute
  **recurring** billing — Razorpay's Subscriptions API is a different
  vendor surface (mandate + recurring cycle, not a single order).
- `RazorpayWebhookController` (`routes/api.php`, outside every
  `Route::domain(...)` group) + `PaymentReconciler` — the established
  pattern for "one fixed vendor URL, no tenant subdomain, signature
  verified before anything is trusted." S11's subscription webhook mirrors
  this exactly, doesn't reinvent it.
- **No existing storage-usage instrumentation anywhere** (grepped the
  whole `app/` tree — zero hits for anything storage-metering shaped).
  Q1's storage limit is new from scratch, unlike bandwidth which already
  had `BandwidthUsageDaily` (S2) to build a cap on top of in S9 B12.
- **No staff `UserResource`.** Admin/instructor accounts are created via
  self-registration (`RegisteredUserController`), not a Filament resource.
  This is the natural point-of-action hook for Q2's seat enforcement.
- `Batch::seat_limit` (Batches domain) is a wholly unrelated concept —
  per-batch *student* capacity. S11's "seat" is a Tenancy/Billing-domain
  concept: the institute's own staff headcount.

### 2.4 Decisions (locked 2026-08-16, before Block 0)

1. **New `Billing` domain**, not folded into `Tenancy` or `Fees`.
   Tenancy owns identity/branding/auth; Fees owns institute→student money.
   Platform→institute money is a third, structurally distinct concern —
   same reasoning that gave Fees its own domain in S7 and Assignments its
   own domain in S10 rather than living inside Content.

2. **`Plan` is a new, un-tenanted catalog table** — not
   `BelongsToTenant`. Plans belong to the platform; only the Super panel
   CRUDs them. `TenantSubscription`/`SubscriptionInvoice`/
   `TenantStorageUsage` **do** belong to a tenant (`BelongsToTenant`,
   registered in `TenantModelRegistry`) since each institute needs to see
   its own billing history in its own Admin panel.

3. **Additive, not a replacement, to `Tenant::plan_tier`.** Add a
   nullable `plan_id` FK to `tenants`. `BandwidthQuota`/`AiSpendCap`
   (and the two new quota classes) prefer `$tenant->plan`'s real columns
   when `plan_id` is set, and fall back to the existing
   `CAPS_BYTES`/`CAPS_USD` const arrays keyed by `plan_tier` when it's
   null. Zero blast radius on every existing S9 test asserting the
   hardcoded 2-tier behavior; every pre-S11 tenant keeps working exactly
   as today. This is the exact extension point S9 B12's own doc comment
   already flagged in advance — implemented as the fallback branch, not a
   migration that rewrites history.

4. **Seat = active users with role `admin` or `instructor`** on the
   tenant. Students excluded. Unlimited student enrolment is the
   institute's own monetization lever — capping it would fight the
   customer. "Seat" in B2B SaaS billing conventionally means the
   *vendor's own team size*; `RoleHierarchy` is the only "team" concept
   this codebase already has.

5. **Storage tracked as one running-counter row per tenant**
   (`tenant_storage_usage.bytes_used`), incremented/decremented at the
   exact call sites that store or delete a billable file — not a nightly
   disk scan. Q2 explicitly wants enforcement *at the point of action*
   (block an upload before it lands); a nightly scan can only flag a
   violation a day late. No existing instrumentation to build on either
   way (per 2.3), so a running counter costs no more than a scan job and
   actually satisfies Q2. Exact call sites (assignment submissions,
   lesson downloadable resources, certificate PDFs, branding uploads) get
   enumerated by grep at build time (B4), not guessed here.

6. **Two Razorpay integrations stay genuinely separate.** Existing
   `PaymentProvider` (Orders API, `FeeInvoice`-typed) is untouched. New
   `SubscriptionProvider` interface + `RazorpaySubscriptionProvider`/
   `FakeRazorpaySubscriptionDriver` (Subscriptions API,
   `TenantSubscription`-typed) sit beside it in `app/Integrations/`.
   Razorpay's Subscriptions API is a genuinely different surface
   (recurring mandate vs. one-shot order) — forcing one interface to
   cover both would leak Orders-specific assumptions (a single
   `total_amount`) into a shape that actually needs `plan_id` + period
   dates. Same "real driver, not yet bound, Fake bound until real
   credentials exist" posture as every driver pair already in this repo
   (`ZoomVideoSdkProvider`, `RazorpayProvider`).

7. **Grace period is a scheduled sweep, not webhook-only.** A
   `payment.failed` webhook flips `active`→`grace` immediately and sets
   `grace_ends_at = now()->addDays(7)`. New `SuspendOverdueTenantSubscriptions`
   job (daily, alongside S9's five + S10's one existing scheduled jobs)
   flips `grace`→`suspended` once `grace_ends_at` passes with no
   successful payment. Mirrors `FlagOverdueInvoices` (Fees domain) and
   S10's `CheckCourseCompletionAndIssueCertificates` — this codebase's own
   established pattern for "a state transition that depends on elapsed
   time, not just an event" is always a scheduled sweep, never computed
   lazily on read.

8. **Suspension reuses `Tenant::STATUS_SUSPENDED`**, not a second flag.
   A billing-suspended tenant sets `tenants.status = suspended`, the same
   column S1 built and something already gates login on. B9 confirms and
   wires the actual enforcement point (`TenantAuthenticator` or
   `ResolveTenant`) rather than adding a parallel suspended-for-billing
   check that could drift out of sync with the original one.

9. **Proration is computed in our own ledger**, not delegated to
   Razorpay's own plan-change proration (opaque for INR subscriptions).
   `PlanChangeService` computes
   `(days_remaining_in_period / period_days) × (new_plan.price −
   old_plan.price)` as a one-time adjustment, written as a `proration`-kind
   `SubscriptionInvoice` row and charged on the next cycle rather than a
   live mid-cycle charge. Keeps the money math auditable in our own rows,
   consistent with how `FeeInvoiceService`/`PaymentReconciler` already
   keep Fees-domain money auditable instead of trusting a vendor's opaque
   computation. A live mid-cycle charge is genuinely deferred complexity
   (its own webhook-order dance), not a shortcut.

10. **No public pricing/checkout page.** Plan assignment and subscription
    creation is Super-panel-initiated (`TenantResource`'s edit page gains
    a "Plan" section) — matches the platform's actual current sales
    motion (every institute in this codebase was operator-provisioned).
    Self-serve signup is `R`-series (P3), out of scope here.

11. **Cancellation reverts a tenant to `plan_id = null`**, not to a
    special "cancelled plan" row — it falls straight back into decision
    3's legacy-fallback branch (existing free-tier `plan_tier` caps). No
    new state needed for "no active plan"; the fallback already models it
    correctly.

---

## 3. Architecture

### 3.0 Where this lands

```
app/Domains/Billing/
  Models/        Plan, TenantSubscription, SubscriptionInvoice, TenantStorageUsage
  Services/      TenantPlanLimits, SeatQuota, StorageQuota, PlanChangeService,
                 SubscriptionReconciler
  Filament/      Resources/Plans (Super panel), Pages/BillingOverview (Admin panel,
                 institute's own read-only plan/usage/invoice view)
  Jobs/          SuspendOverdueTenantSubscriptions
app/Integrations/
  SubscriptionProvider.php
  Drivers/RazorpaySubscriptionProvider.php
  Drivers/FakeRazorpaySubscriptionDriver.php
app/Http/Controllers/Billing/
  RazorpaySubscriptionWebhookController.php   (routed from routes/api.php)
```

No `Events/`, `Livewire/`, or student-facing `Views/` — this slice is
entirely Super-panel ops + Admin-panel read-only visibility, no
student-facing surface.

### 3.1 Schema

```
plans                        (central — no tenant_id, no BelongsToTenant)
  id, code (unique: free|starter|growth|enterprise), name,
  seat_limit (nullable = unlimited), storage_limit_gb,
  bandwidth_limit_gb_per_day, ai_spend_cap_usd_per_day,
  price_inr_per_month, razorpay_plan_id (nullable), is_active, timestamps

tenants
  + plan_id (nullable FK -> plans, null = legacy plan_tier fallback)

tenant_subscriptions          (BelongsToTenant)
  id, tenant_id, plan_id,
  status (trialing|active|grace|suspended|cancelled),
  razorpay_subscription_id,
  current_period_start, current_period_end,
  grace_ends_at (nullable), cancelled_at (nullable), timestamps

subscription_invoices         (BelongsToTenant)
  id, tenant_id, tenant_subscription_id,
  plan_id (snapshot at issue time — a later plan change must not rewrite
           a past invoice's plan),
  amount, currency, kind (recurring|proration),
  status (pending|paid|failed),
  razorpay_payment_id (nullable),
  period_start, period_end, paid_at (nullable), timestamps

tenant_storage_usage          (BelongsToTenant, one row per tenant)
  id, tenant_id (unique), bytes_used, timestamps
```

Four new `BelongsToTenant` models (`TenantSubscription`, `SubscriptionInvoice`,
`TenantStorageUsage` — three; `Plan` is deliberately **not** one, per decision 2)
get registered in `TenantModelRegistry::MODELS` at B0, with full
`seedAttributes()`/`updateAttributes()` — same "register without seed throws
immediately" rule every prior domain-groundwork block has hit.

### 3.2 `TenantPlanLimits` — the one fallback-branch resolver

Single place implementing decision 3's "plan_id set? use plan columns :
use legacy consts" branch, so four different quota classes
(`BandwidthQuota`, `AiSpendCap`, `SeatQuota`, `StorageQuota`) don't each
reimplement it:

```php
class TenantPlanLimits
{
    public function seatLimit(Tenant $tenant): ?int;               // null = unlimited
    public function storageLimitBytes(Tenant $tenant): int;
    public function bandwidthLimitBytesPerDay(Tenant $tenant): int; // replaces BandwidthQuota::CAPS_BYTES lookup
    public function aiSpendCapUsdPerDay(Tenant $tenant): float;     // replaces AiSpendCap::CAPS_USD lookup
}
```

`BandwidthQuota::capFor()`/`AiSpendCap::capFor()` change from a const-array
lookup to `$this->limits->bandwidthLimitBytesPerDay($tenant)` /
`...aiSpendCapUsdPerDay($tenant)` — one-line swaps, existing method
signatures and every existing caller untouched.

### 3.3 Seat + storage enforcement (Q2)

- `SeatQuota::remainingFor($tenant)` — `usedNow()` counts active
  `admin`+`instructor` users on the tenant. Checked in
  `RegisteredUserController` (or wherever tenant-context staff
  registration actually lands — confirmed at build time) before the
  account is created; over-limit registration fails with a clear message,
  not a raw DB error.
- `StorageQuota::remainingFor($tenant)` — reads `tenant_storage_usage`.
  Checked before accepting a new file at each of B4's enumerated call
  sites; `TenantStorageUsage::bytes_used` incremented on successful store,
  decremented on delete (soft-deletes that don't remove the underlying
  file do **not** decrement — matches how S10 B15 already had to reason
  about soft-deleted rows not implying the file is gone).

### 3.4 Subscription lifecycle

```
Super admin sets institute's Plan (TenantResource "Plan" section)
        |
        v
PlanChangeService::subscribe()
  - creates/updates TenantSubscription
  - calls SubscriptionProvider::createSubscription()
        |
        v
Razorpay hosted mandate setup (out of band — institute's own billing
contact completes it; this app never touches card/UPI details)
        |
        v
Webhook (subscription.charged / subscription.completed / payment.failed /
subscription.cancelled) -> RazorpaySubscriptionWebhookController
        |
        v
SubscriptionReconciler
  - verifies signature (same HMAC pattern as PaymentReconciler)
  - updates TenantSubscription.status
  - writes a SubscriptionInvoice row
        |
        v
SuspendOverdueTenantSubscriptions (daily sweep)
  - grace -> suspended once grace_ends_at has passed with no successful
    payment (the one transition no single webhook event can trigger)
```

### 3.5 Testing plan

New/updated test files, one Pest file per unit unless noted:
`TenantPlanLimitsTest`, `BandwidthQuotaTest`/`AiSpendCapTest` (add
plan_id-present cases alongside existing legacy-fallback cases),
`SeatQuotaTest`, `StorageQuotaTest`, `PlanResourceTest` (Super panel CRUD),
`PlanChangeServiceTest` (subscribe + proration math),
`RazorpaySubscriptionProviderTest` (`Http::fake()`, mirrors
`RazorpayProviderTest`'s own shape),
`RazorpaySubscriptionWebhookControllerTest` +
`SubscriptionReconcilerTest`, `SuspendOverdueTenantSubscriptionsTest`,
`BillingOverviewPageTest` (Admin panel read-only view),
`TenantResourcePlanSectionTest`. `CrossTenantTest`'s generated dataset
gains 3 entries (one per newly-registered model) at B0.

Rough estimate: ~55-65 new tests, bringing the suite from 804/805 to
roughly 860-870, in line with S10's own ~50-new-test actual (which beat
its own conservative estimate) for a similarly-sized slice.

---

## 4. What's not real yet, on purpose

- Q6/Q7 (MRR/ARR analytics, revenue export) — deferred, needs real billing
  history first (§2.2).
- No public self-serve plan/checkout page (§2.2, decision 10).
- No multi-currency — INR/Razorpay only, matches Fees domain's current
  scope exactly.
- Proration is next-cycle invoice-line-item only, not a live mid-cycle
  charge (decision 9) — genuinely deferred, not a bug.
- Storage quota covers only files this app stores directly; Bunny-hosted
  video delivery bytes remain covered by the existing bandwidth quota,
  Bunny origin storage cost is not modeled per-tenant (§2.2).
