# Implementation Plan: S12 Security & Resilience Hardening

Full design: `2026-08-17-s12-security-resilience-design.md`. This is the
block-by-block build log — update the PROGRESS table as each block lands,
same as every S1-S11 plan did. 15 blocks (B0-B14), finer-grained than the
11-item roadmap count since several roadmap items (B15's three GDPR
sub-parts, V1's two rollout halves) split cleanly into independent blocks.

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-17-s12-security-resilience-design.md and
docs/superpowers/specs/2026-08-17-s12-security-resilience-plan.md, then build
Block 0 of the S12 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | Domain groundwork — new `Security` domain scaffold, 5 `BelongsToTenant` migrations + models (`device_sessions`, `login_history`, `account_sharing_flags`, `gdpr_export_requests`, `gdpr_deletion_requests`) + 2 non-tenant migrations (`audit_log_entries`, `cookie_consents`), tenant registry | ⬜ not started |
| B1 | Device fingerprinting (`DeviceFingerprintService`) + registered-device limit at login (default 2, config-driven) (B9) | ⬜ not started |
| B2 | Concurrent session limit + remote device logout — Admin/Teach/Super panel "My devices" page (B10) | ⬜ not started |
| B3 | Login history + IP geolocation + impossible-travel detection (`ImpossibleTravelDetector`, velocity check) (B11) | ⬜ not started |
| B4 | Account-sharing detection — daily sweep, forced re-auth on flag (B12) | ⬜ not started |
| B5 | General `audit_log_entries` sink (`AuditLogger`) + wire into the highest-value existing sensitive actions (role assignment, impersonation, plan change, tenant suspension) | ⬜ not started |
| B6 | Audit log 365-day retention sweep (`PruneAuditLogEntries`, monthly) + Super-panel read-only audit log viewer (B13) | ⬜ not started |
| B7 | GDPR/DPDP cookie consent — banner + `cookie_consents` write path, pre-login and post-login reconciliation (B15 pt.1) | ⬜ not started |
| B8 | GDPR/DPDP data export — async job, zip of every domain's rows for one user, signed download link (B15 pt.2) | ⬜ not started |
| B9 | GDPR/DPDP data deletion — anonymize-vs-hard-delete split, 30-day grace window, scheduled sweep (B15 pt.3) | ⬜ not started |
| B10 | Circuit breaker core (`App\Support\Resilience\CircuitBreaker`, Redis-backed) + wire onto Bunny + Claude drivers (V1 pt.1) | ⬜ not started |
| B11 | Circuit breaker wired onto Razorpay (both integrations) + WhatsApp BSP + live-class provider, Sentry alert on trip, dashboard visibility (V1 pt.2) | ⬜ not started |
| B12 | Cache layer + explicit invalidation — `GradebookService::scoreFor()`, `StudentMetrics::summarize()`, `InstituteOverviewWidget` (V2) | ⬜ not started |
| B13 | Soft-delete gap closure — `FeePayment`/`FeeInvoice`/`TenantSubscription`/`SubscriptionInvoice` + admin restore UI on all 4 (V3); Form Request extraction on the 2 remaining inline-`validate()` controllers (V4) | ⬜ not started |
| B14 | Full test coverage, isolation registration sweep, review pass, V5 regression-guard confirmation, `PROJECT-STATUS.md`/`HANDOVER.md` update | ⬜ not started |

**Test suite:** 925/926 at slice start (1 pre-existing, unrelated
`CourseSlugUniquenessTest` failure carried since S1/S2, out of scope here
same as every prior slice). Design doc §5 estimates ~70-85 new tests by
B14 (~995-1010 total) — a rough anchor, not a target; every prior slice's
own estimate undershot its actual by a wide margin.

---

## Block notes (fill in as each block builds — deviations, gotchas,
## decisions made mid-build)

### B0 — Domain groundwork — not started

### B1 — Device fingerprinting + registered-device limit — not started

### B2 — Concurrent session limit + remote device logout — not started

### B3 — Login history + impossible-travel detection — not started

### B4 — Account-sharing detection — not started

### B5 — General audit log sink — not started

### B6 — Audit log retention + viewer — not started

### B7 — GDPR cookie consent — not started

### B8 — GDPR data export — not started

### B9 — GDPR data deletion — not started

### B10 — Circuit breaker core + Bunny/Claude — not started

### B11 — Circuit breaker Razorpay/WhatsApp/live-class + dashboard — not started

### B12 — Cache layer + invalidation — not started

### B13 — Soft-delete gap closure + Form Request extraction — not started

### B14 — Full test coverage, review pass — not started

- Sweep for anything the per-block tests didn't cover end-to-end (a full
  login → device-limit-hit → remote-logout → login-history-flagged chain
  as one integration test, mirroring S7/S11's own precedent for a
  lifecycle E2E test).
- Confirm `TenantModelRegistry` picked up all 5 new `BelongsToTenant`
  models (`device_sessions`, `login_history`, `account_sharing_flags`,
  `gdpr_export_requests`, `gdpr_deletion_requests`) — already enforced by
  B0's own registration, just confirm nothing slipped through.
- V5 regression-guard: re-run `tests/Feature/Security/SecurityBaselineTest.php`
  explicitly and confirm it still passes with zero `DB::raw`/`whereRaw`/
  `DB::statement` introduced by any of B1-B13's own new code.
- Explicit review pass across every B0-B13 file for real bugs, not just a
  green suite — same discipline S9 B14/S10 B15/S11 B16 each already
  established and each already found real, fixed bugs this way.
- Update `HANDOVER.md` (S12 complete entry + any new `## Avoid` entries)
  and `docs/PROJECT-STATUS.md` (new S12 section, matching S1-S11's exact
  format).
