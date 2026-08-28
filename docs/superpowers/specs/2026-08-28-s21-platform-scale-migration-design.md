# Design: S21 Platform Scale & Migration

## 1. Why this document exists

Locks scope and architecture for S21 before any block is built, same role
every prior slice's own design doc played. Companion build log:
`2026-08-28-s21-platform-scale-migration-plan.md`.

`docs/ROADMAP.md`'s own S21 row names 5 items (read replicas, horizontal
scaling, per-tenant feature flags, org SSO, data-import tooling), drawing
from `docs/FEATURES.md`'s V6-V15. Same "verify before building" discipline
as every prior slice: **real build scope is 6 items**, not 5 headline
sentences — V12 (mobile API path) actually names two separable
deliverables (bearer auth + T5's own API-keys/webhooks pair), counted here
as its own two items to match how FEATURES.md itself tracks T5 separately.

## 2. Scope

### 2.1 In scope — 6 items

| # | Item | Source | Why now |
|---|---|---|---|
| V9 | Stateless-readiness audit — confirm no request path writes to local disk, document the constraint | REQ (FEATURES.md V9) | Cheap now; expensive to retrofit once a second app server exists |
| V10 | Per-tenant feature flags — flags table + middleware gate + Admin/Super toggle UI | REQ (FEATURES.md V10) | How the product gets sold in tiers at all; today plan differences are hardcoded conditionals |
| T5 | Institute API keys + outbound webhooks (HMAC-signed, 5 retries, delivery log) | REQ-58 | Named as its own line in FEATURES.md; a real institute integration need once the platform has data worth pushing out |
| V12b | Bearer-token auth on `/api/v1/`'s existing webhook/health endpoints | FEATURES.md V12 | `/api/v1/` (T1) has had no caller since Livewire replaced the API's only consumer (V12's own decision log). Bearer auth is what actually lets an institute's own API key (T5) call it |
| V13a | Google Workspace SSO — tenant-configurable allowed domain, domain-restricted Google OAuth | FEATURES.md V13 | Becomes live the first time a school group with its own Workspace evaluates the product |
| V11 | Card EMI through Razorpay, separate from institute instalments | FEATURES.md V11 | Some parents will ask for it; instalments stay the primary model |
| V14 | Data-import tooling — courses, enrolments, payment history from an existing LMS, dry-run + per-row error report | FEATURES.md V14 | F9 (S15) already covers CSV student import; this is the rest of a real institute's own onboarding data |

### 2.2 Out of scope, and why (locked before any code)

- **V6 (SETUP.md currency) is not a slice deliverable.** It is ongoing
  documentation hygiene, not a feature with a schema or an acceptance
  criterion — `SETUP.md` has in fact been kept current throughout S1-S20
  already (every new env var/service this build added a line there). No
  block needed; if it ever drifts, that's a one-line PR, not a slice.
- **V7 (server hardening: SSH, firewall, bcrypt cost) is cut.** This is
  ops/infra provisioning — this repo contains Laravel application code,
  not server-provisioning scripts or infra-as-code (confirmed: no
  Ansible/Terraform/shell-provisioning anywhere in the tree). A different
  kind of work entirely, same "separate product surface" reasoning S20
  used to cut its own R5 (physical store).
- **V8 (read replicas) is cut.** FEATURES.md's own text says "deferred in
  the original spec too... recorded so the query layer tolerates replica
  lag rather than assuming read-after-write" — there is no concrete
  deliverable here, only a posture, and the app's existing
  `lockForUpdate()`-heavy discipline (every approval/payout/seat-race flow
  built in S1-S20) already avoids the sharpest read-after-write traps. Real
  read replicas apply once a single tenant's data actually justifies them
  — not yet true for any institute on this platform.
- **SAML (half of V13) is cut.** No SAML library is installed
  (`composer.json` has no `onelogin/php-saml` or equivalent), no current
  buyer has asked, and a correct SAML SP (XML signing, IdP metadata
  exchange, clock-skew tolerance) is a slice of its own, not a block
  inside this one. Google Workspace SSO ships (V13a); SAML is flagged as
  its own future item, not silently dropped.
- **V15 (Frappe strangler-pattern cutover) stays cut.** FEATURES.md already
  states this as "conditional, not currently scheduled," working
  assumption unchanged: the Frappe instance at `LMS/lms` is an abandoned
  attempt, not a live production system.

### 2.3 Verified before writing this design (checked, not assumed)

- **No feature-flag package installed** (`composer.json` has no
  `laravel/pennant` or similar) — V10 is genuinely greenfield.
- **No SAML package installed** — confirms the SAML cut above isn't just
  convenient, there's nothing to wire even partially.
- **`GoogleOAuthAuthenticator` (S9) has no hosted-domain restriction** —
  confirmed by reading it: it authenticates any Google account, with no
  `hd=` parameter or post-auth domain check. V13a is a real, additive
  gate on an existing flow, not a rebuild.
- **`/api/v1/` exists but has no bearer-token auth and no real caller** —
  matches FEATURES.md's own V12 decision log verbatim; this slice is what
  finally gives it one (an institute's own API key).
- **No `api_keys`/`webhook_endpoints`/`webhook_deliveries` tables or
  models anywhere** — T5 is genuinely greenfield.
- **`StudentImportBatch` (S15, Batches domain) already establishes the
  CSV-dry-run-plus-per-row-error-report UX** — confirmed by reading its
  own service. V14 reuses this exact shape for courses/enrolments/payment
  history rather than inventing a second import UX.
- **No Card EMI integration anywhere in `PaymentProvider`/`RazorpayProvider`**
  — V11 is genuinely greenfield; Razorpay's own Orders API supports EMI as
  an order-creation option (`method: emi` + `emi_duration`), not a
  separate API surface, so this extends the existing order-creation call
  rather than adding a new integration.

## 3. Architecture

### 3.1 Domain ownership

- **`Tenancy`** (extended) — V10 (`feature_flags`, tenant-scoped), V13a
  (Workspace-domain config + OAuth gate). Both are tenant-level
  configuration, `Tenancy`'s existing charter.
- **`Fees`** (extended) — V11 (Card EMI is a payment-time option on the
  existing checkout/order-creation path).
- **`Batches`** (extended) — V14's course/enrolment import jobs, same
  domain `StudentImportBatch` (S15) already owns; **`Content`** (extended)
  for the course/category half specifically, since `Course`/`Category`
  live there; **`Fees`** (extended) for the payment-history import half,
  since `FeeInvoice`/`FeePayment` live there. No single import table spans
  three domains — three parallel import jobs, one per data type, each in
  the domain that owns the target model, same "no new domain for a
  cross-cutting job" posture already used elsewhere.
- **`Api`** (**new domain**) — T5 (`api_keys`, `webhook_endpoints`,
  `webhook_deliveries`) and V12b (bearer-token auth guarding `/api/v1/`).
  Genuinely doesn't fit any existing domain's charter — this is the
  platform's own outward-facing integration surface, not institute
  content, fees, or batch operations, same bar `Growth` (S20) cleared for
  a similarly cross-cutting new concern.
- **V9 is not a domain change** — an audit + a documentation update
  (`ARCHITECTURE.md`/`DECISIONS.md`), no schema, no new model.

### 3.2 New schema

```
-- Tenancy: V10
feature_flags (Tenancy — V10)
  id, tenant_id, key (string, e.g. "doubt_chat", "analytics"), enabled
  (boolean, default false), timestamps
  -- unique (tenant_id, key)

-- Tenancy: V13a (no new table — tenant-level columns, same "single
-- hardcoded value -> one tenant override" shape every prior slice's own
-- single-value tenant config took)
tenants.google_workspace_domain (string, nullable — null = feature off,
  any Google account may sign in, today's existing behavior unchanged)

-- Api: T5
api_keys (Api — T5)
  id, tenant_id, name, key_hash (string, the actual key is shown once at
  creation and never stored in plaintext), last_used_at (nullable),
  revoked_at (nullable), timestamps

webhook_endpoints (Api — T5)
  id, tenant_id, url, secret (HMAC signing secret, shown once), status
  (active|disabled), timestamps

webhook_deliveries (Api — T5)
  id, tenant_id, webhook_endpoint_id, event_type, payload (json),
  status (pending|delivered|failed), attempt_count (default 0),
  last_attempted_at (nullable), delivered_at (nullable), timestamps

-- Fees: V11 (no new table — columns on the existing payment row)
fee_payments.emi_duration_months (int, nullable — null = not an EMI
  payment, today's existing behavior unchanged)

-- Batches/Content/Fees: V14 (reuses StudentImportBatch's own shape,
-- three parallel tables, one per data type, not one shared table —
-- each imports into a different domain's own models with different
-- validation rules)
course_import_batches (Content — V14)
  id, tenant_id, uploaded_by_user_id, status (pending|dry_run|completed|
  failed), total_rows, error_rows, dry_run (boolean), error_report_path
  (nullable), timestamps

enrolment_import_batches (Batches — V14)
  id, tenant_id, uploaded_by_user_id, status, total_rows, error_rows,
  dry_run, error_report_path (nullable), timestamps

payment_history_import_batches (Fees — V14)
  id, tenant_id, uploaded_by_user_id, status, total_rows, error_rows,
  dry_run, error_report_path (nullable), timestamps
```

### 3.3 New `BelongsToTenant` models (all registered in `TenantModelRegistry`)

`FeatureFlag`, `ApiKey`, `WebhookEndpoint`, `WebhookDelivery`,
`CourseImportBatch`, `EnrolmentImportBatch`, `PaymentHistoryImportBatch` —
**7 new models**.

## 4. Design decisions

1. **Feature flags are boolean-per-key, not a JSON blob.** A real
   `(tenant_id, key)` row per flag, not a `settings` JSON column on
   `tenants` — makes "which tenants have X on" a real indexed query
   (needed for the Super panel's own rollout visibility), which a JSON
   blob can't answer without scanning every tenant row.
2. **`FeatureGate::enabled($tenant, $key)` is the one place a flag is
   checked**, same "one class answers this" posture `LessonAccess` already
   established for a different question — call sites never read
   `FeatureFlag` directly. An unset flag defaults to `false` (opt-in,
   never opt-out-by-accident).
3. **Webhook delivery is an at-least-once, retry-with-backoff queued job**,
   not a synchronous HTTP call at the point an event fires — an
   institute's own endpoint being slow or down must never block the
   request that triggered the webhook. 5 retries (T5's own spec), backoff
   matching the existing `FlagOverdueInvoices`/`FeeRefund`-style job
   `$tries`/`backoff()` pattern already used app-wide.
4. **API keys are shown once, stored only as a hash** (`hash('sha256',
   $key)`), same posture this app already takes for nothing-to-lose-if-
   leaked identifiers elsewhere (session tokens) — a leaked database dump
   never yields a usable key.
5. **Bearer-token auth is additive to `/api/v1/`'s existing routes, not a
   parallel API surface.** V12's own decision log already settled that
   `/api/v1/` stays scoped to webhook receivers and health endpoints in
   this phase (the full public REST surface is a later, unscoped item) —
   this slice's job is only to put a real auth check in front of what
   already exists.
6. **Card EMI reuses `PaymentProvider::createOrder()`, not a new
   interface method.** Razorpay's own Orders API takes EMI as order
   options (`method`, `emi_duration`), not a separate endpoint — the
   existing `createOrder(FeeInvoice $invoice, string $idempotencyKey)`
   signature gains an optional EMI parameter rather than a fourth
   interface method the way `refund()` (S20) genuinely needed one.
7. **Course/enrolment/payment-history import are three separate jobs
   reusing one shared shape, not one generic importer.** Each data type
   has different required columns, different validation, and writes to a
   different domain's own models — a single "generic CSV importer"
   would need type-specific branching internally anyway, so three
   thin jobs sharing `StudentImportBatch`'s own dry-run/error-report
   pattern is less code, not more, same reasoning several prior slices'
   own "don't build one shared abstraction for divergent shapes" calls
   took.
8. **The stateless-readiness audit (V9) is a grep sweep + a documented
   constraint, not a refactor.** If it finds a real local-disk write (a
   generated PDF, a cached file) outside the already-external `Storage`
   disk abstraction, that specific call site gets fixed; the audit itself
   doesn't pre-emptively rewrite anything that already routes through
   `Storage::disk()`.

## 5. Testing strategy

- **Unit/feature per service**: `FeatureGate::enabled()` (on/off/unset),
  webhook HMAC signing + retry/backoff, API key hash-and-verify, Workspace
  domain gate (allowed domain, blocked domain, unconfigured tenant
  unchanged), EMI order creation, each of the three import jobs
  (dry-run reports errors without writing, real run writes and skips
  bad rows) — each gets its own test file, same per-service discipline
  every prior slice used.
- **Cross-tenant isolation**: all 7 new `BelongsToTenant` models register
  in `TenantModelRegistry`, auto-generating `CrossTenantTest` coverage.
- **Lifecycle E2E** (final block, same as every prior slice): an institute
  mints an API key, registers a webhook endpoint, an event fires and the
  webhook is delivered with a valid HMAC signature; a tenant enables a
  feature flag and a gated route becomes reachable, a different tenant
  without it stays blocked; a course CSV dry-run reports errors with zero
  writes, then a real run imports correctly.
