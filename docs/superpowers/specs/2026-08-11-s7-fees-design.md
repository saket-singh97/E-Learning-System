# Design: S7 Fees — AI-Native LMS

Date: 2026-08-11
Status: APPROVED
Scope: Sub-project 7 of 8 in the P1 build (Institute Zero)
Window: 2026-08-11 → 2026-11-14 (demo #7 date)
Depends on: S4 Batches+Enrolment (`Batch.is_paid`/`price` pinned, `BatchEnrolmentService`), S1 Tenancy (`Tenant` model)
Feeds: S8 Comms (WhatsApp channel slots into this slice's reminder Notification once the BSP exists)

---

## 1. Why this document exists

`docs/PROJECT-STATUS.md`'s roadmap commits S7 to one demo on 2026-11-14:
**"Fees collected, an instalment schedule, a reminder firing."** Two things
are already pinned, unused, from earlier slices — the exact shape this app's
prior four slices have all opened with:

- **`Batch.is_paid`/`Batch.price` exist since S1/S4** (`app/Domains/Batches/Models/Batch.php:30-31`)
  — but `BatchEnrolmentService::enrol()` (`app/Domains/Batches/Services/BatchEnrolmentService.php:29-49`)
  never reads either column. A "paid" batch enrols exactly like a free one
  today — verified by reading the method, not assumed.
- **`Tenant` has no GSTIN/legal-name/billing-address fields at all**
  (`app/Domains/Tenancy/Models/Tenant.php`) — needed before any invoice this
  slice issues can be GST-compliant.

## 2. Scope

### 2.1 In scope (P1 — required for the 2026-11-14 demo)

Matches `docs/FEATURES.md` §P's own Phase-P1 rows exactly:

| Feature | FEATURES.md ref | What it means concretely |
|---|---|---|
| Razorpay order + webhook capture, idempotency | P1 | Order created at checkout, webhook verifies signature + dedupes by idempotency key |
| Coupon codes | P2 | Percentage or fixed, usage limit, applied once at invoice creation |
| Instalment plans | P3 | Institute-defined schedule on the batch, materialized into real invoices per enrolment |
| GST-compliant invoices + receipts | P4 | Sequential per-tenant numbering, GSTIN snapshot, printable HTML (not a new PDF dependency) |
| Auto-enrolment on payment + retry on failure | P5 | Enrolment finalizes only once payment captures; a failed attempt can retry against the same invoice |
| Fee defaulter list + reminders | P7 | `/admin` list of overdue invoices; T-3/T-0/T+3 reminder via mail `Notification` (WhatsApp channel is S8's) |

### 2.2 Out of scope, and where each goes

| Feature | FEATURES.md ref | Why deferred | Lands in |
|---|---|---|---|
| Refunds | P6 | Phase-P2, not on Institute Zero's roadmap | Fast-follow |
| Grace period → access restriction | P8 | Phase-P2 | Fast-follow |
| Subscriptions/membership plans | P9 | Phase-P3 | Fast-follow |
| Multi-currency, pluggable gateway | P10 | Phase-P3; INR/Razorpay only for now | Fast-follow |
| B2B platform→institute billing (§Q) | Q1-Q7 | Different concern (institute pays platform), not on the 8-slice roadmap at all | Beyond Institute Zero |
| WhatsApp reminder channel | P7 (partial) | BSP integration is explicitly S8 scope; binding a vendor that doesn't exist yet breaks every prior slice's posture | S8 |
| Real Razorpay account | — | No account exists yet — same posture every prior vendor integration shipped with | Fast-follow |

### 2.3 Reviewed before writing

- `Batch.is_paid`/`price` pinned since S1, never read (§1).
- `BatchEnrolmentService` is the one place enrolment state transitions happen
  (S4 §3.0) — a payment gate must slot into it, not bypass it with parallel logic.
- `Integrations/{AiProvider,VideoProvider,LiveClassProvider}` + `Fake*Driver`
  + a real-but-unbound driver is this app's standing shape for every vendor
  (S2/S3/S5) — `PaymentProvider` follows it exactly.
- `routes/api.php` already exists, registered outside every `Route::domain(...)`
  group, and Laravel's `api` middleware group excludes CSRF verification by
  default — the natural home for a Razorpay webhook, no new middleware config.
- Scheduled, tenant-unbound daily jobs are established precedent (S4 B5, S5 B7,
  S6 B7) — this slice's two sweeps (overdue-flagging, reminders) are two more
  instances, including S6 B7's hard-won lesson: bind `CurrentTenant` explicitly
  per-row before any `BelongsToTenant` model gets created inside the sweep, and
  never let that binding leak across loop iterations.

### 2.4 Decided (2026-08-11, before Block 0)

1. **New `app/Domains/Fees/` domain** (`FeeInvoice`/`FeePayment`/`FeeCoupon`) —
   same domain-per-slice precedent as Assessments (S6).
2. **Payment gates through a new `Enrolment.status = 'pending_payment'`**, not
   a parallel system. A paid batch's `enrol()` creates the enrolment as
   `pending_payment` + one or more `FeeInvoice` rows instead of immediately
   resolving active/waitlisted/pending_approval; that resolution happens only
   once the first invoice's payment captures.
3. **Fees never calls `BatchEnrolmentService` directly** — payment capture
   dispatches `Fees\Events\PaymentCaptured`; a Batches-domain listener
   finalizes the enrolment, reusing `enrolDirect()`'s existing seat-race-safe
   transaction rather than re-implementing seat counting in Fees (CLAUDE.md:
   domains talk through `Events/` only).
4. **Instalments are a JSON template on `Batch` (`instalment_plan`),
   materialized into real `FeeInvoice` rows at enrolment time** — same
   template-then-materialize shape S6 used for `Quiz.blueprint` → drawn
   `Question`s. Only the *first* invoice blocks enrolment; later instalments
   are tracked and reminded, not access-gating (P8's job, deferred).
5. **GST invoice numbering is a per-tenant sequential counter**, resolved
   under `lockForUpdate()` at issue time — same race discipline
   `BatchEnrolmentService` already applies to seat counts. Invoices render as
   printable, GST-field-complete HTML, not a new PDF dependency (matches this
   app's standing "no new library until the demo needs it" posture — video is
   still local disk, not Bunny, for the same reason).
6. **The Razorpay webhook lives in `routes/api.php`**, outside every tenant
   domain group — Razorpay can't route through `{subdomain}.lms.test`. The
   tenant is resolved from the order's own `notes.tenant_id` metadata and
   bound explicitly before any row is touched, mirroring S6 B7's per-row
   `CurrentTenant` bind inside a cross-tenant sweep (including that block's
   own lesson: never let the binding leak across requests/iterations).
7. **Coupon and invoice-sequence races take the same `lockForUpdate()`
   treatment `BatchEnrolmentService` already established** for seats — not a
   new pattern, just a new caller of an existing discipline.

---

## 3. Architecture

### 3.0 Where this lands

```
app/Domains/Fees/
  Models/
    FeeInvoice.php             amount, gst, total, status, due_date, invoice_number
    FeePayment.php              provider order/payment refs, idempotency_key, status
    FeeCoupon.php               code, discount, usage_limit
  Services/
    FeeInvoiceService.php       builds invoice(s) for an enrol() attempt; applies a coupon once
    PaymentReconciler.php       verifies webhook signature, idempotent capture, flips invoice paid
  Jobs/
    FlagOverdueInvoices.php     daily: pending + due_date < today -> overdue
    SendFeeReminders.php        daily: T-3/T-0/T+3 relative to due_date -> FeeReminderDue
  Filament/
    Resources/FeeInvoices/      /admin — browse + defaulter filter (status=overdue)
    Resources/FeeCoupons/       /admin — coupon CRUD
  Events/
    PaymentCaptured.php
  Notifications/
    FeeReminderDue.php          mail channel now; WhatsApp channel added in S8

app/Domains/Batches/
  Listeners/
    FinalizeEnrolmentOnPaymentCaptured.php   listens for Fees\PaymentCaptured

app/Integrations/
  PaymentProvider.php           createOrder(), verifyWebhookSignature()
  Drivers/FakeRazorpayDriver.php  (bound)
  Drivers/RazorpayProvider.php    (real, unbound — no live account yet)

app/Http/Controllers/Fees/
  RazorpayWebhookController.php   POST /api/webhooks/razorpay (routes/api.php)
  DownloadInvoiceController.php   GET /invoices/{invoice} (tenant-scoped, routes/fees.php)

app/Livewire/
  FeeCheckout.php                embeds into the existing batch-join flow (S4 B7) when is_paid
```

`FeeInvoice`/`FeePayment`/`FeeCoupon` read `Batch`/`Enrolment` directly
(cross-domain model read, established precedent) but never call into
`BatchEnrolmentService` — decision 3.

### 3.1 Schema

```sql
ALTER TABLE tenants ADD COLUMN gstin VARCHAR NULL;
ALTER TABLE tenants ADD COLUMN legal_name VARCHAR NULL;
ALTER TABLE tenants ADD COLUMN billing_address TEXT NULL;

ALTER TABLE batches ADD COLUMN instalment_plan JSONB NULL;
-- [{"percent": 50, "days_after_enrolment": 0}, {"percent": 50, "days_after_enrolment": 30}]

-- Enrolment.STATUSES gains 'pending_payment' — PHP-side const only, no migration.

CREATE TABLE fee_invoices (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT NOT NULL REFERENCES tenants(id),
  batch_id BIGINT NOT NULL REFERENCES batches(id),
  enrolment_id BIGINT NOT NULL REFERENCES enrolments(id),
  user_id BIGINT NOT NULL REFERENCES users(id),
  coupon_id BIGINT NULL REFERENCES fee_coupons(id),
  sequence_number BIGINT NOT NULL,      -- per-tenant, gapless
  invoice_number VARCHAR NOT NULL,      -- INV-{tenant}-{FY}-{sequence}
  description VARCHAR NOT NULL,         -- "Full fee" | "Instalment 1 of 2"
  amount DECIMAL(10,2) NOT NULL,        -- pre-tax, post-discount
  gst_rate DECIMAL(5,2) NOT NULL DEFAULT 18.00,
  gst_amount DECIMAL(10,2) NOT NULL,
  total_amount DECIMAL(10,2) NOT NULL,
  discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  due_date DATE NOT NULL,
  status VARCHAR NOT NULL DEFAULT 'pending',   -- pending | paid | overdue | cancelled
  gstin_snapshot VARCHAR NULL,
  paid_at TIMESTAMP NULL,
  timestamps
);

CREATE TABLE fee_payments (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT NOT NULL REFERENCES tenants(id),
  fee_invoice_id BIGINT NOT NULL REFERENCES fee_invoices(id),
  provider VARCHAR NOT NULL DEFAULT 'razorpay',
  provider_order_id VARCHAR NOT NULL,
  provider_payment_id VARCHAR NULL,
  idempotency_key VARCHAR NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  status VARCHAR NOT NULL,              -- created | captured | failed | refunded
  failure_reason VARCHAR NULL,
  webhook_verified_at TIMESTAMP NULL,
  timestamps,
  UNIQUE(idempotency_key)
);

CREATE TABLE fee_coupons (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT NOT NULL REFERENCES tenants(id),
  code VARCHAR NOT NULL,
  discount_type VARCHAR NOT NULL,       -- percentage | fixed
  discount_value DECIMAL(10,2) NOT NULL,
  usage_limit INT NULL,
  used_count INT NOT NULL DEFAULT 0,
  expires_at TIMESTAMP NULL,
  status VARCHAR NOT NULL DEFAULT 'active',   -- active | disabled
  timestamps,
  UNIQUE(tenant_id, code)
);
```

### 3.2 Data flow

```
Student clicks "Join" on a paid batch (existing BatchCatalog, S4 B7)
  -> enrol() sees Batch.is_paid, creates Enrolment(status=pending_payment)
     + FeeInvoiceService builds 1 (full) or N (instalment_plan) FeeInvoice rows
  -> FeeCheckout resolves the first due invoice, PaymentProvider::createOrder()
  -> student pays via Razorpay's client-side checkout

Razorpay POSTs the webhook -> RazorpayWebhookController (routes/api.php)
  -> resolves tenant from order.notes.tenant_id, binds CurrentTenant explicitly
  -> PaymentReconciler verifies signature + idempotency_key, marks FeePayment captured
  -> FeeInvoice.status = paid, paid_at = now()
  -> dispatches Fees\PaymentCaptured
  -> Batches listener finalizes the Enrolment into whatever self_enrol_mode
     would have produced (active/waitlisted/pending_approval), reusing
     enrolDirect()'s existing seat logic

Daily sweeps:
  FlagOverdueInvoices: pending + due_date < today -> overdue
  SendFeeReminders: T-3/T-0/T+3 relative to due_date -> FeeReminderDue (mail)
```

A free batch (`is_paid = false`) is untouched — `enrol()`'s existing behavior
is the default path; the payment gate only engages when `is_paid` is true.

---

## 4. Testing

| Layer | What | Notes |
|---|---|---|
| Unit | Invoice math — coupon discount, GST calc, instalment split | Deterministic fixtures |
| Unit | Invoice-number sequencing under concurrent issuance | Same race shape as seat counting |
| Integration | Full paid-enrol flow: join -> invoice -> webhook -> active enrolment | Mirrors S6's start->submit->grade integration tests |
| Integration | Webhook idempotency — same payload twice, one capture | |
| Integration | Retry after a failed payment reuses the same invoice | |
| Integration | Overdue sweep + reminder firing at T-3/T-0/T+3 | Same shape as S4 B5 / S6 B7's scheduled-job tests |
| Isolation | `FeeInvoice`/`FeePayment`/`FeeCoupon` registered in `TenantModelRegistry` | First registration |
| E2E | Demo script: join paid batch -> pay -> auto-enrol -> invoice downloadable -> reminder fires on an overdue instalment | One test, mirrors S6 B2's tree E2E |

---

## 5. What's not real yet, on purpose

No real Razorpay account (`FakeRazorpayDriver` only, same posture every prior
vendor shipped with). WhatsApp reminders wait on S8's BSP integration — mail
is the real channel for now. Refunds, grace-period access restriction,
subscriptions, and multi-currency are all Phase-P2/P3 in FEATURES.md,
deliberately absent. Invoices are printable HTML, not PDF.

**Demo #7 target: 2026-11-14** — a student joins a paid batch, pays via
Razorpay (fake driver), lands active immediately; an instalment-plan batch
shows a second invoice due later; an overdue instalment fires a reminder.
