# Design: S20 Growth & Billing Extensions

## 1. Why this document exists

Locks scope and architecture for S20 before any block is built, same role
S9-S19's own design docs played. Full block-by-block build log lives in the
companion `2026-08-27-s20-growth-billing-extensions-plan.md`.

`docs/ROADMAP.md`'s own S20 row names 12 items (R1-R6, P6/P8-P10, Q6-Q7).
Same "verify before building" discipline as every prior slice: **real build
scope is 9 items**, not 12 (§2.2) — 3 are cut, 1 already shipped, 1 folded
into another.

## 2. Scope

### 2.1 In scope — 9 items

| # | Item | Source | Phase |
|---|---|---|---|
| P6 | Refunds within institute-configured window | REQ-16 | P2 |
| P8 | Grace period → access restriction on non-payment | NEW | P2 |
| P9 | Student subscription/membership plans, trials, proration | REQ-61 | P3 |
| P10 | Multi-currency + regional tax rules (gateway layer already pluggable) | REQ-62 | P3 |
| Q6 | MRR/ARR, per-institute revenue, success/failure rates | REQ-18 | P3 |
| R1 | Affiliate/referral codes, commission rules, reversal on refund | REQ-63 | P3 |
| R2 | Affiliate dashboard + payout requests | REQ-63 | P3 |
| R3 | Instructor payouts: salaried or revenue-share, approval workflow | REQ-64 | P3 |
| R6 | Upcoming-course announcements / pre-launch interest capture | INF, FRP | P3 |

`docs/ROADMAP.md`'s own S20 headline names all of R1-R6/P6/P8-P10/Q6-Q7 —
this design keeps every headline-named item that survives verification below,
same "headline explicitly names it, it stays" precedent S16/S19 both used for
their own P3-tier items.

### 2.2 Out of scope, and why (locked before any code, not discovered mid-build)

- **Q7 (revenue export CSV/PDF as background job) is not part of this
  slice — it already shipped.** `app/Domains/Comms/Jobs/ExportRevenueRegister.php`
  + `App\Filament\Admin\Pages\RevenueExport` + `DownloadRevenueExportController`
  (S8/S9, confirmed by reading all three) already generate and deliver a
  per-tenant revenue register CSV on demand. It answers a different question
  than Q6 though (a tenant's own raw payment ledger, not cross-tenant
  MRR/ARR/success-rate aggregates for the Super panel) — not a duplicate,
  just already-done, so it's dropped from this slice's own build list
  rather than re-scoped.
- **R4 (cashback, gift, registration bonus) is cut.** `INF`-tagged only, no
  `REQ` number, and — same "too broad to build against without its own
  scoping pass" reasoning L15 (AI content-generation assist) got cut for in
  S17 — "cashback, gift, registration bonus" names three different reward
  mechanics with no shared shape yet. `FeeCoupon`'s existing
  `discount_type` (percentage/fixed) already covers the common "give a
  one-time discount" case if a concrete bonus need shows up before this
  gets its own slice.
- **R5 (store for physical study material) is cut.** `INF, MKT`-tagged,
  P3. Same "a whole second audience this platform has never served" cut
  reasoning S15 applied to its own job-board item (§S.S7) — physical
  inventory, shipping, and fulfillment are a genuinely separate product
  surface, not an extension of anything this app currently does.
- **P10's "pluggable gateway layer" is narrowed to multi-currency + tax,
  not a second payment gateway.** `App\Integrations\PaymentProvider`
  (confirmed by reading it) is already an interface — `createOrder()`/
  `verifyWebhookSignature()` — with exactly one driver (Razorpay). It's
  already pluggable in the sense the interface exists; nothing in this
  slice's own scope names a second gateway to plug in (no Stripe/PayPal
  requirement anywhere in `docs/FEATURES.md`), so building a second driver
  nobody asked for is out. The real, verified gap is currency and tax:
  every money column in `Fees`/`Billing` is implicitly INR
  (`Plan.price_inr_per_month`, `FeeInvoice.amount`/`gst_rate`/`gst_amount`
  — no `currency` column anywhere, confirmed by reading both models), and
  GST is hardcoded rather than a configurable regional tax rule.

