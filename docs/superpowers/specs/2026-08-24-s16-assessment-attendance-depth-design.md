# Design: S16 Assessment & Attendance Depth

## 1. Why this document exists

Locks scope and architecture for S16 before any block is built, same role
S9-S15's own design docs played. Full block-by-block build log lives in the
companion `2026-08-24-s16-assessment-attendance-depth-plan.md`.

S16 is the eighth P2/P3-mixed backlog slice (after S9 hardening, S10
assignments/gradebook/certificates, S11 billing, S12 security/resilience, S13
discovery/engagement, S14 video/live-class depth, S15 batch & institute
operations). Scope per `docs/ROADMAP.md`'s own S16 row: `docs/FEATURES.md`
§I6-I11 (Assessments) + §H10-H13 (Attendance) — **10 items as counted there,
but I7 (numerical questions) is already built** (§2.3 below), so the
roadmap's own "9" item count is already correct, not a miscount to fix. Real
build scope is **9 items**.

## 2. Scope

### 2.1 In scope — 9 items

| # | Item | Source | Phase |
|---|---|---|---|
| I6 | Extended question types (multi-select, fill-in-the-blank, matching pairs, ordering) with partial credit | REQ-52 | P2 |
| I8 | Proctoring — tab-switch/fullscreen/copy-paste detection, per-question timing | REQ-15 | P3 |
| I9 | Violation threshold → auto-submit + flag for review | REQ-15 | P3 |
| I10 | Test series with All-India Rank, percentile, topper comparison | NEW, MKT | P2 |
| I11 | OMR sheet scanning for offline tests → auto-grade | NEW, MKT | P3 |
| H10 | Low-attendance threshold alerts — extend, not rebuild (§2.3) | NEW | P2 |
| H11 | Geofenced check-in | NEW | P3 |
| H12 | Biometric / RFID device webhook | NEW | P3 |
| H13 | Substitute-teacher workflow on unmarked teacher absence | NEW | P3 |

