# Design: S9 Hardening & Backlog

Date: 2026-08-12
Status: DRAFT — pending owner review before Block 0
Scope: Ninth sub-project — **not part of the original P1 "Institute Zero" roadmap** (S1-S8 closed that roadmap out in full). This is a new, owner-requested slice that picks up the fast-follow backlog every prior slice's design doc explicitly deferred, plus the security gaps three separate review passes (S5, S7, S8) found and flagged-but-didn't-fix.
Window: no external demo commitment (unlike S1-S8, nothing on `docs/PROJECT-STATUS.md`'s roadmap names a date for this) — proposed 2026-08-12 → 2026-08-28, longer than a typical two-week slice because the verified backlog is bigger than any single prior slice's.
Depends on: all of S1-S8 (every block here extends an existing domain; this slice adds no brand-new domain of its own)
Feeds: nothing on a further roadmap yet — anything cut from this slice's own scope table (§2.2) is next slice's (S10+) starting backlog, the same way S1-S8 fed each other

---

## 1. Why this document exists

`docs/PROJECT-STATUS.md` closed S8 with "the whole P1 roadmap is now built." That line is true for the roadmap's own eight demo scripts — it is not true for the roadmap's own paperwork. Every one of S1-S8's design docs carries an "out of scope, and where it goes" table, and most of those rows say "Fast-follow." Nobody had gone back to collect that backlog into a buildable slice until now, and no design doc before this one has cross-checked it against the current code rather than trusting the deferring doc's own wording.

That cross-check (full inventory: a dedicated research pass, not memory) found three categories of finding, in order of how much they matter:

1. **Two real, unfixed multi-tenant security gaps**, both already named once by an earlier review pass and both left as "flagged, not fixed — out of that slice's own scope":
   - `App\Livewire\StudentPortal` has no tenant-mismatch check on its own session (`app/Livewire/StudentPortal.php` — confirmed no `mount()` method exists at all). `SESSION_DOMAIN` is a shared parent domain (`CLAUDE.md`), so a student logged in at tenant A stays authenticated on tenant B's `/dashboard`. `ParentPortal` (S8 B1) got exactly this fix; `StudentPortal` — the surface every enrolled student actually uses daily — still doesn't have it.
   - `LessonAccess::canStream()` (`app/Domains/Content/Services/LessonAccess.php:66-70`) and `Quiz::isVisibleTo()` (`app/Domains/Assessments/Models/Quiz.php:65-69`) both gate on `hasAnyRole(...)` alone, with no `$viewer->tenant_id === CurrentTenant::id()` cross-check — the same vulnerability class `EnsureRole` (S1) and `DownloadInvoiceController` (S7 B7) were each fixed for. S7 B7's own review named this explicitly: "a materially bigger surface than one invoice route... worth a dedicated cross-cutting pass." Confirmed still true by reading both files directly, not by trusting the S7 note's age.
2. **Four features S1's own design doc named, dated, and assigned — "lands in S8" — that S8 never built and no later doc ever mentions again**: TOTP two-factor auth, Google OAuth login, Super Admin impersonation + audit log, custom domain + SSL. S8 shipped Comms in full; none of these four appear in S8's own scope tables. This is a genuinely broken promise, not a soft "someday" — it's the single biggest silently-dropped commitment in the whole backlog.
3. **Everything else**: named features every slice from S2 through S8 deferred with a stated reason (self-service parent linking, lesson content types nobody ever wired up, report catalog completion, production-readiness infrastructure that no slice's demo script ever forced into scope, and a long tail of P2/P3 items this slice explicitly does not attempt — §2.2).

## 2. Scope

### 2.1 In scope (this slice)

| # | Feature | Deferred by | What it means concretely |
|---|---|---|---|
| B0 | Tenant-isolation security parity | S5/S7/S8 reviews (flagged, not fixed) | `StudentPortal` gets `ParentPortal`'s own tenant-mismatch `mount()` check; `LessonAccess::canStream()`/`Quiz::isVisibleTo()` gain the same tenant cross-check `EnsureRole`/`DownloadInvoiceController` already have; two smaller flagged gaps (`canJoinLive()`'s missing course-published check, `myLiveBatchSession()`'s undeterministic `->first()`) close alongside them since they're the same file/review family |
| B1 | TOTP two-factor auth | S1 §3.2 ("lands in S8") | Optional per-user TOTP enrollment + challenge at login, all three panels + the student/parent portals share one `TenantAuthenticator` path |
| B2 | Google OAuth login | S1 §3.2 ("lands in S8") | Sign in with Google, tenant resolved the same way password login already is |
| B3 | Super Admin impersonation + audit log | S1 §3.2 ("lands in S8") | A super_admin can act as any user for support/debugging, every impersonation session start/end audited (same audit-row posture `RoleAssignmentAudit` already established) |
| B4 | Custom domain (verification only; SSL stays a documented manual step) | S1 §3.2 ("lands in S8") | A tenant can add `learn.theirschool.com`, verify it via a DNS TXT record, and `ResolveTenant` recognizes it alongside `*.lms.test` subdomains |
| B5 | Self-service parent linking (invite code) | S8 §2.2 | A parent can claim a student via a one-time code the admin generates, instead of the admin filling in the parent's own details |
| B6 | Lesson content-type completion (PDF/text/embed) + basic content protection | S2 (never revisited) | `pdf`/`text`/`embed` lesson types get real rendering in the syllabus tree (today: dead end, a teacher can select them but nothing shows); `<video>` gets `controlsList="nodownload"` + right-click disabled (D5, never explicitly deferred — just silently never built) |
| B7 | Quiz answer autosave | S6 (`AutoSubmitExpiredQuizAttempts`'s own docblock) | `QuizRunner` persists each answer incrementally, so the auto-submit sweep grades what a student actually answered, not a blank attempt |
| B8 | Fee-checkout live refresh + Razorpay idempotency key | S7 B2/B7 (both flagged) | `FeeCheckout` reflects a webhook-captured payment without a manual page reload; `RazorpayProvider::createOrder()` sends a real `Idempotency-Key` header |
| B9 | User profile page | Never claimed by any slice (T9, P1) | Avatar, name, timezone/language, password change — one page, shared across student/parent/staff, same "one component, mounted everywhere" shape `NotificationCentre` (S8 B7) established |
| B10 | Production readiness: backups + real queue retry/backoff | Never claimed by any slice (T3/T2, both P1) | Nightly encrypted backup (db + `storage/app/private`) with 30-day retention via `spatie/laravel-backup`; every existing job gets `tries`/`backoff()`, `failed_jobs` table provisioned, Horizon's already-installed priority queues actually configured |
| B11 | Report catalog: revenue + engagement/completion | S8 §2.4 decision 7 ("cheap once one report is real") | Two more CSV exports reusing `ExportAttendanceRegister`'s exact job/download-controller shape |
| B12 | Plan tiers / feature-flag foundation | S2 D8, S3 L4 (both blocked on this) | A `plan_tier` on `Tenant` + a small limit-checking service, unlocking the bandwidth-quota and AI-spend-cap *enforcement* both S2 and S3 built the *measurement* half of and then explicitly stopped |
| B13 | Low-attendance alert + automated nudges | S8 §2.2 (H10, M15) | A real outbound alert (not just the passive at-risk widget S8 B5 already built) when a student's attendance/coverage crosses the same 60% line `InstituteOverviewWidget` already uses; a 3-day-inactivity nudge reusing the same `WhatsAppChannel`/`NotificationCentre` plumbing |
| B14 | Full test coverage, isolation registration, review pass | — | Same closing block every prior slice has had |

### 2.2 Out of scope, and where each goes

| Feature | Why deferred | Lands in |
|---|---|---|
| Actually binding real vendor drivers (Bunny, a real AI vendor, Zoom, Razorpay-as-default, AiSensy-as-default) | No accounts exist for any of the five — same posture every prior slice shipped with. This slice fixes what's buildable *without* an account (Razorpay's idempotency header, B8) but doesn't flip any binding in `AppServiceProvider` | Fast-follow, the moment each account exists |
| Real `BunnyDriver` + adaptive HLS/bitrate ladder | Needs the Bunny account above, and is a materially bigger build than any other single item in this backlog | Fast-follow |
| 9 of `lesson_artifacts`' 11 originally-pinned consumers (revision notes, semantic search, weak-topic detection, auto chapters/timestamps, auto lecture notes, daily practice problems, ask-at-timestamp, post-class auto quiz, cross-course doubt chat) | Each is its own real AI feature, not a hardening task — bundling any of them into a "backlog closure" slice would make this slice's own scope dishonest the same way S8's design doc warned against for its own P1 density | A dedicated S10 "AI Spine, part 2" slice |
| Assignments + Gradebook, Certificates | S6's own design doc: "not on the 8-slice P1 roadmap at all — no S-number owns them." Still true — adding either here would roughly double this slice's already-large scope | Beyond Institute Zero, unscheduled |
| Extended question types, proctoring, ranked test series, OMR scanning | S6 fast-follow, P2/P3, no security or broken-promise urgency | Fast-follow |
| Timetable conflict detection (F7), batch forum/feedback/bulk-CSV-import (F6/F8/F9/F10) | S4 fast-follow, genuinely separate feature work, not hardening | Fast-follow |
| Refunds, grace-period access restriction, subscriptions, multi-currency | S7 fast-follow — refunds/subscriptions both plausibly *depend* on B12's plan-tier foundation landing first, so attempting them before B12 ships would be backwards | Fast-follow, after this slice |
| BigBlueButton, Google Meet live-class providers; rotating-QR check-in; live-webhook attendance writer; teacher-attendance-for-payouts | All blocked on either a real vendor account or a payout system that doesn't exist (S7 never built one either) | Fast-follow, once the blocker clears |
| Monthly WhatsApp report-card PDF, live-class T-30 reminders, course announcements | S8 fast-follow, P1-but-not-demo-critical, no new blocker cleared by this slice | Fast-follow |
| Gradebook/B2B revenue exports | Both moot pending Gradebook/B2B billing, neither of which exists | Deferred alongside their parent feature |

### 2.3 Reviewed before writing

- Every claim in §1 and §2.1 was checked against the live file it describes, not against the deferring slice's own (potentially stale) wording — the same discipline S8 B0 established after catching its own design doc's stale `RoleHierarchy` claim. Full verified inventory (including items checked and found *already* resolved by a later slice, and so correctly excluded here) is the output of this slice's own pre-planning research pass, not reproduced in full in this doc.
- `laravel/horizon` (`^5.48`) is already a real dependency (`composer.json`, confirmed present since at least S7) — B10's "real queue retry/backoff" is configuring an already-installed package, not adding one.
- `Tenant` already carries `gstin`/`legal_name`/`billing_address` (S7's `2026_08_11_200000_add_billing_columns_to_tenants_table.php`) — B12 adds one more column to the same model, not a new billing subsystem from scratch.
- `RoleAssignmentAudit` (S1) is the existing precedent for "every sensitive action gets its own audit row" — B3's impersonation log follows that shape exactly, not a new pattern.
- `NotificationCentre` (S8 B7) is the existing precedent for "one shared component, mounted on both portals and all three panels via a Filament render hook" — B9's profile page and B13's nudge delivery both reuse that mounting shape rather than inventing a new one.
- `ExportAttendanceRegister` (S8 B6) is the existing precedent for "queued job writes to `local` disk, a Filament action dispatches it, a gated plain controller downloads it, readiness is `Storage::exists()` not a new schema column" — B11's two new reports copy that shape exactly.

### 2.4 Decided (2026-08-12, before Block 0)

1. **Security parity (B0) goes first, not last.** Every prior slice's own review pass happened at the *end* of the slice; this slice inverts that for its two known, already-named security gaps specifically — there's no reason to carry a known multi-tenant leak through 14 more blocks when the fix is already understood and small.
2. **Three new Composer dependencies are a deliberate, named exception to "no new library until the demo needs it."** TOTP (B1), Google OAuth (B2), and encrypted backups (B10) are each a well-defined, security-sensitive primitive (RFC 6238, OAuth2, backup/restore correctness) that this app has no business hand-rolling — the same reasoning that's kept invoices as printable HTML rather than a hand-rolled PDF generator applies in the opposite direction here: some things *are* worth a battle-tested package. Recommended: `pragmarx/google2fa-laravel` (TOTP), `laravel/socialite` (OAuth), `spatie/laravel-backup` (backups) — confirmed live, actively maintained packages as of this doc's writing; pin exact versions at B1/B2/B10 build time, not guessed here.
3. **Custom domain (B4) ships as DNS-verification-only.** Fully automated SSL issuance (ACME/Let's Encrypt against a dynamically-added domain) is an infrastructure/ops concern, not application code — this app has no reverse-proxy/cert-management layer to hook into yet. B4 builds the verification flow and `ResolveTenant` recognizing a verified custom domain; actually serving HTTPS on that domain stays a documented manual step (matching how Docker/hosting setup is already documented in `SETUP.md`, not automated).
4. **Impersonation (B3) is session-based, not token-based**, matching this app's existing session-cookie auth model rather than introducing a second auth mechanism. A super_admin's own session gets an `impersonating_user_id` flag; every request under it resolves `Auth::user()` as the impersonated user while the audit log keeps the real actor's identity.
5. **B12's plan tiers are a flat enum (`free`/`paid`), not a configurable-limits table.** `docs/FEATURES.md`'s own Q1/Q2 wording ("how the product gets sold in tiers at all") doesn't require a full self-serve billing product to unlock the two enforcement gaps this slice actually needs (bandwidth quota, AI spend cap) — a tenant-level tier plus two hardcoded per-tier limits is enough to turn S2/S3's measurement-only code into real enforcement. A richer tiers/limits product is fast-follow once real customers actually need different limits from each other.
6. **B6's content-type completion covers `pdf`/`text`/`embed` rendering, not new upload infrastructure.** `Lesson::TYPES` and the Filament form already accept these types (S2) — this block wires the *reading* side (`SyllabusTree` branching, a viewer for each type) so selecting them stops being a dead end, reusing S2's existing upload/storage code for `pdf` the same way `video` already does.
7. **B13's low-attendance alert reuses B3 (S8)'s absence-alert plumbing exactly** — same `AttendanceRecorded`-adjacent trigger shape, same `WhatsAppChannel`/`database` dual-channel notification, same dedupe-by-notifications-table pattern — not a new notification mechanism.

---

## 3. Architecture

### 3.0 Where this lands

No new domain folder. Every block extends an existing one:

| Block | Domain |
|---|---|
| B0 | Content, Assessments, Batches (the three files each gap lives in) |
| B1, B2, B3, B4 | Tenancy |
| B5, B13 | Comms |
| B6 | Content |
| B7 | Assessments |
| B8 | Fees |
| B9 | Shared (`app/Livewire/`, mounted everywhere — no single owning domain, same as `NotificationCentre`) |
| B10 | Infrastructure (`bootstrap/app.php`, `config/`, no domain) |
| B11 | Comms (reuses B6-of-S8's shape) + Fees (reads `FeeInvoice`/`FeePayment` directly) |
| B12 | Tenancy (`Tenant` gains `plan_tier`), read from Content (bandwidth) and AI (spend cap) |

### 3.1 Schema (additive only, confirmed against every table this touches)

```
users            — no change
tenants          — + plan_tier (string, default 'free')
                 — + custom_domain (string, nullable, unique)
                 — + custom_domain_verified_at (timestamp, nullable)
users            — + two_factor_secret (text, nullable, encrypted cast)
                 — + two_factor_recovery_codes (text, nullable, encrypted cast)
                 — + two_factor_confirmed_at (timestamp, nullable)
                 — + google_id (string, nullable)
                 — + avatar_path (string, nullable)
                 — + timezone (string, default 'Asia/Kolkata')
                 — + locale (string, default 'en')
```

New tables:

```sql
-- B3
CREATE TABLE impersonation_audits (
    id BIGINT PRIMARY KEY,
    tenant_id BIGINT NULL REFERENCES tenants(id) ON DELETE CASCADE, -- null: platform-level actor impersonating across tenants
    actor_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    target_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    started_at TIMESTAMP NOT NULL,
    ended_at TIMESTAMP NULL
);

-- B5
CREATE TABLE parent_invite_codes (
    id BIGINT PRIMARY KEY,
    tenant_id BIGINT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    student_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code VARCHAR(20) NOT NULL UNIQUE, -- short, human-typeable, not a UUID
    expires_at TIMESTAMP NOT NULL,
    redeemed_at TIMESTAMP NULL,
    redeemed_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_by BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE
);
```

No new schema for B0 (pure code fix), B6 (reuses `Lesson`'s existing columns), B7 (reuses `QuizAnswer`), B8 (reuses `FeeInvoice`/`FeePayment`), B9 (columns folded into `users` above), B10 (Laravel's own `failed_jobs` table, provisioned via the framework's own stub migration), B11 (reuses `FeeInvoice`/`FeePayment`/`Enrolment` directly), B13 (reuses `AttendanceRecord`/`VideoWatchEvent`, dedupes via the existing `notifications` table).

### 3.2 Data flow (the two load-bearing new flows)

**Custom domain resolution (B4), extending `ResolveTenant`:**
```
Request arrives on learn.theirschool.com
  -> ResolveTenant no longer matches on subdomain alone
  -> tries Tenant::where('subdomain', $firstLabel) first (unchanged, the common case)
  -> falls back to Tenant::where('custom_domain', $host)->whereNotNull('custom_domain_verified_at')
  -> same CurrentTenant::set() either way — every downstream check is unaware which path resolved it
```

**Plan-tier enforcement (B12), extending both existing measurement-only checks:**
```
BandwidthUsageDaily::record() (S2, unchanged)
  -> new: BandwidthQuota::remainingFor($tenant) reads Tenant.plan_tier's hardcoded cap
  -> video stream request now checked against remainingFor() before serving (S2's own
     "measuring only, no cap" note is what this closes)

TokenCostLedger::totalForDate() (S3, unchanged)
  -> new: AiSpendCap::remainingFor($tenant) reads the same plan_tier
  -> AI-calling code paths (ingestion, doubt chat, question generation) now checked
     before dispatching a real provider call
```

## 4. Testing

| Layer | What | Count |
|---|---|---|
| Feature | B0: cross-tenant session on `/dashboard`, `canStream()`, `Quiz::isVisibleTo()`, `canJoinLive()` course-unpublished case, `myLiveBatchSession()` tiebreak determinism | +6 |
| Feature | B1: TOTP enrollment, challenge on login, recovery code, wrong-code lockout | +5 |
| Feature | B2: Google OAuth new-user + existing-user-by-email linking, tenant resolution | +3 |
| Feature | B3: impersonation start/end, audit row written, cannot impersonate cross-tenant, cannot impersonate another super_admin | +4 |
| Feature | B4: DNS verification success/failure, `ResolveTenant` resolves a verified custom domain, unverified domain 404s | +4 |
| Feature | B5: invite code generation, redemption, expiry, single-use, cross-tenant redemption blocked | +5 |
| Feature | B6: PDF/text/embed lesson rendering, download-protection markup present | +4 |
| Feature | B7: incremental answer persistence, auto-submit sweep grades a partially-answered (not blank) expired attempt | +3 |
| Feature | B8: live refresh after webhook capture, duplicate Razorpay order prevented by idempotency key | +3 |
| Feature | B9: profile update, avatar upload, password change, mounted on all 5 surfaces | +5 |
| Feature | B10: backup command runs and produces an archive, a job's `tries`/`backoff` config, failed job lands in `failed_jobs` | +3 |
| Feature | B11: revenue report figures, engagement/completion report figures, both tenant-scoped | +3 |
| Feature | B12: bandwidth quota blocks over-cap streaming, AI spend cap blocks an over-cap call, tier upgrade lifts both | +4 |
| Feature | B13: low-attendance alert fires once per threshold-cross not every check, nudge dedup, both reuse `NotificationCentre` | +3 |
| Cross-tenant | `ImpersonationAudit`, `ParentInviteCode` registered in `TenantModelRegistry` | (generated) |

## 5. What's not real yet, on purpose

No real vendor account exists for any of the five fake drivers (Bunny, AI, Zoom, Razorpay, AiSensy) — this slice fixes what's buildable without one (B8's idempotency header) but does not flip any `AppServiceProvider` binding. Custom domains get DNS verification only; SSL provisioning is a documented manual step, not automated. Plan tiers are a flat two-value enum with hardcoded limits, not a configurable billing product. Nine of `lesson_artifacts`' eleven originally-pinned AI consumers, Assignments/Gradebook, Certificates, extended question types/proctoring/test-series/OMR, and the rest of §2.2's table are all explicitly out of scope — full reasoning and landing spot for each in §2.2, same discipline every prior slice's own "what's not real yet" section has used.

---

This is the ninth slice, the first not on the original P1 roadmap. Once built and reviewed, `docs/PROJECT-STATUS.md` gains an "S9" section in the same completed-slice format as S1-S8, and this slice's own §2.2 becomes the seed of whatever S10 turns out to be.