### 2.3 Verified before writing this design (grepped/read, not assumed)

- **No refund concept exists anywhere.** Grepped `Refund` across
  `app/Domains/Fees` and `app/Domains/Billing` — zero matches. `P6` is
  genuinely greenfield, not a misconfigured existing flow.
- **No fee-based access gate exists.** `App\Domains\Content\Services\LessonAccess`
  (the one class CLAUDE.md says answers "may this viewer see this lesson,"
  confirmed by reading it) has zero payment/invoice-status logic — access
  today is enrolment-status-only. `P8` needs a genuinely new check, not a
  tweak to an existing one.
- **`Q4`'s "7-day grace period → suspension" (already shipped, S11) is a
  different mechanism than `P8`.** `TenantSubscription.grace_ends_at`
  gates the *institute's* own platform subscription (Billing, B2B); `P8`
  gates an individual *student's* access to a batch/course over their own
  unpaid fee instalments (Fees, B2C) — same "grace period" word, two
  different payers and two different things that get restricted. Confirmed
  no student-level equivalent exists by reading `LessonAccess` and
  `Enrolment`.
- **No affiliate, payout, or instructor-payment concept exists.** Grepped
  `Affiliate|Payout|Commission` across all of `app/` — zero matches. `R1`,
  `R2`, and `R3` are genuinely greenfield, same as S19's own
  survey/help-desk/gamification research pass found for that slice.
- **`FeeCoupon` already has `discount_type`/`discount_value`/`usage_limit`/
  `expires_at`** (confirmed by reading it) — the shape `R1`'s own
  affiliate-code catalog and `P9`'s trial mechanics can both borrow from
  (a code with a discount and a usage limit is not a new primitive), even
  though affiliate codes track commission/attribution rather than a
  discount and need their own table.
