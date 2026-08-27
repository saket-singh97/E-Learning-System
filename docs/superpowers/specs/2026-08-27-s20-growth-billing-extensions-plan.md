# Implementation Plan: S20 Growth & Billing Extensions

Full design: `2026-08-27-s20-growth-billing-extensions-design.md`. This is
the block-by-block build log — update the PROGRESS table as each block
lands, same as every S1-S19 plan did. 15 blocks (B0-B14), finer-grained than
the 9-item real build scope since P6/P9/R1 each split into two independent
blocks, plus a registry sweep and a final review block.

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-27-s20-growth-billing-extensions-design.md and
docs/superpowers/specs/2026-08-27-s20-growth-billing-extensions-plan.md, then build
Block 0 of the S20 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | P6 pt.1 — `fee_refunds` schema + refund-eligibility service (institute-configured window) | ✅ done |
| B1 | P6 pt.2 — staff approval Filament action + `PaymentProvider::refund()` + gateway call | ✅ done |
| B2 | P8 — `tenants.fee_grace_period_days` + `LessonAccess` overdue-instalment gate, enrolment-scoped | ✅ done |
| B3 | P9 pt.1 — `student_membership_plans`/`student_subscriptions` schema + institute-side plan builder | ✅ done |
| B4 | P9 pt.2 — student checkout/trial flow + Razorpay recurring subscription + proration on upgrade | ✅ done |
| B5 | P10 — multi-currency columns + tenant-configurable tax rate/label, generalizing the hardcoded GST fields | ✅ done |
| B6 | Q6 — Super-panel MRR/ARR + per-institute revenue + payment success/failure rate widget | ✅ done |
| B7 | R1 pt.1 — `Growth` domain scaffold + `affiliate_codes`/`affiliate_referrals` schema + code generation + attribution at payment time | ✅ done |
| B8 | R1 pt.2 — commission calculation + reversal-on-refund listener (fires off B1's own refund completion) | not started |
| B9 | R2 — affiliate-facing earnings dashboard + `affiliate_payout_requests` flow | not started |
| B10 | R3 pt.1 — `instructor_payouts` schema + admin approval workflow (salaried basis) | not started |
| B11 | R3 pt.2 — revenue-share computation against real `FeePayment` rows + mark-paid action | not started |
| B12 | R6 — public course-interest capture form + `course_interest_signups` + admin lead list | not started |
| B13 | `TenantModelRegistry` sweep — all 8 new `BelongsToTenant` models confirmed registered | not started |
| B14 | Full test coverage, lifecycle E2E, review pass, `PROJECT-STATUS.md`/`HANDOVER.md`/`ROADMAP.md` update | not started |

**Test suite baseline:** 1718+/1719+ passing at slice start per S19's own
final count (1 pre-existing, unrelated `CourseSlugUniquenessTest` failure
carried since S1/S2, out of scope here same as every prior slice).

---

## Block detail (starting checklist — expand into a build narrative as each block actually lands, same as every prior plan file)

### B0 — P6 pt.1: refund schema + eligibility — DONE

- [x] `fee_refunds` migration + `FeeRefund` model (Fees), registered in
      `TenantModelRegistry`.
- [x] `tenants.refund_window_days` (nullable — null = institute hasn't
      configured a window, same absence-turns-it-off shape `fee_grace_period_days`
      (B2) will use).
- [x] `RefundEligibilityService` — a `FeePayment` is refundable only within
      its own tenant's configured window from `paid_at`.
- [x] Tests: eligible within window, refused past it, refused when the
      tenant never configured a window at all.

Built as designed, no deviations. `paid_at` lives on `FeeInvoice` (not
`FeePayment` — confirmed no such column exists on `FeePayment` before
writing this), so eligibility reads `$payment->invoice->paid_at`; a
payment whose invoice was never marked paid is refused rather than treated
as always-in-window (correctness case beyond the plan's own 3, covered by
a 4th test). `RefundEligibilityService` loads the `Tenant` row directly by
`$payment->tenant_id` rather than trusting ambient `CurrentTenant`, since
B1's future approval action and any queued job calling this later may run
without tenant context bound. Full suite: 1722/1725 passing — 3 failures,
all pre-existing and unrelated (`CourseSlugUniquenessTest`, carried since
S1/S2; two `DownloadCompletionExportControllerTest`/
`DownloadEnrolmentExportControllerTest` cases that are order-dependent
storage-pollution flakes already flagged in S19 B16 — both pass in
isolation, confirmed before marking this block done).

### B1 — P6 pt.2: approval + gateway call — DONE

- [x] `PaymentProvider::refund()` (new interface method) + Razorpay driver
      implementation.
- [x] Staff-facing request/approve/reject Filament actions on a
      `FeeRefundResource` (or an existing invoice/payment resource's own
      relation manager — decide against the real `FeePayment` UI once this
      block starts, not guessed here).
- [x] Approval calls `RefundEligibilityService` again (client-settable
      state re-verified at the point of action, same discipline every
      prior slice's own review passes have had to add after the fact —
      built in from B1's first draft this time).
- [x] Tests: approval processes the refund and updates status; rejection
      leaves the original payment untouched; an admin can't approve a
      refund past the eligibility window even if the request itself was
      raised inside it.

Went with a standalone `FeeRefundResource` (request/approve/reject queue),
not a relation manager on `FeePaymentResource` — a flat admin queue of
open requests reads more naturally than one buried per-payment (same shape
`MessageReportResource` established for a similar "flag, staff decides"
resource). Endpoint/header confirmed against Razorpay's own Refunds API
docs before writing, not assumed: `POST /v1/payments/:id/refund`,
idempotency via the `X-Refund-Idempotency` header (distinct from Orders,
which has no header and relies on its own `receipt` field instead — the
two APIs differ here, so `createOrder()`'s own idiom wasn't just copied
over blind). `CircuitBreakerGuardedPaymentDriver` wraps `refund()` too,
same as `createOrder()` — both are real outbound vendor calls. Approval and
rejection both go through one `RefundApprovalService` that
`lockForUpdate()`s the `FeeRefund` row and re-checks `status === 'pending'`
before acting, closing the "two approvals of the same request" race B14's
own checklist flags as an expected finding — built in now rather than
patched after a future review pass, with its own test. A single `approve`
action carries a request straight from `pending` to `processed` (calling
the gateway synchronously), matching design doc §4 decision 1's own
wording; `approved` stays a defined status on the model without being a
separate step, since nothing in this block's scope needs an
approved-but-not-yet-processed intermediate state. Fixed a pre-existing
anonymous `PaymentProvider` implementation in
`tests/Feature/Support/CircuitBreakerGuardedPaymentDriverTest.php` that
would have hit a fatal "must implement refund()" once the interface grew a
new method — added `refund()` to it plus a breaker-trip test for the new
method, mirroring the existing `createOrder()` coverage. Full suite:
1727/1730 — same 3 pre-existing/unrelated failures as B0's run
(`CourseSlugUniquenessTest`, plus the two order-dependent export-controller
storage-pollution flakes), no new failures.

### B2 — P8: grace period access restriction — DONE

- [x] `tenants.fee_grace_period_days` migration.
- [x] `LessonAccess` gains one new check: an overdue `FeeInvoice` past the
      tenant's own grace period blocks access to that invoice's own
      batch/course only (design doc §4 decision 2) — every other check
      (enrolment status, publish state) stays exactly as-is.
- [x] Tests: access blocked past grace period, access fine within it,
      access fine when the tenant never configured a grace period, a
      student blocked on one batch still has access to a different batch
      they're current on.

The new gate lives inside `canStream()` only — `isVisibleTo()`/
`isCourseVisibleTo()` (syllabus/title visibility) are untouched, so an
overdue student can still see the course exists and why, just not stream
it, matching design doc §4 decision 2's own "not their whole account"
framing. Checked directly against `FeeInvoice` (`status not in
[paid,cancelled]` and `due_date` older than the grace cutoff), not against
the `overdue` status flag `FlagOverdueInvoices` writes — that sweep runs
on its own schedule, and gating on its output would let access lag behind
the real due date by however long the sweep hasn't run yet. Resolves the
grace window from the lesson's own `tenant_id` directly (`Tenant::find`),
not ambient `CurrentTenant`, same reason `RefundEligibilityService`
(B0) already made that call — this runs on stream/watch requests, which
per CLAUDE.md's own tenant-context note always have `CurrentTenant` bound
via the lenient `ResolveTenant` pass, but there's no reason for a pure
lookup like this one to depend on that being true. Enrolment-scoped via a
`whereHas('batch', ...course_id...)` join, not account-wide — the 4th test
seeds two separate courses/batches/enrolments for the same student and
confirms the overdue one blocks while the current one doesn't. Full suite:
1731/1734 — same 3 pre-existing/unrelated failures as B0/B1's runs, no new
failures.

### B3 — P9 pt.1: membership schema + builder — DONE

- [x] `student_membership_plans`/`student_subscriptions` migrations +
      models (Fees), registered in `TenantModelRegistry`.
- [x] Admin-panel plan builder (name/price/billing interval/trial days),
      same resource shape `SurveyResource` (S19) established for an
      institute authoring its own catalog.
- [x] Tests: creates a plan with each billing interval, lists only the
      current tenant's own plans.

`student_subscriptions` gets its migration + model + registry entry now
(FK target for B4's own checkout flow to write into) but no Filament
resource yet — B3's own checklist only names a plan *builder*, and there's
nothing for staff to author on a subscription row before B4 exists to
create any. `StudentMembershipPlanResource` mirrors `SurveyResource`'s
shape exactly: plain CRUD, tenant-scoped list via the existing
`BelongsToTenant` global scope (no manual `where tenant_id` needed — the
tenant-isolation test just confirms the scope actually holds for this new
model, same as every other resource's own list test does). Full suite:
1736/1739 — same 3 pre-existing/unrelated failures as B0-B2's runs, no new
failures.

### B4 — P9 pt.2: checkout + recurring billing — DONE

- [x] Student-facing checkout (trial start or immediate first payment) +
      Razorpay recurring subscription wiring, reusing `PaymentProvider`
      where its existing shape fits.
- [x] Proration calculation on plan upgrade/downgrade mid-cycle.
- [x] Tests: trial starts with no charge, subscription activates on first
      successful payment, proration math on an upgrade matches a known
      worked example, a cancelled subscription doesn't renew.

Reused `PaymentProvider`'s existing Orders-API shape wholesale rather than
Billing's separate `SubscriptionProvider` (Razorpay's native Subscriptions
API, mandate-based) — that interface is hard-typed to `TenantSubscription`/
`Plan` (Billing, B2B), and design doc §4 decision 3 already settled that a
student membership is "a second, parallel payment shape to the existing
instalment-plan `FeeInvoice` flow" that "both write to `FeePayment`."
Concretely: added `FeeInvoiceService::invoiceForMembership()` (mirrors
`invoiceForProgram()` exactly, one more nullable routing column on
`fee_invoices` — `student_membership_plan_id`, same "no single batch/
enrolment" shape `program_id` already established) and reused
`createPaymentOrder()` completely unchanged. A `StudentSubscription` row is
created/activated by `StudentSubscriptionService::activateFromPayment()`,
called directly from `PaymentReconciler`'s own capture path — not via an
event, since both classes live in Fees and CLAUDE.md's "events only" rule
is about crossing a *domain* boundary, which this never does (unlike the
Program→Content branch right next to it, which genuinely does and rightly
stays event-driven). `activateFromPayment()` uses `firstOrNew` keyed on
(user, plan), so a trial converting to paid activates the same row instead
of creating a duplicate — covered by its own test. Proration is the
standard unused-credit-on-old-plan-offset-by-new-plan-share-of-remaining-days
formula, computed in `calculateProration()` and verified against a
hand-worked 30-day-period/10-days-remaining/₹300→₹900 example
(expected ₹200). `changePlan()` applies the new plan_id and returns the
computed amount but does not yet raise a further invoice for it — actually
charging/crediting the proration delta is noted as a fast-follow, not
silently dropped, since nothing in this block's own checklist names it and
Razorpay's native subscription-proration path was already ruled out above.
`renew()` exists specifically to give "a cancelled subscription doesn't
renew" a real method to guard rather than an assumption — it is not yet
wired to a scheduled job; automating the actual renewal cadence is a
fast-follow the same way. Full suite: 1741/1744 — same 3 pre-existing/
unrelated failures as B0-B3's runs, no new failures.

### B5 — P10: multi-currency + tax generalization — DONE

- [x] `fee_invoices.currency`/`tenants.default_currency` migrations
      (default `'INR'` — zero behavior change for every existing tenant).
- [x] `tenants.tax_rate`/`tax_label` migrations, `FeeInvoiceService` (or
      wherever GST is currently computed — confirm the exact call site
      before this block starts) reads the tenant's own configured rate
      instead of a hardcoded percentage, falling back to the existing GST
      default when unconfigured.
- [x] Tests: an invoice generated for a tenant with a configured non-INR
      currency/non-GST tax label renders correctly; an unconfigured tenant
      behaves byte-identical to today.

Confirmed the exact GST call sites before touching anything (not guessed):
`FeeInvoiceService::GST_RATE` was read in exactly three places —
`invoicesFor()`, `invoiceForProgram()`, and B4's own new
`invoiceForMembership()` — all three now call one new
`taxRateFor(Tenant $tenant): float` helper (`$tenant->tax_rate ??
self::GST_RATE`) instead of the constant directly, and all three now also
stamp `currency` from `$tenant->default_currency`. `gst_rate`/`gst_amount`
column names are untouched per design doc §4 decision 4 — they still hold
whatever tax rate actually applied, just sourced from the tenant now
instead of a hardcoded literal. `resources/views/fees/invoice.blade.php`
(the actual rendered tax invoice a student/admin sees — confirmed this is
what "renders correctly" means here, not the internal Filament list
columns, which stay INR-formatted as a deliberate scope cut: nothing in
this block's checklist names them, and generalizing every internal amount
column across the app is a different, much bigger task than P10's own
"an institute can configure its own currency/tax" scope) now reads
`$tenant->tax_label ?? 'GST'` for the line-item name and a new
`FeeInvoice::currencySymbol()` helper (small `CURRENCY_SYMBOLS` map,
INR/USD/EUR/GBP, falls back to `"{code} "` for anything else) instead of a
hardcoded ₹ in all four places it appeared. `Tenant`/`FeeInvoice` both gained
an in-memory `$attributes` default for their own new `currency`-ish column
(mirroring the existing `gamification_leaderboard_enabled` fix) — Eloquent
doesn't re-fetch a server-side column default after `create()`, so a
freshly-created instance would otherwise read the new column as `null`
until the next query. Full suite: 1743/1747 — the same 3 pre-existing/
unrelated failures as B0-B4's runs, plus one newly-surfaced one,
`BackupConfigurationTest::it_does_not_zip_up_its_own_previous_archive_on_a_second_run`
— confirmed it passes in isolation (order-dependent storage-accumulation
flake, same class as the two `DownloadCompletionExportControllerTest`/
`DownloadEnrolmentExportControllerTest` cases already carried; nothing in
this block's diff touches backups) before marking this block done.

### B6 — Q6: platform revenue analytics — DONE

- [x] Super-panel widget/page: MRR (sum of active `TenantSubscription`s'
      own plan price), ARR (MRR × 12), per-institute revenue breakdown,
      `FeePayment` success/failure rate — computed at read time (design
      doc §4 decision 5).
- [x] Tests: MRR/ARR match a known set of active subscriptions, a
      cancelled subscription doesn't count, success/failure rate matches a
      known mix of `FeePayment` statuses.

`PlatformRevenueAnalytics` (Billing) owns all three figures, each computed
at read time with no rollup table, same posture `TestSeriesRankingService`/
`LeaderboardService` already established. Every query explicitly calls
`withoutGlobalScope('tenant')` rather than leaning on the Super panel's own
`CurrentTenant` being unbound — `InstituteSubscriptions` (S11 B12) already
paid for that lesson twice (S9 B10, S10 B13 both shipped a cross-tenant
read relying on ambient state first, caught only in a later review pass);
built in from this block's own first draft instead, and a dedicated test
proves MRR is unaffected by whichever tenant happens to be bound at query
time. `perInstituteRevenue()` groups by **(tenant, currency)**, never
summing two institutes or two currencies into one blended figure — the
proactive fix for the exact "currency-mixing in Q6's aggregation" bug class
B14's own checklist names as an expected finding, verified by its own test
seeding one INR institute and one USD institute. MRR/ARR read `Plan.
price_inr_per_month` directly with no currency conversion at all — `Plan`
is the platform's own INR-only tier catalog (design doc §2.3), so P10's
currency work has nothing to convert there; the note in the plan about
"converting via P10's currency work where relevant" turned out to apply
only to the per-institute figures, not MRR/ARR itself, confirmed by
re-reading `Plan`'s own schema before assuming otherwise. Success/failure
rate reads `FeePayment.status` directly (`captured` vs `failed` only —
`created` is still in flight, `refunded` reflects a later reversal of an
already-settled outcome, not the original attempt's own verdict). Landed as
a new `App\Filament\Pages\PlatformRevenue` page (Super panel's own
`app/Filament/Pages/` home, same directory `InstituteSubscriptions` already
uses despite the naming mismatch with Admin/Teach's own convention) —
`Placeholder`-based, no interactive Filament table, since
`perInstituteRevenue()` returns a plain array rather than an Eloquent
query; a static HTML table (same `HtmlString` idiom `BillingOverview::
usageBar()` already established) renders it instead of forcing Filament's
table component onto a non-Eloquent-backed dataset. Full suite: 1752/1753
— only the one long-standing `CourseSlugUniquenessTest` failure (carried
since S1/S2); the storage-pollution flakes B5's own writeup noted didn't
reproduce this run, consistent with them being timing/order-dependent
rather than something this block's diff touches.

### B7 — R1 pt.1: Growth domain + affiliate schema — DONE

- [x] New `app/Domains/Growth/` domain — `affiliate_codes`/
      `affiliate_referrals` migrations + models, registered in
      `TenantModelRegistry`.
- [x] Code generation (unique per tenant) + attribution: a referred
      signup/payment records which affiliate code was used at the point of
      payment, same "single write chokepoint" discipline `ForumPostComposer`
      (S19) established for its own shared write path.
- [x] Tests: a code attributes a payment to the right affiliate, an
      unrecognized/disabled code attributes nothing, cross-tenant codes
      never collide or leak.

`Growth` gets only `Models/`/`Services/` for now — `Filament/`/`Livewire/`/
`Jobs/`/`Policies/`/`Events/` stay unscaffolded until a later block actually
needs one (R2's dashboard, R3's payouts), same "don't scaffold for later"
posture every prior slice's own domain introduction took. Two services,
split along the same "generation vs. attribution" line the checklist
itself draws: `AffiliateCodeService::createFor()` mints a code
(`ParentInviteCodeService`'s own generator pattern, adapted — unique
**per tenant** here via the DB's `(tenant_id, code)` composite index,
not globally unique the way an invite code is, since two institutes
running separate affiliate programs may legitimately mint the same code
text); `AffiliateAttributionService::attribute()` is the actual
"at the point of payment" chokepoint the checklist names — takes a
`FeePayment` and a raw code string, creates one `pending`
`AffiliateReferral` (`commission_amount` starts at 0; B8 computes the real
figure and confirms it) if the code resolves to an `active` row for the
*current* tenant, returns `null` and writes nothing otherwise. Wiring an
actual code-entry field into the checkout UI is left for whichever block
needs it (not named in this one's own checklist — B7 only names "code
generation + attribution" as a service capability, not a UI). Full suite:
1762/1763 — only the one long-standing `CourseSlugUniquenessTest` failure
(carried since S1/S2); no new failures.

### B8 — R1 pt.2: commission + reversal

- [ ] Commission calculation (percentage or fixed, per `AffiliateCode`'s
      own configured type) on each attributed `AffiliateReferral`.
- [ ] Listener on `FeeRefund`'s own completion (B1) flips a matching
      referral's status to `reversed` (design doc §4 decision 6) — a real
      event, not a manual reconciliation step.
- [ ] Tests: commission computed correctly for both commission types, a
      refunded payment's own referral reverses automatically, an
      unaffiliated payment triggers nothing.

### B9 — R2: affiliate dashboard + payout requests

- [ ] Affiliate-facing Livewire view: their own codes, referral count,
      confirmed vs. reversed commission, running balance.
- [ ] `affiliate_payout_requests` migration + model, registered in
      `TenantModelRegistry` — request/approve/reject, same shape B1's own
      refund-approval flow established.
- [ ] Tests: balance reflects only confirmed (not reversed or pending)
      commission, a payout request can't exceed the available balance, an
      affiliate never sees another affiliate's own numbers.

### B10 — R3 pt.1: instructor payout schema + approval

- [ ] `instructor_payouts` migration + model (Growth), registered in
      `TenantModelRegistry`.
- [ ] Admin-panel approval workflow for a `salaried`-basis payout (fixed
      configured amount per period) — same "flag + admin action" shape
      H13 (S16) established.
- [ ] Tests: a salaried payout computes to exactly its configured amount,
      status walks pending → approved → paid with each action gated on the
      current status.

### B11 — R3 pt.2: revenue-share computation

- [ ] `revenue_share`-basis computation: sums an instructor's own batches'
      `FeePayment` rows for the payout period × their configured share
      percentage (design doc §4 decision 7).
- [ ] Mark-paid action records the transfer happened outside this app —
      no bank integration, by design (§4 decision 7).
- [ ] Tests: revenue-share amount matches a known set of seeded payments
      for one instructor's own batches, a different instructor's payments
      never leak into the calculation, a batch outside the payout period
      is excluded.

### B12 — R6: course-interest capture

- [ ] `course_interest_signups` migration + model (Growth), registered in
      `TenantModelRegistry`.
- [ ] Public, unauthenticated capture form (name/email/phone) on an
      upcoming/unpublished course's own page; admin-panel lead list.
- [ ] Tests: a signup persists correctly, a duplicate email for the same
      course doesn't create a second row (updates instead), a different
      tenant's signups never leak into another tenant's lead list.

### B13 — `TenantModelRegistry` sweep

- [ ] Reverse sweep (same anchored-grep method S19 B15 used, not a bare
      substring match): grep every `app/Domains/*/Models/*.php` for a real
      `use BelongsToTenant` declaration, diff against
      `TenantModelRegistry::MODELS`, confirm zero gaps either direction.
      All 8 new S20 models (`FeeRefund`, `StudentMembershipPlan`,
      `StudentSubscription`, `AffiliateCode`, `AffiliateReferral`,
      `AffiliatePayoutRequest`, `InstructorPayout`, `CourseInterestSignup`)
      confirmed present with working `seedAttributes()`/`updateAttributes()`
      entries.

### B14 — Full test coverage, review pass

- [ ] Lifecycle E2E (design doc §5): overdue instalment blocks batch
      access → payment clears it → access restores, chained in one run; a
      refund request → approval → affiliate commission reversal; an
      instructor's revenue-share payout computed against real fee payments
      end to end.
- [ ] Dispatch a background review agent over the whole slice's diff, same
      discipline every prior slice's final block established — expect
      findings around refund-approval race conditions (two approvals of
      the same request), cross-tenant affiliate/payout visibility, and
      currency-mixing in Q6's aggregation first, same class of bug prior
      slices' own review passes caught.
- [ ] Update `HANDOVER.md` (S20 complete entry), `docs/PROJECT-STATUS.md`
      (new S20 section), `docs/ROADMAP.md` (S20 row → Done, shipped-count
      19→20).
