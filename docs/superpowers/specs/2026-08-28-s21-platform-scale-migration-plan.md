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
| B0 | V10 pt.1 — `feature_flags` schema + `FeatureFlag` model | done |
| B1 | V10 pt.2 — `FeatureGate` service + middleware enforcement on one real gated route | not started |
| B2 | V10 pt.3 — Admin/Super panel flag toggle UI | not started |
| B3 | T5 pt.1 — `api_keys` schema + issuance/revocation Filament UI | not started |
| B4 | V12b — bearer-token auth (Sanctum) on `/api/v1/`'s existing webhook/health endpoints | not started |
| B5 | T5 pt.2 — `webhook_endpoints`/`webhook_deliveries` schema + HMAC-signed dispatch job | not started |
| B6 | T5 pt.3 — 5-retry delivery logic + delivery log admin UI | not started |
| B7 | V13a — Google Workspace SSO: tenant-configurable allowed domain + domain-restricted OAuth gate | not started |
| B8 | V11 pt.1 — Card EMI: `PaymentProvider::createOrder()` EMI option + eligibility | not started |
| B9 | V11 pt.2 — EMI checkout UI option + webhook/payment-status handling | not started |
| B10 | V14 pt.1 — course/category CSV import (dry-run + per-row error report) | not started |
| B11 | V14 pt.2 — enrolment CSV import | not started |
| B12 | V14 pt.3 — payment-history CSV import (historical backfill, no live gateway calls) | not started |
| B13 | V9 — stateless-readiness audit + `ARCHITECTURE.md`/`DECISIONS.md` update | not started |
| B14 | `TenantModelRegistry` sweep — all 7 new `BelongsToTenant` models confirmed registered | not started |
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

### B1 — V10 pt.2: FeatureGate + first real gate

- [ ] `FeatureGate::enabled(Tenant $tenant, string $key): bool` — the one
      place a flag is ever checked (design doc §4 decision 2), defaults
      `false` for an unset key.