- **`Plan`/`TenantSubscription` (Billing, S11) are platform-tier — no
  `tenant_id`** (confirmed: `Plan::$fillable` has no `tenant_id`, it's the
  platform's own global tier catalog). `P9`'s student membership plans are
  each *institute's own* offering to *their own* students — a distinct,
  tenant-scoped catalog, not an extension of `Plan`.

## 3. Architecture

### 3.1 Domain ownership — one new domain

- **`Fees`** (extended, no new domain) — `P6` (refunds), `P8` (access
  restriction), `P9` (student subscriptions), `P10` (currency/tax). All
  four are student↔institute money, `Fees`' existing charter
  (`FeeInvoice`/`FeeCoupon`/`FeePayment`).
- **`Billing`** (extended, no new domain) — `Q6` (MRR/ARR analytics).
  Institute↔platform money, `Billing`'s existing charter
  (`Plan`/`TenantSubscription`/`SubscriptionInvoice`), read-only aggregate
  over data both `Fees` (via `FeePayment`) and `Billing` already write.
- **`Growth`** (**new domain**) — `R1`/`R2` (affiliate program), `R3`
  (instructor payouts), `R6` (course-interest capture). Money and leads
  flowing to a third party who is neither the paying student nor the
  platform — genuinely doesn't fit `Fees`' or `Billing`'s own charter,
  same bar `Planner` (S19) and `Operations` (S15) cleared when introduced.

### 3.2 New schema

```
-- Fees: P6
fee_refunds (Fees — P6)
  id, tenant_id, fee_payment_id, requested_by_user_id, amount, reason,
  status (pending|approved|rejected|processed), gateway_refund_id (nullable),
  processed_at (nullable), timestamps

-- Fees: P8 (no new table — a per-tenant grace-period config value +
-- a computed check, same "absence of a value turns a feature off" shape
-- batch_geofences (S16) already established)
tenants.fee_grace_period_days (int, nullable — null = feature off for that tenant)

-- Fees: P9
student_membership_plans (Fees — P9)
  id, tenant_id, name, price, billing_interval (monthly|yearly),
  trial_days (int, default 0), status (draft|active|archived), timestamps

student_subscriptions (Fees — P9)
  id, tenant_id, user_id, student_membership_plan_id, status
  (trialing|active|past_due|cancelled), trial_ends_at (nullable),
  current_period_start, current_period_end, razorpay_subscription_id
  (nullable), cancelled_at (nullable), timestamps

-- Fees: P10 (no new table — columns on existing rows)
fee_invoices.currency (char(3), default 'INR')
tenants.default_currency (char(3), default 'INR')
tenants.tax_label (string, nullable — e.g. "GST", "VAT"; null = no tax line)
tenants.tax_rate (decimal, nullable — replaces the hardcoded 18% GST rate)

-- Billing: Q6 (no new table — computed at read time from FeePayment +
-- TenantSubscription + SubscriptionInvoice, same posture every other
-- analytics service in this app already takes)

-- Growth: R1
affiliate_codes (Growth — R1)
  id, tenant_id, owner_user_id, code (unique per tenant), commission_type
  (percentage|fixed), commission_value, status (active|disabled), timestamps

affiliate_referrals (Growth — R1)
  id, tenant_id, affiliate_code_id, referred_user_id, fee_payment_id,
  commission_amount, status (pending|confirmed|reversed), timestamps
  -- reversed when fee_payment_id's own refund (P6) is processed

-- Growth: R2
affiliate_payout_requests (Growth — R2)
  id, tenant_id, affiliate_code_id, amount, status
  (requested|approved|paid|rejected), requested_at, decided_at (nullable),
  decided_by (nullable), timestamps

-- Growth: R3
instructor_payouts (Growth — R3)
  id, tenant_id, instructor_id, period_start, period_end, basis
  (salaried|revenue_share), amount, status
  (pending|approved|paid|rejected), approved_by (nullable),
  approved_at (nullable), paid_at (nullable), timestamps

-- Growth: R6
course_interest_signups (Growth — R6)
  id, tenant_id, course_id, name, email, phone (nullable), notified_at
  (nullable), timestamps
  -- unique (course_id, email): one signup per prospect per course
```

### 3.3 New `BelongsToTenant` models (all registered in `TenantModelRegistry`)

`FeeRefund`, `StudentMembershipPlan`, `StudentSubscription`,
`AffiliateCode`, `AffiliateReferral`, `AffiliatePayoutRequest`,
`InstructorPayout`, `CourseInterestSignup` — **8 new models**.

## 4. Design decisions

1. **Refunds are a request/approval workflow, not an instant self-service
   reversal.** A student or staff member requests a refund
   (`fee_refunds`, `status: pending`); an institute admin approves or
   rejects it; approval calls `PaymentProvider`'s new `refund()` method
   (gains a fourth method alongside `createOrder()`/
   `verifyWebhookSignature()`) against the original `FeePayment`'s own
   gateway payment id, then marks `processed`. Same "flag + admin action"
   shape H13 (S16, substitute-teacher workflow) already established for a
   similarly staff-gated action — a refund is real money leaving the
   institute's account, not something a click should trigger unattended.
2. **P8's access restriction is enrolment-level, not account-level.** A
   student with an overdue instalment past their tenant's own
   `fee_grace_period_days` loses access to *that batch's own* lessons
   (`LessonAccess` gains one new check), not their whole account — a
   student can be current on one course and overdue on another
   simultaneously (multiple `Enrolment` rows), and locking the whole
   account would block them from even seeing why. `fee_grace_period_days`
   is nullable and defaults to off, same reasoning
   `at_risk_threshold_percent` (S18) took for an institute-tunable
   value that shouldn't retroactively change behavior for every tenant
   that never configured it.
3. **P9's student subscriptions are a second, parallel payment shape to
   the existing instalment-plan `FeeInvoice` flow, not a replacement.** An
   instalment plan (P3, already shipped) is fixed-schedule, fixed-total,
   tied to one course/batch enrolment; a membership subscription is
   open-ended, recurring, and grants access across whatever the
   institute's plan actually bundles (this slice ships single-tenant
   membership access, not a specific bundle-of-courses model — that's a
   future refinement once a real institute defines what a membership
   actually unlocks for them). Both write to `FeePayment` for the actual
   money movement, so `Q6`'s revenue analytics reads one table regardless
   of which flow produced the payment.