Unlike S9-S15, this slice is a deliberate **mixed P2/P3 grab-bag**, not a
phase-boundary slice — `docs/FEATURES.md` Part 5 splits these 9 items across
P2 (I6, I10, H10) and P3 (I8, I9, I11, H11, H12, H13). `docs/ROADMAP.md`'s own
S16 headline names every P3 item explicitly ("proctoring... OMR scanning...
geofencing"), so none is cut for being P3-tier — same "headline explicitly
names it, it stays" precedent S15 kept ID cards (P3) in scope on.

### 2.2 Out of scope, and why (locked before any code, not discovered mid-build)

- **I7 (numerical/JEE-NEET questions) is not part of this slice — it already
  shipped in S6.** `Question::TYPES` includes `'numerical'`,
  `QuizAttemptService::gradeNumerical()` is tolerance-based and explicitly
  tagged `(I7)` in its own docblock. Re-verified before writing this design,
  not assumed from the roadmap's P1 tag. The one real gap under the I7 label
  — JEE/NEET-style negative marking (+4/−1 per-question weighting) — is a
  scoring-policy change to every existing question type, not an I7-specific
  gap, and isn't named in any roadmap row; left out, flagged as a possible
  future item if an institute asks for it.
- **H13's "workflow" is flag + admin action, not automatic substitute
  matching/scheduling.** The roadmap item's own one-line wording doesn't
  specify an auto-match algorithm, and this app has no "available substitute
  pool" concept to match against — building a matching engine on top of a
  flagging system nobody asked for yet is exactly the "don't build for a
  hypothetical" posture `RecommendationService` (S13) and §S.S2's single-
  instructor-per-batch decision (S15) both already took.
- **I10's "All-India Rank" is a per-tenant ranking, not a literal
  cross-institute leaderboard.** Ranking students from *different* coaching
  institutes against each other would need a shared cross-tenant students
  table, which directly contradicts every tenant-isolation guarantee this
  app enforces (`CrossTenantTest`, `TenantModelRegistry`, `BelongsToTenant`'s
  own global scope) — "All India Rank" is read as the *marketing framing* a
  single institute uses for its own test series, not a literal
  cross-tenant computation. §4 decision 6 below.
- **I11's OMR reads a fixed bubble template, not a freeform scanned form.**
  No AI vision model, no free-text OCR, no skew/rotation auto-correction —
  a flat, upright scan against a known coordinate grid this app itself
  generates and prints. General-purpose document AI is a different, much
  larger problem (§4 decision 8) this slice doesn't attempt.
- **H12 is a generic, vendor-agnostic device webhook, not a named vendor
  integration.** Unlike S14's Zoom/Google Meet (concrete, named vendors with
  real OAuth/API contracts to build against), no specific biometric/RFID
  vendor is named anywhere in `docs/FEATURES.md` — this slice builds one
  generic HMAC-signed webhook contract (matching the Zoom recording
  webhook's own verification shape) that any device speaking it can call;
  which real device an institute buys and how it's configured to call this
  URL is deployment-time integration, same "flagged, not solved" boundary
  S14 drew around `GoogleMeetProvider`'s single platform-wide refresh token.

### 2.3 Verified before writing this design (grepped/read, not assumed)

- **Assessments domain (`app/Domains/Assessments`) already owns
  `Question`/`Quiz`/`QuizAttempt`/`QuizAnswer` and `QuizAttemptService`** —
  every I-item in this slice extends it, no new domain needed there.
  `Question::TYPES = ['mcq', 'true_false', 'short_answer', 'numerical']`,
  `QuizAttemptService::grade()` is a `match` over exactly those four with a
  `default => [false, 0.0, 'auto_graded']` fallthrough — I6's new types need
  new `match` arms, not a rewrite. `quiz_attempts`/`quiz_answers` have
  received zero schema changes since their original S6 migrations (only
  `questions` gained `token_cost` in S9 B14) — I8/I9's new columns are the
  first schema change to either table since launch.
- **Attendance has no dedicated domain and never has, since S5.**
  `AttendanceRecord` lives in `app/Domains/Content/Models/`; its two real
  writers (`RecordVideoCoverageAttendance` job, `BatchSessionsRelationManager::markAttendance`)
  live in `app/Domains/Batches`; its consumers (`SendAbsenceAlert`,
  `SendLowAttendanceAlert`) live in `app/Domains/Comms`. This slice keeps
  that same split rather than consolidating into a new `Attendance` domain
  — introducing one now would mean moving the existing model for no reason
  this slice's own additions actually need; every new H-item fits one of
  the three existing homes (§3.1).
- **`docs/FEATURES.md`'s own "three writers, one table" design (H1) is 2 of
  3 built.** `AttendanceRecord::SOURCES = ['manual', 'video_coverage']`
  (its own docblock: *"two writers ship in S5; a third — live-webhook — is
  fast-follow"*) — the live-class join/leave webhook writer (H4/H5) was
  never built and stays out of this slice's own 9-item scope (not named in
  the S16 roadmap row). H11/H12 add a 3rd and 4th writer alongside the
  existing two, not a replacement for the still-missing live-webhook one.
- **H10 (low-attendance alerts) already exists, contrary to a naive reading
  of its "P2, NEW" tag.** `SendLowAttendanceAlert` (Comms, S9 B13) fires on
  `AttendanceRecorded`, checks `StudentMetrics::isAtRisk()` against a
  **hardcoded** `AT_RISK_THRESHOLD = 60.0` constant, notifies linked parents
  via WhatsApp, deduped once per week. **This slice's real H10 gap is
  narrow**: the threshold is a single hardcoded value with no per-institute
  override, and the alert is parent-only — no staff-facing "who's at risk"
  view exists anywhere (confirmed: no such Filament page/widget found).
- **H9 (WhatsApp-on-absence) already exists** (`SendAbsenceAlert`,
  same-day dedupe) — not one of this slice's 9 items, unaffected.
- **`attendance_records` has no `role` column** — `user_id` is always read
  as a student today; nothing distinguishes a teacher's own attendance row.
  H7 ("teacher attendance, same table, `role=teacher`, feeds payouts") was
  never built either, and isn't itself a numbered S16 item — but H13
  (substitute-teacher workflow) cannot exist without it, same "verify a
  named item's own real prerequisite before building on it" discipline S15
  applied to §S.S1/instructor-invitations. `grep -rln payout app` returns
  nothing, so H7's own "feeds payouts" tie-in has no downstream consumer to
  wire up yet — this slice adds the column and the teacher-attendance write
  path only as far as H13 itself needs.
- **No geofence, biometric/RFID, proctoring, violation, test-series/rank, or
  OMR concept exists anywhere in `app/`** — each grepped individually
  (`geofence`, `biometric|rfid`, `proctor|tab_switch|fullscreen`,
  `violation`, `TestSeries|percentile|topper`, `omr`), all genuinely
  greenfield, confirmed via a dedicated research pass, not assumed from the
  roadmap's own wording.
- **`ext-gd` is available on this PHP 8.4 build** (`php -m` confirmed) and
  no image-processing composer package (`intervention/image`, `imagick`) is
  installed — I11's OMR reader uses bundled GD directly, no new dependency,
  same "reuse what's already there" posture `IdCardGenerator` (S15) took
  reusing `dompdf`/`endroid/qr-code` instead of adding a third PDF/QR
  package.
- **`ImpossibleTravelDetector` (S12 B2) already has a real haversine
  distance function** (`app/Domains/Security/Services/ImpossibleTravelDetector.php`)
  — H11's geofence radius check reuses the same formula, not a second
  hand-rolled copy.
- **`ZoomRecordingWebhookHandler` (S14 B8) already has a real HMAC-SHA256
  webhook-signature verification pattern** — H12's generic device webhook
  copies that shape (timestamp + signature header, `hash_hmac`), not a new
  one invented from scratch.
- **`RoleHierarchy::LEVELS` is still 5 roles** (`super_admin`,
  `institute_admin`, `instructor`, `student`, `parent`) — "teacher" in every
  H-item's own wording means the existing `instructor` role; no new role is
  added.
- **`QuizRunner` (`app/Livewire/QuizRunner.php`) is the one shared,
  top-level Livewire component every student's quiz-taking flow already
  goes through** — I6's new question-type rendering, I8's proctoring hooks,
  and I9's violation counting all wire into this single component, same
  "one shared path" posture this app already prefers over parallel
  code paths.

## 3. Architecture

### 3.1 Domain ownership — no new domain

Every item extends an existing domain; nothing here is a "new business
surface" the way `Operations` (S15) or `Billing` (S11) were.

- **`Assessments`** — extended question types (I6), proctoring (I8),
  violation handling (I9), test series + ranking (I10), OMR (I11). All five
  are Quiz/Question-adjacent, the domain's own existing business.
- **`Content`** — `AttendanceRecord` gains the `role` column (H7
  prerequisite for H13) and two new `SOURCES` values (H11, H12) — the model
  itself already lives here, extended not moved.
- **`Batches`** — new attendance writers: `GeofenceCheckInService` (H11),
  the generic device webhook handler (H12), and the teacher-attendance
  sweep + substitute-flag job (H13) — same home as the two existing writers
  (`RecordVideoCoverageAttendance`, `markAttendance`).
- **`Comms`** — H10's staff-facing at-risk view and the substitute-teacher
  admin notification (H13) sit beside the existing `StudentMetrics`/
  `SendLowAttendanceAlert`/`SendAbsenceAlert` — the domain that already
  owns "how is this student/class doing, and who needs to know."
- **`Tenancy`** — H10's per-institute configurable threshold is a tenant
  setting, same shape `CustomDomainSettings` (S9 B4) already established
  for this app's first tenant-settings UI.

### 3.2 New schema

```
-- Content: H7 (prerequisite for H13), H11, H12
attendance_records
  + role (string, default 'student' — 'student'|'instructor')
  -- SOURCES const widened: 'manual'|'video_coverage'|'geofence_checkin'|'device_webhook'

-- Tenancy: H10
tenants
  + at_risk_threshold_percent (decimal nullable — null falls back to
    StudentMetrics::AT_RISK_THRESHOLD's existing 60.0 default, same
    "nullable + existing hardcoded fallback" shape S11's plan_id addition
    already established for zero blast radius on existing tenants)

-- Batches: H11
batch_geofences (Batches — H11)
  id, tenant_id, batch_id (unique — one geofence per batch), latitude,
  longitude, radius_meters, timestamps
  -- absence of a row for a batch means geofencing is off for it (opt-in,
  -- not a forced constraint on every batch)

-- Batches: H12
device_webhook_credentials (Batches — H12)
  id, tenant_id, batch_id (nullable — null means "any batch", matches the
  "device covers the whole institute" common case), device_label,
  webhook_secret, timestamps

-- Batches: H13 (depends on H7 above)
teacher_attendance_flags (Batches — H13)
  id, tenant_id, batch_session_id, instructor_id, flagged_at,
  status (open|resolved), resolved_by_user_id (nullable), timestamps

-- Assessments: I6 (no new table — Question.correct_answer's own jsonb
-- shape widens per new type, documented in §4 decision 2)

-- Assessments: I8
quiz_proctoring_events (Assessments — I8)
  id, tenant_id, quiz_attempt_id, event_type
  (tab_switch|fullscreen_exit|copy|paste), occurred_at, timestamps

-- Assessments: I8 (column addition)
quiz_answers
  + time_spent_seconds (int nullable)

-- Assessments: I9 (column addition)
quiz_attempts
  + flagged_for_review (boolean, default false)

-- Assessments: I10
test_series (Assessments — I10)
  id, tenant_id, title, starts_at (nullable), ends_at (nullable),
  timestamps

quizzes
  + test_series_id (nullable FK -> test_series — a quiz not in a series
    keeps this null, same "additive, zero blast radius" shape every prior
    slice's own optional-grouping FK uses, e.g. S13's fee_invoices.program_id)

-- Assessments: I11
omr_templates (Assessments — I11)
  id, tenant_id, quiz_id (FK — the offline test's own answer key lives on
  its Question rows, reused not duplicated), question_count,
  options_per_question, roll_number_digits, layout (jsonb — bubble grid
  coordinates, generated not hand-authored, §4 decision 8), timestamps

omr_sheet_scans (Assessments — I11)
  id, tenant_id, omr_template_id, uploaded_by_user_id, image_disk_path,
  status (pending|processed|failed), detected_roll_number (nullable),
  matched_user_id (nullable FK -> users), quiz_attempt_id (nullable FK —
  set once graded), raw_bubble_results (jsonb nullable), timestamps
```

### 3.3 New `BelongsToTenant` models (all registered in `TenantModelRegistry`)

`BatchGeofence`, `DeviceWebhookCredential`, `TeacherAttendanceFlag`,
`QuizProctoringEvent`, `TestSeries`, `OmrTemplate`, `OmrSheetScan` — **7 new
models**, narrower than S13 (12) or S15 (11) since several items are pure
column additions (H7, H10, I8's `time_spent_seconds`, I9) rather than new
tables.

## 4. Design decisions

1. **`attendance_records.role` is a plain string column, not a second
   table.** Same shape every other "same table, discriminated by a column"
   precedent in this app already uses (`AttendanceRecord.source` itself,
   `AttendanceRecord.status`) — a teacher's attendance row is structurally
   identical to a student's, just a different `role` value and (for now) a
   different writer.
2. **I6's new question types store their answer shape in the same
   `correct_answer` jsonb column, keyed by convention per type, not a new
   column per type.** `numerical` already does this (`{value, tolerance}`);
   this slice adds `multi_select: {correct_option_ids: [...]}`,
   `fill_in_blank: {accepted_answers: [...]}` (case-insensitive exact
   match, not fuzzy), `matching: {pairs: [[left_id, right_id], ...]}`,
   `ordering: {correct_sequence: [...]}` — same "no schema migration per
   question type" property the existing 4 types already have.
3. **Partial credit is a simple fraction, not a curved or negatively-marked
   scheme.** `multi_select`: (correct selections − incorrect selections) /
   total correct options, floored at 0. `matching`/`ordering`: fraction of
   pairs/positions correct. No partial credit for `fill_in_blank` (exact
   match or zero, same binary shape `mcq`/`true_false` already have) —
   consistent, predictable grading an instructor can explain to a student,
   not a research-grade partial-credit model.
4. **Proctoring is detection and logging, not a lockdown browser.** Tab-
   switch/fullscreen-exit/copy/paste are logged via browser events
   (Visibility API, Fullscreen API, `copy`/`paste` listeners) posted to
   `QuizRunner`; there is no OS-level process lock, no webcam, no
   screen-recording. Framed honestly to instructors as a deterrent-and-
   evidence tool, same posture this app's video watermark and print/save
   deterrents (S9, S14) already took for "raises the bar, doesn't make
   cheating impossible."
5. **I9's violation threshold reuses the existing `auto_submitted` status
   and `submit()` call, not a new terminal state.** `AutoSubmitExpiredQuizAttempts`
   already calls `QuizAttemptService::submit($locked, [], 'auto_submitted')`
   on time expiry; a violation-threshold trip calls the exact same method
   the exact same way — the only new state is the separate
   `flagged_for_review` boolean, since *why* an attempt auto-submitted
   (expired vs. violated) is a fact an instructor reviewing it needs, but
   isn't a new terminal status to fork every existing `STATUSES` check on.
6. **"All-India Rank" is computed per-tenant, across every student who
   attempted any quiz in the same `test_series`, not cross-tenant.** §2.2.
   Rank/percentile/topper-comparison are computed on demand from each
   student's best attempt per quiz in the series (same "no materialized
   rank column, computed at read time" posture `Gradebook`'s own S8 B5
   precedent already established for a similarly bounded aggregate) — a
   PHP-side sort over one tenant's own attempts, not a `DB::raw` window
   function (this app's own security baseline forbids raw SQL in
   `app/Domains/`).
7. **A `test_series` bundles `Quiz` rows via a nullable FK on `quizzes`,
   the same "bundle via an FK on the bundled thing" shape `Program`
   (S13) established for bundling `Batch` rows** — not a pivot table, since
   one quiz belongs to at most one series (a quiz reused across multiple
   series is a real but unrequested future need, deferred).
8. **OMR reads a fixed, app-generated bubble template — not a freeform
   scanned form and not general document AI.** `omr_templates.layout` is
   computed once at template-creation time from `question_count`/
   `options_per_question`/`roll_number_digits` (a deterministic grid this
   app itself lays out and the institute prints), not hand-authored by an
   admin dragging bubbles around — a "template designer" UI is out of
   scope, matching §2.2's "don't build a bigger problem than asked."
   `OmrScanner` (GD) reads pixel darkness at each known coordinate; no
   skew/rotation correction, flagged in §5 as a real production-accuracy
   risk this slice's own test suite can't fully cover (same "what's not
   real yet, on purpose" honesty S10/S14 already applied to first-use
   dependencies).
9. **A scanned OMR sheet is matched to a `User` via its bubbled roll-number
   block, resolved against `Enrolment.id` (not free-text name/roll-number
   entry).** The template's own `roll_number_digits` bubbles the
   student's enrolment id digit-by-digit at upload time — free-text OCR is
   a different, harder problem (§2.2) this slice doesn't attempt; a scan
   whose roll-number block doesn't resolve to a real enrolment in the
   quiz's own batch is queued `status: failed` for manual admin resolution,
   not silently dropped.
10. **H13 stops at flag + admin notification, no automatic substitute
    matching.** §2.2. `TeacherAttendanceFlag` is created by a sweep job
    (same "unscoped daily sweep" shape `CheckCourseCompletionAndIssueCertificates`
    (S10)/`SuspendOverdueTenantSubscriptions` (S11) already established)
    once a `BatchSession`'s own end time passes with no teacher-role
    `AttendanceRecord` written for it; institute_admin resolves it manually
    (including, if they choose, reassigning the batch's `instructor_id` —
    S15's own field, no new reassignment mechanism needed) via a new
    Filament action, not an automated matcher.
11. **H12's device webhook is generic and vendor-agnostic, keyed by a
    per-tenant (optionally per-batch) shared secret, not a named vendor
    SDK.** §2.2. `device_webhook_credentials.webhook_secret` + HMAC-SHA256
    verification (same signature shape `ZoomRecordingWebhookHandler`
    already established) is the entire contract; the payload itself is a
    minimal, documented JSON shape (`{device_label, user_identifier,
    event: 'check_in'|'check_out', timestamp}`) any real device integrator
    configures their hardware to POST — this app has no relationship with
    any specific biometric/RFID vendor to build a real SDK client against.
12. **H10's threshold override is per-tenant, not per-batch or per-course.**
    `StudentMetrics::isAtRisk()`'s own existing signature already computes
    a student-wide number (not per-course), matching `StudentProgress`'s
    own S8 B5 granularity — a per-institute override is the natural
    matching granularity, not a narrower one this slice would have to
    invent new aggregation for.

## 5. Testing strategy

- **Unit/feature per service**: `GeofenceCheckInService`, the device
  webhook handler, `TeacherAttendanceSweep`, the widened
  `QuizAttemptService::grade()` (one test per new question type),
  `TestSeriesRankingService`, `OmrScanner` each get a dedicated test file.
- **Cross-tenant isolation**: all 7 new `BelongsToTenant` models register in
  `TenantModelRegistry`, auto-generating `CrossTenantTest` coverage — no
  hand-written per-model isolation tests.
- **Partial credit**: a dedicated test per new question type proving the
  exact fractional score for a partially-correct response (§4 decision 3),
  not just "graded, non-zero."
- **Proctoring + violations**: a dedicated test proves N violations (config
  threshold) auto-submits an in-progress attempt with `flagged_for_review =
  true`, and that below-threshold violations are logged but don't
  auto-submit.
- **Geofence**: inside-radius check-in succeeds, outside-radius is refused,
  a batch with no `BatchGeofence` row accepts any check-in (opt-in, §3.2).
- **OMR**: a synthetically-generated bubble-sheet image (drawn by the test
  itself, same coordinates the template computes) round-trips through
  `OmrScanner` to the exact expected per-question answers and roll number —
  proves the reader against a known-good input, not just that the job runs.
- **Lifecycle E2E**: at minimum, a teacher's session goes unmarked past its
  end time → `TeacherAttendanceFlag` created → admin resolves by reassigning
  the batch's instructor, chained in one run; plus a full test-series
  lifecycle — two quizzes bundled into one series → two students attempt
  both → rank/percentile computed correctly for both.
- Rough anchor, not a target, same posture every prior slice's own estimate
  already undershot — expect 60-90 new tests across the slice by its own
  final block.