- [ ] Wire it into one real existing route as the proof case — confirm
      the actual call site before this block starts (doubt chat, per
      V10's own FEATURES.md example), not guessed here.
- [ ] Tests: gate blocks when unset, blocks when explicitly `false`,
      allows when `true`, a different tenant's flag never leaks across.

### B2 — V10 pt.3: toggle UI

- [ ] Admin-panel view (read-only: which flags this tenant has) + Super-
      panel resource (grant/revoke per tenant per key) — same "Super
      grants, tenant sees" shape `plan_id`/`TenantPlanLimits` already
      established for a similar platform-controlled-per-tenant value.
- [ ] Tests: institute admin cannot self-grant a flag, Super admin can
      toggle it, list is scoped correctly cross-tenant.

### B3 — T5 pt.1: API key issuance

- [ ] `api_keys` migration + model (Api, new domain), registered in
      `TenantModelRegistry`.
- [ ] Admin-panel issuance action showing the real key exactly once,
      storing only `key_hash` (design doc §4 decision 4); revoke action
      sets `revoked_at`.
- [ ] Tests: key shown once, hash verifies correctly, revoked key fails
      verification, a different tenant's key never verifies against this
      tenant's own records.

### B4 — V12b: bearer-token auth on `/api/v1/`

- [ ] Middleware verifying a bearer token against `api_keys.key_hash`,
      binding the resolved tenant the same way `ResolveTenant` does for
      the subdomain path — confirm exact `/api/v1/` route list before
      this block starts (design doc §4 decision 5: additive to existing
      routes, not a new surface).
- [ ] Tests: valid key passes, missing/invalid/revoked key is refused,
      resolved tenant matches the key's own owner, cross-tenant key never
      grants access to another tenant's endpoint.

### B5 — T5 pt.2: webhook schema + dispatch

- [ ] `webhook_endpoints`/`webhook_deliveries` migrations + models (Api),
      registered in `TenantModelRegistry`.
- [ ] HMAC-signed dispatch job (design doc §4 decision 3: queued,
      at-least-once, never synchronous) — confirm which existing domain
      event(s) trigger a first real webhook before this block starts.
- [ ] Tests: payload signature verifies with the endpoint's own secret, a
      disabled endpoint receives nothing, delivery creates a real
      `webhook_deliveries` row.

### B6 — T5 pt.3: retry + delivery log

- [ ] 5-retry backoff on delivery failure (design doc §4 decision 3),
      `attempt_count`/`status` updated per attempt; delivery log
      admin-panel view.
- [ ] Tests: a failing endpoint retries up to 5 times then marks
      `failed`, a recovering endpoint on a later attempt marks
      `delivered`, the log shows accurate attempt history.

### B7 — V13a: Google Workspace SSO

- [ ] `tenants.google_workspace_domain` migration (nullable, off by
      default).
- [ ] `GoogleOAuthAuthenticator` gains a domain check: when configured,
      only a Google account on that domain may authenticate for this
      tenant (design doc §2.3: no such check exists today).
- [ ] Tests: configured domain allows a matching account, refuses a
      non-matching one, unconfigured tenant behaves byte-identical to
      today.

### B8 — V11 pt.1: Card EMI eligibility + order creation

- [ ] `fee_payments.emi_duration_months` migration (nullable).
- [ ] `PaymentProvider::createOrder()` gains an optional EMI parameter
      (design doc §4 decision 6, not a new interface method) — confirm
      Razorpay's own EMI order-option shape before this block starts.
- [ ] Tests: a non-EMI order is byte-identical to today, an EMI order
      carries the duration through to the created `FeePayment`.

### B9 — V11 pt.2: EMI checkout + webhook handling

- [ ] Checkout UI option to select an EMI duration (reuses the existing
      checkout flow's own structure — confirm exact component before
      this block starts).
- [ ] `PaymentReconciler` correctly captures an EMI payment the same as
      any other (confirm no EMI-specific webhook event exists in
      Razorpay's own docs before assuming one does).
- [ ] Tests: EMI selection flows through to a real order, capture works
      identically to a non-EMI payment.

### B10 — V14 pt.1: course/category import

- [ ] `course_import_batches` migration + model (Content), registered in
      `TenantModelRegistry`.
- [ ] CSV import job reusing `StudentImportBatch`'s own dry-run/
      per-row-error-report shape (design doc §4 decision 7), targeting
      `Course`/`Category`.
- [ ] Tests: dry-run reports row-level errors with zero writes, a real
      run creates courses/categories and skips only the bad rows.

### B11 — V14 pt.2: enrolment import

- [ ] `enrolment_import_batches` migration + model (Batches), registered
      in `TenantModelRegistry`.
- [ ] CSV import job targeting `Enrolment`, same dry-run/error-report
      shape, validating against already-imported (or pre-existing)
      batches/students.
- [ ] Tests: dry-run catches a reference to a non-existent batch/student,
      real run creates valid enrolments only.

### B12 — V14 pt.3: payment-history import

- [ ] `payment_history_import_batches` migration + model (Fees),
      registered in `TenantModelRegistry`.
- [ ] CSV import job creating historical `FeeInvoice`/`FeePayment` rows
      directly (no live gateway call — this is backfilling settled
      history, not processing new payments).
- [ ] Tests: dry-run reports errors with zero writes, real run creates
      invoice/payment pairs correctly, never dispatches a live payment
      event for imported (already-settled) history.

### B13 — V9: stateless-readiness audit

- [ ] Grep every `app/Domains/**` and `app/Http/**` for a local
      filesystem write outside `Storage::disk(...)` (design doc §4
      decision 8) — anchored grep, not a bare substring match, same
      discipline the registry sweep already uses.
- [ ] Fix any real hit found; if none, document that explicitly (don't
      invent a fix for a problem that doesn't exist).
- [ ] Update `ARCHITECTURE.md`/`DECISIONS.md` with the "nothing lives on
      local disk" constraint as a stated, load-bearing rule for anyone
      adding a future feature.

### B14 — `TenantModelRegistry` sweep

- [ ] Reverse sweep (same anchored-grep method S19 B15/S20 B13 used):
      grep every `app/Domains/*/Models/*.php` for a real
      `use BelongsToTenant` declaration, diff against
      `TenantModelRegistry::MODELS`, confirm zero gaps either direction.
      All 7 new S21 models (`FeatureFlag`, `ApiKey`, `WebhookEndpoint`,
      `WebhookDelivery`, `CourseImportBatch`, `EnrolmentImportBatch`,
      `PaymentHistoryImportBatch`) confirmed present with working
      `seedAttributes()`/`updateAttributes()` entries.

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
