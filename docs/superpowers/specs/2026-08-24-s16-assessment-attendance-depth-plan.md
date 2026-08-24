# Implementation Plan: S16 Assessment & Attendance Depth

Full design: `2026-08-24-s16-assessment-attendance-depth-design.md`. This is
the block-by-block build log — update the PROGRESS table as each block
lands, same as every S1-S15 plan did. 18 blocks (B0-B17), finer-grained
than the 9-item real build scope since several items split into
independent blocks (extended question types split in two, proctoring split
in two, geofenced check-in split in two, substitute-teacher workflow split
in two, test series/ranking split in two, OMR split in two).

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-24-s16-assessment-attendance-depth-design.md and
docs/superpowers/specs/2026-08-24-s16-assessment-attendance-depth-plan.md, then build
Block 0 of the S16 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | `attendance_records.role` column (H7, foundation for H13) — teacher vs. student attendance rows in the same table | ✅ done |
| B1 | H10 — tenant-configurable at-risk threshold + staff-facing at-risk students view (extends the existing S9 alert, not a rebuild) | ✅ done |
| B2 | H11 pt.1 — `batch_geofences` schema + `GeofenceCheckInService` (haversine, reuses `ImpossibleTravelDetector`'s formula) | ✅ done |
| B3 | H11 pt.2 — student-facing geofenced check-in Livewire component (browser geolocation) | ✅ done |
| B4 | H12 — generic HMAC-signed device webhook (biometric/RFID, vendor-agnostic) | ✅ done |
| B5 | H13 pt.1 — teacher/instructor attendance capture + `TeacherAttendanceSweep` (unmarked-past-session-end detection) | ✅ done |
| B6 | H13 pt.2 — `TeacherAttendanceFlag` + admin notification + manual substitute reassignment action (no auto-matching) | ✅ done |
| B7 | I6 pt.1 — extended question types schema (`Question::TYPES` widened) + `QuizAttemptService::grade()` partial-credit branches | ⬜ not started |
| B8 | I6 pt.2 — Filament form UI for the 4 new types + `QuizRunner` student-facing rendering | ⬜ not started |
| B9 | I8 pt.1 — `quiz_proctoring_events` schema + client-side tab-switch/fullscreen/copy-paste detection wired into `QuizRunner` | ⬜ not started |
| B10 | I8 pt.2 — per-question timing (`quiz_answers.time_spent_seconds`) | ⬜ not started |
| B11 | I9 — violation threshold → auto-submit (reuses existing `auto_submitted` path) + `flagged_for_review` + instructor review queue | ⬜ not started |
| B12 | I10 pt.1 — `test_series` schema + Admin-panel CRUD, `quizzes.test_series_id` bundling (mirrors `Program`/`Batch`) | ⬜ not started |
| B13 | I10 pt.2 — `TestSeriesRankingService` (rank/percentile/topper, per-tenant) + student-facing results view | ⬜ not started |
| B14 | I11 pt.1 — `omr_templates`/`omr_sheet_scans` schema + app-generated bubble-grid layout (no freeform designer) | ⬜ not started |
| B15 | I11 pt.2 — `OmrScanner` (GD bubble-darkness reader) + roll-number-block matching + auto-grade into a real `QuizAttempt` | ⬜ not started |
| B16 | `TenantModelRegistry` sweep — all 7 new `BelongsToTenant` models confirmed registered | ⬜ not started |
| B17 | Full test coverage, review pass, `PROJECT-STATUS.md`/`HANDOVER.md`/`ROADMAP.md` update | ⬜ not started |

**Test suite:** 1406/1407 at slice start (1 pre-existing, unrelated
`CourseSlugUniquenessTest` failure carried since S1/S2, out of scope here
same as every prior slice). Design doc §5 estimates ~60-90 new tests by
B17 — a rough anchor, not a target; every prior slice's own estimate
undershot its actual by a wide margin.

---

## Block detail (expand as each block builds; stubs below are the starting checklist)

### B0 — `attendance_records.role` column — done

- Migration `2026_08_24_150000_add_role_to_attendance_records_table.php`:
  `role` string column on `attendance_records`, default `'student'`
  (backfills every existing row for free — all attendance to date is
  student attendance, confirmed before writing — no teacher-role writer
  exists before this block).
- `AttendanceRecord::ROLES = ['student', 'instructor']` constant added,
  `role` added to `$fillable`.
- No code change needed in any existing reader/writer (`RecordVideoCoverageAttendance`,
  `BatchSessionsRelationManager::markAttendance`) — grepped both
  `AttendanceRecord::create`/`updateOrCreate` call sites, neither passes
  `role`, so the new column's default covers them for free — zero
  regression risk to every existing S5/S8/S9 consumer.
- Foundation-only block, same posture S15 B0 took for
  `instructor_invitations` — B5/B6 (H13) are what actually write/read a
  `role = 'instructor'` row.
- `composer test`: 1406/1407 pass, migration applied clean. Sole failure is
  the pre-existing `CourseSlugUniquenessTest` (carried since S1/S2,
  unrelated, out of scope).

### B1 — H10: tenant-configurable at-risk threshold + staff view — done

- `tenants.at_risk_threshold_percent` nullable decimal migration.
  `StudentMetrics::isAtRisk(array $summary, User $student)` now takes the
  student so it can read `$student->tenant->at_risk_threshold_percent ??
  self::AT_RISK_THRESHOLD` — existing hardcoded 60.0 stays the fallback for
  every tenant that never sets one, zero blast radius. Both existing
  callers (`InstituteOverviewWidget`, `SendLowAttendanceAlert`) updated to
  pass `$student`; no existing test called `isAtRisk()` directly, so the
  signature change was safe.
- `App\Filament\Admin\Pages\AtRiskThresholdSettings` — same single-field
  settings-page shape `CustomDomainSettings` (S9 B4) already established;
  blank clears the override back to the default.
- New staff-facing "At-risk students" pages — `App\Filament\Admin\Pages\AtRiskStudents`
  (tenant-wide) and `App\Filament\Teach\Pages\AtRiskStudents` (scoped to the
  instructor's own assigned batches, reusing `StudentProgress`'s own
  `studentsQuery()` shape, S15 B2's `instructor_id` scoping). Both filter
  in PHP since `isAtRisk()` reads two computed/cached percentages, not
  queryable columns — same shape `InstituteOverviewWidget::atRiskCount()`
  already takes.
- New tests (8): `AtRiskThresholdSettingsTest` (save + clear), 2 new
  `StudentMetricsTest` cases (default fallback, tenant override respected),
  `AtRiskStudentsAdminPageTest` (roster + cross-tenant exclusion),
  `AtRiskStudentsTeachPageTest` (own-batch roster + cross-instructor
  exclusion). `composer test`: 1414/1415 pass, sole failure the
  pre-existing unrelated `CourseSlugUniquenessTest`.

### B2 — H11 pt.1: geofence schema + service — done

- `batch_geofences` migration (unique `batch_id`), `BatchGeofence` model
  (Batches), `Batch::geofence(): HasOne`, registered in
  `TenantModelRegistry`.
- `ImpossibleTravelDetector`'s own private `haversineKm()` extracted to
  `App\Support\Geo\HaversineDistance` (cross-domain shared code, per
  CLAUDE.md's Support/ convention — calling directly into a Security-domain
  service from Batches would have broken the "domains talk through Events
  only" rule) — `ImpossibleTravelDetector` itself refactored to call the
  shared version, no behavior change, no test referenced the old private
  method directly.
- `GeofenceCheckInService::isWithinRadius(BatchGeofence, lat, lng): bool`
  (Batches) uses the same shared formula, not a second hand-rolled copy.
- Admin-panel CRUD: a `geofence` modal `Action` on `BatchResource`'s table
  (admin-panel only, same instructor/admin split `canCreate()`/
  `getEloquentQuery()` already draw) — `fillForm` pre-loads the existing
  row, `updateOrCreate` keyed on `batch_id` so re-opening it edits rather
  than duplicates.
- New tests (7): `GeofenceCheckInServiceTest` (inside/outside/exact-center/
  boundary — a 0-radius geofence only accepts its exact center point), 2
  new `BatchResourceTest` cases (sets a geofence via the table action,
  re-saving updates rather than duplicates). `composer test`: 1421/1422
  pass (CrossTenantTest's generated suite auto-covers `BatchGeofence`
  isolation); sole failure the pre-existing unrelated
  `CourseSlugUniquenessTest`.

### B3 — H11 pt.2: student check-in UI — done

- `App\Livewire\GeofenceCheckIn` — `checkIn(lat, lng)` is called from the
  view via `navigator.geolocation.getCurrentPosition()` + `@this.call()`
  (no Alpine dependency); refuses with a visible `$error` (not a silent
  no-op) when outside the batch's own configured radius. No `BatchGeofence`
  row for the batch means geofencing is off — every check-in accepted
  (design doc §3.2's own opt-in framing).
- Writes `AttendanceRecord` via `updateOrCreate` keyed the same way
  `BatchSessionsRelationManager::markAttendance` already does
  (`attendable_type`/`attendable_id`/`user_id`) — `source =
  'geofence_checkin'`, `role = 'student'`, `status = 'present'` — then
  fires `AttendanceRecorded` so the existing S9 cache-bust/alert listeners
  pick it up same as any other writer. `AttendanceRecord::SOURCES` widened
  to include `geofence_checkin` (no DB CHECK constraint on `source` to
  migrate — the const is documentation only, not validated anywhere).
- No existing student-facing "batch session" page existed to embed this
  on (`JoinLiveClassController` just redirects away) — added a small host
  route (`GET /batch-sessions/{session}/check-in`, `routes/batches.php`,
  auth-required) + `batches.batch-session-check-in` view, same "no
  existing page, add a small host route" shape S15 B6/B7 already took.
- New tests (4): inside-radius writes a present/`geofence_checkin` record,
  outside-radius refused with the visible error and writes nothing,
  no-geofence-configured batch always accepts, a duplicate check-in
  updates the existing row rather than creating a second one. `composer
  test`: 1425/1426 pass; sole failure the pre-existing unrelated
  `CourseSlugUniquenessTest`.

### B4 — H12: generic device webhook — done

- `device_webhook_credentials` migration (`batch_id` nullable — null means
  "any batch") + `DeviceWebhookCredential` model (Batches), registered in
  `TenantModelRegistry`.
- `App\Http\Controllers\Batches\DeviceWebhookController` (route
  `POST /api/webhooks/device/{credential}`, no `Route::domain(...)`,
  same "no business logic in the controller" split as
  `ZoomRecordingWebhookController`) + `DeviceWebhookHandler` (Batches) —
  HMAC-SHA256 over `v0:{timestamp}:{body}` prefixed `v0=`, same scheme
  `ZoomRecordingWebhookHandler` established, verified against the
  looked-up credential's own `webhook_secret` (not one fixed app-wide
  secret, since each tenant configures its own).
- `user_identifier` resolves against `User.email`, explicitly scoped to
  the credential's own `tenant_id` — this is also what makes a
  correctly-signed request for tenant A's credential structurally unable
  to write into tenant B's attendance even naming a real tenant-B email,
  no special-case check needed.
- Build-time call the plan flagged as undecided: the payload carries no
  session reference, so the target `BatchSession` is resolved as the one
  currently-live session (`liveState() === STATE_LIVE`) among the batches
  the credential covers (its own `batch_id`, or every batch the user is
  actively enrolled in when null) that the user is actually enrolled in.
  No live match (off-hours, wrong device) is a silent no-op, not an
  error — a physical device firing outside class hours must not 500.
  Writes `AttendanceRecord` via the same `updateOrCreate` shape as B3's
  `GeofenceCheckIn`, `source = 'device_webhook'`, fires
  `AttendanceRecorded`. `AttendanceRecord::SOURCES` widened again.
- No Admin-panel CRUD for `DeviceWebhookCredential` in this block — not
  listed in the plan's own B4 checklist; a credential is provisioned via
  tinker/seeder for now, same as any other build-time gap this app has
  left for a later block/review pass to pick up if it turns out load-
  bearing.
- New tests (5): valid signature writes a present/`device_webhook`
  record, bad signature refused 401 and writes nothing, missing signature
  refused 401, unknown `user_identifier` no-ops without a fatal error, a
  correctly-signed request naming a different tenant's real user writes
  nothing (tenant-scoped lookup, not a special case). `composer test`:
  1431/1432 pass; sole failure the pre-existing unrelated
  `CourseSlugUniquenessTest`.

### B5 — H13 pt.1: teacher attendance capture + sweep — done

- `markMyAttendance` action added to `BatchSessionsRelationManager`,
  reusing `markAttendance`'s own `updateOrCreate` shape scoped to
  `role = 'instructor'`, one row not a roster. Teach-panel only
  (`Filament::getCurrentPanel()?->getId() === 'teach'`) — `BatchResource`'s
  own `getEloquentQuery()` already scopes every batch an instructor
  reaches to their own `instructor_id`, so this is always the viewer
  marking themselves.
- `TeacherAttendanceSweep` (Batches/Jobs) — unscoped daily job, same
  `CurrentTenant::clear()` shape `CheckCourseCompletionAndIssueCertificates`
  (S10)/`SuspendOverdueTenantSubscriptions` (S11) already established,
  scheduled in `bootstrap/app.php` alongside them. `unmarkedSessions()`
  filters `starts_at < now()` in SQL, then compares the computed end time
  (`starts_at + duration_minutes`) in PHP since it's not a stored column
  and this app's security baseline forbids raw-SQL query methods in
  `app/Domains/`. Detection only — `handle()` calls `unmarkedSessions()`
  but B6 is what turns each row into a `TeacherAttendanceFlag` (that
  table doesn't exist until B6).
- New tests (14): instructor marks their own session (`role=instructor`,
  fires `AttendanceRecorded`), idempotent re-run, action hidden on the
  Admin panel; sweep finds an unmarked-past-end session, skips one with an
  instructor row, skips one that hasn't ended yet, isn't fooled by a
  *student*-role row on the same session, `handle()` runs clean unscoped.
  Caught and fixed one real bug this block's own docblock introduced: a
  security-baseline regression test does a plain substring search for
  raw-SQL method names across every file in `app/Domains/` — the original
  docblock explaining *why* raw SQL wasn't used literally contained those
  two strings as text, tripping the guard on prose, not code. Reworded.
  `composer test`: 1439/1440 pass; sole failure the pre-existing unrelated
  `CourseSlugUniquenessTest`.

### B6 — H13 pt.2: flag + admin resolution — done

- `teacher_attendance_flags` migration (`instructor_id` is a stamp of the
  batch's own instructor at flag time, not a live reference — a later
  reassignment must not rewrite who the flag was originally raised
  against) + `TeacherAttendanceFlag` model, registered in
  `TenantModelRegistry`. `TeacherAttendanceSweep::handle()` (B5) now
  actually creates one per detected gap, `status = 'open'` — dedupes on
  `batch_session_id` regardless of the existing flag's status (a resolved
  flag doesn't retroactively backfill an instructor attendance row, so
  without the dedupe the same gap would re-flag forever); skips a session
  whose batch has no assigned instructor at all. Same `withoutEvents()` +
  explicit `tenant_id` shape `RecordVideoCoverageAttendance` (S5 B7)
  already established for writing a `BelongsToTenant` row from this
  unscoped sweep.
- `App\Notifications\TeacherAttendanceFlagged` — database-only (no mail/
  WhatsApp, an internal admin worklist item not a customer alert, same
  posture `MessageReportResource`'s moderation queue already takes), sent
  to every institute_admin for the tenant (`GracePeriodReminder`'s own
  "no dedicated recipient concept" reasoning). Picked up for free by the
  existing `NotificationCentre` (S8 B7, already rendered on every panel's
  topbar) — added one `summarize()` case, no new render-hook registration
  needed.
- `App\Domains\Batches\Filament\Resources\TeacherAttendanceFlags\TeacherAttendanceFlagResource`
  — browse-only, same shape `MessageReportResource` already established
  (no create/edit pages). Auto-discovered on the Admin panel only (its own
  `discoverResources` directory scan on `Domains/Batches/Filament/Resources`;
  Teach panel lists Batches resources explicitly and doesn't include it).
  `resolve` action closes the flag and, if an institute_admin picks a
  `reassign_instructor_id`, updates the session's batch `instructor_id` in
  the same action (S15's own field, no new reassignment mechanism) —
  otherwise the flag just closes with no batch change.
- New tests (7): sweep `handle()` creates an open flag + notifies every
  institute_admin, doesn't re-flag an already-flagged session (even a
  resolved one), skips a session with no assigned instructor; admin queue
  lists a sweep-created flag, resolves without reassignment (instructor
  unchanged), resolves with a reassignment (`Batch::instructor_id`
  updated), resolve action hidden once already resolved. `composer test`:
  1446/1447 pass; sole failure the pre-existing unrelated
  `CourseSlugUniquenessTest`.

### B7 — I6 pt.1: extended question types schema + grading — not started

- `Question::TYPES` widened to include `multi_select`, `fill_in_blank`,
  `matching`, `ordering` (5 types before this block: mcq, true_false,
  short_answer, numerical → 9 after).
- `QuizAttemptService::grade()` gains one `match` arm per new type,
  implementing design doc §4 decision 3's exact partial-credit fractions.
- No new columns — `correct_answer`'s existing jsonb column carries each
  new type's own shape (§4 decision 2).
- New tests: `QuizAttemptServiceTest` additions — one grading test per new
  type proving exact partial-credit scores for a partially-correct
  response, plus a fully-correct and fully-wrong case each.

### B8 — I6 pt.2: Filament UI + QuizRunner rendering — not started

- `NormalizesQuestionFormData` gains form widgets for each new type
  (multi-select checkboxes with correct-answer marking, fill-in-blank
  accepted-answers repeater, matching pairs builder, ordering list).
- `QuizRunner` gains a rendering branch per new type for the student-facing
  attempt UI (checkbox group, text input, drag-or-select pairing, a
  reorderable list).
- New tests: creating each new question type through the real Filament
  form, a student attempting each type through `QuizRunner` and the
  response persisting correctly to `QuizAnswer.response`.

### B9 — I8 pt.1: proctoring event capture — not started

- `quiz_proctoring_events` migration + model, registered in
  `TenantModelRegistry`.
- `QuizRunner` gains client-side listeners (Page Visibility API for
  tab-switch, Fullscreen API for fullscreen-exit, `copy`/`paste` DOM
  events) dispatching a Livewire call that writes an event row — framed to
  the instructor-facing review UI as detection/evidence, not prevention
  (design doc §4 decision 4).
- Only fires when the owning `Quiz` has proctoring enabled (a new
  `quizzes.proctoring_enabled` boolean, default false — opt-in per quiz,
  same posture `print_disabled` (S14) took for a similar browser-deterrent
  toggle).
- New tests: an enabled quiz logs each event type once triggered, a
  disabled quiz logs nothing, events are tenant/attempt-scoped correctly.

### B10 — I8 pt.2: per-question timing — not started

- `quiz_answers.time_spent_seconds` nullable int migration.
  `QuizAttemptService::saveAnswer()` (the existing S9 B7 autosave path)
  accepts and persists a client-reported elapsed-seconds value per
  question, accumulated not overwritten on repeated saves to the same
  question.
- New tests: repeated `saveAnswer()` calls for the same question accumulate
  time rather than resetting it, a never-visited question stays null not
  zero.

### B11 — I9: violation threshold → auto-submit + flag — not started

- `quiz_attempts.flagged_for_review` boolean migration, default false.
- A new config value (`config('assessments.proctoring_violation_threshold')`,
  default 3) — once an in-progress attempt's own `quiz_proctoring_events`
  count crosses it, `QuizAttemptService::submit($locked, [], 'auto_submitted')`
  fires (the exact same call `AutoSubmitExpiredQuizAttempts` already makes
  for time-expiry, design doc §4 decision 5) and `flagged_for_review` is
  set true.
- Instructor-facing review queue — a filter on the existing
  `QuizAnswerResource`/a new small Filament page listing
  `flagged_for_review = true` attempts, surfacing the event log alongside
  the responses.
- New tests: crossing the threshold auto-submits and flags, staying under
  it leaves the attempt in progress, a time-expiry auto-submit (unrelated
  to violations) never sets the flag.

### B12 — I10 pt.1: test series schema + bundling — not started

- `test_series` migration + model, registered in `TenantModelRegistry`.
  `quizzes.test_series_id` nullable FK migration.
- Admin-panel `TestSeriesResource` CRUD; `QuizResource`'s form gains an
  optional `test_series_id` select, same "bundle via an FK on the bundled
  thing" shape `Program`/`fee_invoices.program_id` (S13) already
  established.
- New tests: creating a series, assigning quizzes to it, a quiz with no
  series stays unaffected by every existing Quiz test.

### B13 — I10 pt.2: ranking + student results view — not started

- `TestSeriesRankingService::rankingsFor(TestSeries, User $viewer): Collection`
  — per student in the series's own tenant, sums best-attempt score per
  quiz in the series, sorts descending in PHP (design doc §4 decision 6 —
  no `DB::raw` window function), computes rank + percentile + the top
  scorer's own score for comparison.
- Student-facing results view (a bare host route/Livewire component, same
  "no existing page to embed on, add a small host route" shape B6/B7 of
  S15 already established) showing the viewer's own rank/percentile/gap-
  to-topper, not the full roster (privacy — a student sees their own
  standing, not classmates' names, matching this app's existing "no
  cross-student data exposure" posture).
- New tests: two students across two quizzes in one series rank/percentile
  correctly, a student who attempted only one of the two quizzes in the
  series is scored fairly (best-effort partial participation, exact rule
  decided at build time and documented), cross-tenant students in a
  same-named series never mix (belt-and-suspenders on top of the generated
  `CrossTenantTest`).

### B14 — I11 pt.1: OMR schema + template layout — not started

- `omr_templates`/`omr_sheet_scans` migrations + models, registered in
  `TenantModelRegistry`.
- `OmrTemplate::generateLayout()` — deterministic bubble-grid coordinate
  computation from `question_count`/`options_per_question`/
  `roll_number_digits` (design doc §4 decision 8 — no freeform designer),
  stored in `layout` jsonb.
- Admin-panel action on `QuizResource` — "Generate OMR template" (creates
  the template + a printable PDF via the existing `dompdf` dependency, same
  reuse posture `IdCardGenerator` (S15) already took).
- New tests: template layout generation is deterministic and covers every
  question/option/roll-number-digit position with no overlaps.

### B15 — I11 pt.2: scanner + auto-grade — not started

- `OmrScanner::scan(OmrSheetScan): array` — GD-based: loads the uploaded
  image, samples pixel darkness at each of the template's own known
  coordinates, resolves the darkest-bubble-per-question/per-roll-number-
  digit (design doc §4 decision 8/9). No skew/rotation correction (flagged
  limitation, design doc §5).
- Roll-number digits resolve to an `Enrolment.id` within the quiz's own
  batch; a non-matching roll number sets `status = 'failed'` for manual
  admin resolution (§4 decision 9), never silently dropped.
- On a successful match, writes a real `QuizAttempt` + `QuizAnswer` rows
  (reusing `QuizAttemptService`'s existing grade methods per question type)
  so an OMR-graded offline test appears in the exact same Gradebook/
  reports every online attempt already does — no parallel "offline score"
  concept.
- Admin-panel upload action + status list (mirrors `StudentImportBatchResource`'s
  own upload-then-status-list shape, S15 B9/B10).
- New tests: `OmrScannerTest` — a synthetically-generated bubble-sheet
  image (drawn by the test at the template's own coordinates) round-trips
  to the exact expected answers and roll number; a failed roll-number match
  queues for manual resolution instead of crashing; a successful scan
  produces a real gradeable `QuizAttempt`.

### B16 — `TenantModelRegistry` sweep — not started

- Confirm all 7 new `BelongsToTenant` models (`BatchGeofence`,
  `DeviceWebhookCredential`, `TeacherAttendanceFlag`, `QuizProctoringEvent`,
  `TestSeries`, `OmrTemplate`, `OmrSheetScan`) present in
  `TenantModelRegistry::MODELS` with `seedAttributes()`/`updateAttributes()`
  entries.
- Reverse sweep (not just the 7 named ones) — grep every
  `app/Domains/*/Models/*.php` for real `BelongsToTenant` trait usage
  against `MODELS`' own entries, same discipline S14 B17/S15 B20 already
  established.

### B17 — Full test coverage, review pass — not started

- Lifecycle E2E test(s) mirroring S11 B16/S12 B14/S13 B17/S14 B18/S15 B21's
  own posture — at minimum: a teacher's session goes unmarked past its end
  time → flagged → admin resolves by reassigning the instructor, chained in
  one run; plus a full test-series lifecycle — two quizzes bundled → two
  students attempt both → rank/percentile computed correctly for both.
- Explicit review pass across every B0-B16 file for real bugs, not just a
  green suite — same discipline S9 B14/S10 B15/S11 B16/S12 B14/S13 B17/S14
  B18/S15 B21 each already established (each found real bugs this way).
- Update `HANDOVER.md` (S16 complete entry + any new `## Avoid` entries),
  `docs/PROJECT-STATUS.md` (new S16 section), `docs/ROADMAP.md` (S16 row
  → Done, next proposed slice's own row unaffected).