4. **P10's tax handling generalizes the existing GST-specific columns
   rather than replacing them.** `FeeInvoice.gst_rate`/`gst_amount` stay
   (every existing invoice already has real GST data) — `tenants.tax_rate`/
   `tax_label` is the new, institute-configurable value future invoices
   read instead of a hardcoded 18%, with `tax_label` doubling as the
   line-item name on the generated PDF ("GST" today, "VAT" or nothing for
   a future non-Indian tenant). No new tax-rule *engine* — one rate, one
   label, per tenant, same "single hardcoded value → one tenant-level
   override" starting point H10 (S16) took before this slice generalizes
   it further.
5. **Q6 is Super-panel-only, computed at read time, no rollup table** —
   same "no materialized aggregate, computed at read time" posture
   `TestSeriesRankingService` (S16) and `LeaderboardService` (S19)
   already established. MRR = sum of active `TenantSubscription`s' own
   `Plan.price_inr_per_month` (converted via `P10`'s currency work where
   relevant); ARR = MRR × 12; success/failure rate reads `FeePayment`'s
   own status column, not a separate metrics table.
6. **Affiliate commission reverses automatically when the underlying
   payment is refunded, never silently stays paid out.** `AffiliateReferral.status`
   flips `confirmed` → `reversed` the moment its own `fee_payment_id`'s
   `FeeRefund` (§ decision 1) reaches `processed` — an event listener on
   the refund's own completion, not a manual step an admin has to
   remember, same "domains talk through events only" rule CLAUDE.md
   states as load-bearing.
7. **Instructor payouts compute the amount, they don't move money.**
   `basis: salaried` is a flat configured amount per period;
   `basis: revenue_share` sums the instructor's own batches' `FeePayment`
   rows for the period × their configured share percentage. Marking a
   payout `paid` is a manual admin action recording that the transfer
   happened outside this app (bank transfer, UPI, whatever the institute
   actually uses) — same "flag + admin action, not full automation"
   posture decision 1 already takes for refunds, and the same one H13
   (S16) took for substitute-teacher scheduling: this app has no bank
   integration to actually move money to a person, so it doesn't pretend
   to.
8. **Course-interest capture is a public, unauthenticated form** — a
   prospect isn't a user yet, so `course_interest_signups` takes a bare
   name/email/phone, no `user_id`. Same "guest sees a log-in prompt inside
   the component" pattern doesn't apply here since there's deliberately no
   login at all; closer to `ParentInviteRedemption`'s own "an outside party
   with no account yet" shape.

## 5. Testing strategy

- **Unit/feature per service**: refund eligibility + approval flow, the
  `LessonAccess` grace-period check, subscription trial/proration math,
  affiliate commission calculation + reversal-on-refund, instructor
  revenue-share computation, Q6's MRR/ARR aggregation — each gets a
  dedicated test file, same per-service discipline every prior slice used.
- **Cross-tenant isolation**: all 8 new `BelongsToTenant` models register
  in `TenantModelRegistry`, auto-generating `CrossTenantTest` coverage.
- **Lifecycle E2E** (final block, same as every prior slice): a student's
  overdue instalment restricts their batch access, they pay, access
  restores; a refund request → approval → affiliate commission reversal,
  chained in one run; an instructor's revenue-share payout computed
  correctly against real fee payments, approved, marked paid.
