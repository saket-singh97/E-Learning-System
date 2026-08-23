# Implementation Plan: S15 Batch & Institute Operations

Full design: `2026-08-22-s15-batch-institute-operations-design.md`. This is
the block-by-block build log — update the PROGRESS table as each block
lands, same as every S1-S14 plan did. 22 blocks (B0-B21), finer-grained
than the 10-item real build scope since several items split into
independent blocks (instructor onboarding split across three, timetable
split in two, forum split in two, feedback split in two, bulk CSV import
split in two, bulk background jobs split in two, admission CRM split in
two, noticeboard split in two, reports split in two).

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-22-s15-batch-institute-operations-design.md and
docs/superpowers/specs/2026-08-22-s15-batch-institute-operations-plan.md, then build
Block 0 of the S15 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | Instructor invitation + assignment schema — `instructor_invitations`, `batches.instructor_id` (closes §S.S1's real gap, foundation for F7/§S.S2/F10 pt.2) | ✅ done |
| B1 | Instructor invitation flow — Admin-panel invite/accept/assign/deactivate/reactivate | ✅ done |
| B2 | Per-instructor permission enforcement (§S.S2) — Teach panel + policies scoped to only the instructor's own assigned batches | ✅ done |
| B3 | Timetable templates schema — `timetable_templates` (F7 pt.1) | ✅ done |
| B4 | Teacher conflict detection — write-time overlap check on `BatchSession` create/edit against the assigned instructor's other sessions (F7 pt.2) | ✅ done |
| B5 | Discussion forum schema — `forum_threads`/`forum_posts`, depth-capped at 3 (F6 pt.1) | ✅ done |
| B6 | Discussion forum UI — batch-scoped Livewire component, enrolment-gated (F6 pt.2) | ✅ done |
| B7 | Batch feedback schema + student submission — `batch_feedback` (F8 pt.1) | ✅ done |
| B8 | Batch feedback aggregation view — instructor/admin-facing average + comments (F8 pt.2) | ✅ done |
| B9 | Bulk student CSV import — schema + parser/validator — `student_import_batches` (F9 pt.1) | ✅ done |
| B10 | Bulk student CSV import — queued creation job + downloadable per-row error report (F9 pt.2) | ✅ done |
| B11 | Bulk enrolment as a background job — thin `ShouldQueue` wrapper around `BatchEnrolmentService::addManually()` (F10 pt.1) | ✅ done |
| B12 | Bulk instructor invitations as a background job — thin `ShouldQueue` wrapper around B1's own invitation path (F10 pt.2) | ✅ done |
| B13 | Admission CRM schema — new `Operations` domain, `enquiries` (§S.S4 pt.1) | ✅ done |
| B14 | Admission CRM follow-up + convert-to-enrolment — `enquiry_follow_ups`, real `Enrolment` on convert (§S.S4 pt.2) | ✅ done |
| B15 | ID card generation — `id_cards`, dompdf + qr-code reuse from `Certificate`'s own precedent (§S.S5) | ✅ done |
| B16 | Noticeboard schema + authoring — `notices`, role-targeted, Admin-panel compose (§S.S6 pt.1) | ✅ done |
| B17 | Noticeboard display + read tracking — `notice_read_receipts`, portal/panel banner (§S.S6 pt.2) | ⬜ not started |
| B18 | Enrolment report export — CSV job, `ExportAttendanceRegister`-shaped (§S.S3 pt.1, real gap-close) | ⬜ not started |
| B19 | Completion report export — CSV job, same shape (§S.S3 pt.2, real gap-close) | ⬜ not started |
| B20 | `TenantModelRegistry` sweep — all 11 new `BelongsToTenant` models confirmed registered | ⬜ not started |
| B21 | Full test coverage, review pass, `PROJECT-STATUS.md`/`HANDOVER.md`/`ROADMAP.md` update | ⬜ not started |

**Test suite:** 1283/1284 at slice start (1 pre-existing, unrelated
`CourseSlugUniquenessTest` failure carried since S1/S2, out of scope here
same as every prior slice). Design doc §5 estimates ~70-100 new tests by
B21 — a rough anchor, not a target; every prior slice's own estimate
undershot its actual by a wide margin.

---

## Block detail (expand as each block builds; stubs below are the starting checklist)

### B0 — Instructor invitation + assignment schema — done

- `instructor_invitations` migration (`tenant_id`, `email`, `token` unique,
  `invited_by_user_id`, `status` pending|accepted|expired|revoked,
  `expires_at`, `accepted_user_id` nullable).
- `batches.instructor_id` nullable FK migration (design decision 1 — one
  primary instructor per batch, not a pivot).
- `InstructorInvitation` model, `BelongsToTenant`, registered in
  `TenantModelRegistry` (seed/update attributes included).
- `Batch::instructor()` relation added alongside the new fillable column.
- `composer test`: 1285 tests, 1284 pass, 1 pre-existing unrelated
  `CourseSlugUniquenessTest` fail (carried since S1/S2, out of scope).

### B1 — Instructor invitation flow — done

- Admin-panel invite action (email + course/batch assignment at invite
  time), 14-day token expiry, same host-agnostic-callback shape
  `ZoomOAuthController`/`ParentInviteRedemption` already established for
  a token-carries-context flow.
- Accept flow creates the `User` (or links an existing one) with the
  `instructor` role and sets `batches.instructor_id` for every assigned
  batch.
- Deactivate/reactivate action on an accepted instructor (closes the
  remaining half of §S.S1's original, never-built scope).
- New: `batch_ids` jsonb column on `instructor_invitations` (invite-time
  selection, migration `2026_08_22_100002`); `users.is_active` boolean
  (migration `2026_08_22_100003`), `User::canAccessPanel()` and
  `TenantAuthenticator::validate()` both gate on it — covers every panel
  and the student/parent portal login alike.
- `InstructorInvitationService` (Tenancy) — `invite()`, `accept()`
  (rebinds `CurrentTenant` from the token same as
  `ParentInviteCodeService::redeem()`), `deactivate()`/`reactivate()`.
- `InstructorInvitationMail` — first on-demand (`Notification::route`)
  notification in this app, since no `User` row exists yet for the
  invitee.
- Host-agnostic accept route + `InstructorInvitationAccept` Livewire
  component (`routes/web.php`, same shape as `ParentInviteRedemption`).
- `InstructorInvitationResource` (Admin panel) — lives under
  `Domains/Tenancy/Filament/Resources`, which Admin/Teach/Super panels
  all discover; `canAccess()` overridden to Admin-only since the shared
  directory has no per-panel split otherwise.
- Real bug found + fixed in review: `UserFactory` never set `is_active`,
  so a factory-built `User` kept it `null` in memory (DB `default(true)`
  never round-trips without a re-fetch) — `canAccessPanel()`'s strict
  `bool` return type crashed the first Filament-panel HTTP test with a
  `TypeError`. Fixed by setting `is_active => true` in both `UserFactory`
  and `InstructorInvitationService::accept()`'s own `User::create()`.
- `composer test`: 1293 tests, 1292 pass, 1 pre-existing unrelated
  `CourseSlugUniquenessTest` fail (unchanged from B0), 0 new errors.

### B2 — Per-instructor permission enforcement (§S.S2) — done

- Verified before coding (this block's own audit, not assumed from the
  plan's own wording): `BatchResource` was **not registered in
  `TeachPanelProvider` at all** — instructors had zero Teach-panel route
  to `Batch`/`BatchSession`/`Enrolment` before this block, not merely an
  unscoped directory-wide leak. This is *why*
  `EnrolmentApprovalPolicy::DECIDER_ROLES` already listed `'instructor'`
  with no way to reach it — a half-built gap from before this slice,
  same shape as §S.S1's own never-built invitation UI. `Question`/`Quiz`/
  `Assignment`/`AssignmentSubmission` all trace to `Course`, which design
  decision 1 deliberately leaves un-scoped (no per-course instructor
  assignment) — only `BatchResource` needed touching.
- `TeachPanelProvider` — added `BatchResource::class` to the same
  explicit `->resources()` array Content's own S13 B17 fix already uses
  (not a directory-wide `discoverResources()`, consistent with that
  precedent).
- `BatchResource::getEloquentQuery()` — panel-conditional: Teach scopes
  to `where('instructor_id', auth()->id())`, Admin unchanged (full
  tenant view). Unconditional scoping would have hidden every batch from
  institute_admin, since admins are essentially never a batch's own
  `instructor_id` — caught before writing the fix, not after.
  `canCreate()` and the table's destructive actions
  (Delete/ForceDelete/Restore) are Admin-only; Teach keeps `EditAction`
  only, since instructors reach their own batch to schedule sessions and
  decide enrolments via the two existing relation managers, not to
  create/delete batches.
- `EnrolmentApprovalPolicy::canDecide()` tightened: an `instructor` actor
  must be *that batch's own* `instructor_id`, not just same-tenant
  (institute_admin keeps the same-tenant check). Found + fixed a real
  pre-existing test asserting the old (wrong) behavior
  (`EnrolmentApprovalPolicyTest`'s "allows an instructor in the batch
  tenant to decide") — updated to assign the batch first, plus a new
  test proving a same-tenant-but-unassigned instructor is denied.
- `BatchSessionsRelationManager`/`EnrolmentsRelationManager` needed no
  changes themselves — both resolve through the owner `Batch` record,
  which Filament fetches via the now-scoped `getEloquentQuery()`, so an
  instructor can't reach another instructor's batch to begin with
  (verified with a 404 test, not just trusted).
- New `tests/Feature/Batches/TeachPanelBatchScopingTest.php` (5 tests):
  Teach-panel list scoping, cross-instructor edit 404, Admin panel
  unaffected, Teach create forbidden, assigned instructor can approve an
  enrolment end-to-end through the Teach panel.
- Also found + fixed while touching `EnrolmentApprovalPolicy`: `StudentProgress`
  (Teach panel page) had the exact same stale "read as same-tenant, no
  real instructor link" posture in its own docblock, showing every
  active enrolment in the tenant to every instructor — tightened to the
  instructor's own assigned batches too, same fix, same block.
- Several pre-existing S14 tests (`LivePollBoardTest`,
  `LivePollServiceTest`, `StudentProgressPageTest`,
  `S14VideoLiveDepthE2ETest`) built batches without `instructor_id` and
  asserted the *old* same-tenant-instructor behavior via
  `LivePollService`/`EnrolmentApprovalPolicy`'s shared `canDecide()` —
  updated each to assign the batch to its own test instructor, not
  loosened.
- `composer test`: 1299 tests, 1298 pass, 1 pre-existing unrelated
  `CourseSlugUniquenessTest` fail (unchanged since B0), 0 errors.

### B3 — Timetable templates schema — done

- `timetable_templates` migration (`tenant_id`, `title`, `day_of_week`,
  `starts_at` time, `duration_minutes`).
- `TimetableTemplate` model, registered in `TenantModelRegistry`.
- Admin-panel CRUD — `TimetableTemplateResource` under
  `Domains/Batches/Filament/Resources/`. Deliberately left off
  `TeachPanelProvider`'s own explicit `->resources()` list (added in B2)
  rather than directory-wide discovery — Admin-only per this block's own
  plan text, no code needed to enforce it since Teach simply never
  registers the route.
- `tests/Feature/Batches/TimetableTemplateResourceTest.php` (3 tests):
  create, tenant-scoped listing, confirmed unreachable from Teach.
- `composer test`: 1303 tests, 1302 pass, 1 pre-existing unrelated
  `CourseSlugUniquenessTest` fail (unchanged since B0), 0 errors.

### B4 — Teacher conflict detection — done

- `TeacherConflictDetector` service (`app/Domains/Batches/Services/`) —
  pure `wouldConflict()` check, PHP-side window comparison over every
  `BatchSession` sharing the batch's own `instructor_id` (no `DB::raw`/
  `whereRaw`, same bounded-not-optimized posture `PrerequisiteGraph`'s
  own DFS already established). Returns false with no `instructor_id`.
- Wired into `BatchSessionsRelationManager`'s `starts_at` field via
  `->rule()`, same "a `fail()` message reads better than a caught
  exception mid-submit" idiom `CourseResource`/`LessonResource` already
  use for `PrerequisiteGraph` — covers both create and edit since the
  relation manager's `form()` is shared by both actions. Excludes the
  record's own id on edit so re-saving a session's unchanged slot doesn't
  flag itself.
- "Create from template" — a non-persisted `from_template` Select
  (`dehydrated(false)`, `hiddenOn('edit')`) prefills `starts_at`
  (next calendar occurrence of the template's `day_of_week`, at its
  time) and `duration_minutes` into the real fields; no FK stored
  (design decision 3).
- New tests: `TeacherConflictDetectorTest` (5, service-level) +
  `BatchSessionConflictTest` (4, through the relation manager: refuses
  overlap, allows non-overlap, allows re-saving own slot, template
  prefill).
- Caught by `composer test`, not by review: `TeacherConflictDetector`'s
  own docblock literally spelled out the two forbidden call shapes it was
  avoiding — `SecurityBaselineTest`'s raw-SQL guard is a naive
  `str_contains()` file scan, not AST-aware, so the comment text itself
  tripped it. Reworded to describe the constraint without repeating the
  literal banned strings.
- `composer test`: 1312 tests, 1311 pass, 1 pre-existing unrelated
  `CourseSlugUniquenessTest` fail (unchanged since B0), 0 errors.

### B5 — Discussion forum schema — done

- `forum_threads`/`forum_posts` migrations (`forum_posts.parent_id`
  self-referential nullable + `cascadeOnDelete()`, `depth` 0-2).
- `ForumThread`/`ForumPost` models (Comms domain), registered in
  `TenantModelRegistry`. `ForumThread::batch()` is a cross-domain model
  read (Comms -> Batches), same posture `Batch::course()` already
  established (Batches -> Content).
- `ForumPostComposer` — single write path for `forum_posts`, same
  "one shared path" posture `ParentInviteCodeService`/
  `InstructorInvitationService` already use. Depth is derived from the
  parent's own stored `depth` column, refused before persisting (not
  silently flattened) via new `ForumReplyDepthExceededException`
  (409, registered in `bootstrap/app.php`) — schema-block deliverable
  per this block's own plan text; B6 wires the actual UI around it.
- New `tests/Feature/Comms/ForumPostComposerTest.php` (3 tests): depth-0
  post, nested replies to depth 2, depth-3 attempt refused.
- `composer test`: 1317 tests, 1316 pass, 1 pre-existing unrelated
  `CourseSlugUniquenessTest` fail (unchanged since B0), 0 errors.

### B6 — Discussion forum UI — done

- Found no existing student-facing per-batch page to embed on (only
  `batch-catalog` exists) — added a bare host route/view
  (`routes/comms.php` `/batches/{batch}/forum`, closure + `$request->
  route('batch')`, CLAUDE.md's domain-route splicing gotcha) same shape
  `/messages`/`comms.messages` already established, no new controller.
- `App\Domains\Comms\Livewire\BatchForum` — thread list, open-thread post
  tree (nested to depth 2, flat query grouped in PHP, small recursive
  Blade partial `comms::forum-post` for rendering since the depth is
  bounded), create-thread + reply composer.
- `canParticipate()` gate: active `Enrolment`, or the batch's own
  assigned `instructor_id`, or `institute_admin` — same staff-bypass
  shape `LessonAccess::canJoinLive()` already established, re-checked on
  every action (not just page load), same reasoning `LessonQuestions`'s
  own docblock documents for its `/livewire/update` exposure.
  `institute_admin`/assigned-instructor bypass mirrors B2's own
  per-instructor tightening (broad tenant-wide instructor access was
  exactly what B2 closed elsewhere), not the older course-level "any
  staff in tenant" posture.
- Depth-3 attempt surfaces as a real Livewire field error
  (`ForumPostComposer`'s exception caught and re-shown via
  `addError()`), not silently dropped.
- New `tests/Feature/Comms/BatchForumTest.php` (5 tests): enrolled
  student posts, assigned instructor bypasses enrolment, non-enrolled
  student no-ops, guest no-ops, depth-2 replies succeed + depth-3
  refused with a visible error.
- `composer test`: 1322 tests, 1321 pass, 1 pre-existing unrelated
  `CourseSlugUniquenessTest` fail (unchanged since B0), 0 errors.

### B7 — Batch feedback schema + student submission — done

- `batch_feedback` migration (unique on `batch_id`+`user_id`, `rating`
  1-5 DB `CHECK`, same `CourseReview` precedent from S13).
- `BatchFeedback` model (Batches domain), registered in
  `TenantModelRegistry`.
- `BatchFeedbackPolicy::canSubmit()` — same active-or-expired "genuine
  participation" gate `CourseReviewPolicy::canReview()` already
  establishes, scoped directly by `batch_id` (no `whereHas` needed,
  unlike the course-level version).
- `BatchFeedbackForm` Livewire component, same `CourseReviews` shape —
  `updateOrCreate` so a resubmission updates the existing row instead of
  erroring on the unique constraint. No student-facing batch page
  existed yet (same gap B6 hit) — bare host route
  `/batches/{batch}/feedback` in `routes/batches.php`, same shape as
  B6's own `/batches/{batch}/forum`.
- New tests: `BatchFeedbackPolicyTest` (4) + `BatchFeedbackFormTest` (4).
- `composer test`: 1331 tests, 1330 pass, 1 pre-existing unrelated
  `CourseSlugUniquenessTest` fail (unchanged since B0), 0 errors.

### B8 — Batch feedback aggregation view — done

- `Batch::feedback()` relation + `FeedbackRelationManager` (read-only —
  no create/edit/delete, feedback is student-submitted only per B7),
  registered on `BatchResource` alongside the sessions/enrolments
  relation managers. Reuses B2's own panel scoping for free: same
  `getRelations()` list is reachable from both Admin (every batch) and
  Teach (the assigned instructor's own batch only), no new scoping code.
- Filament's `Average` column summarizer on the `rating` column — the
  aggregation itself, not a hand-rolled query.
- New `tests/Feature/Batches/FeedbackRelationManagerTest.php` (2 tests):
  admin sees the list, the batch's own assigned instructor sees it via
  the Teach panel.
- `composer test`: 1333 tests, 1332 pass, 1 pre-existing unrelated
  `CourseSlugUniquenessTest` fail (unchanged since B0), 0 errors.

### B9 — Bulk student CSV import — schema + parser — done

- `student_import_batches` migration (`status`, `total_rows`,
  `success_count`, `error_count`, `error_report_disk_path` nullable).
  `success_count`/`error_report_disk_path` stay unset until B10's queued
  job actually runs — phase 1 only ever writes `total_rows`/`status`/
  `error_count` (validation-time errors, not creation-time ones).
  `batch_id` deliberately isn't a column here (matches the design doc's
  own literal schema) — the CSV's own `batch` column (a slug) lets one
  upload span multiple batches, resolved per row.
- `StudentImportBatch` model, registered in `TenantModelRegistry`.
- `StudentCsvImporter::parse()` — file-shape refusal (bad headers, no
  data rows) throws `InvalidStudentImportFileException` (422) before any
  row is even written, same "immediate feedback, no queue wait" reason
  design decision 6 gives. Per-row errors (missing name, invalid email,
  unknown batch slug, duplicate email within the file) don't refuse the
  whole file — returned alongside the valid rows for B10's own upload
  action to hand to the queued creation job. Plain CSV parsing
  (`str_getcsv`), not `Excel::import()` — this app's first real import,
  per the design doc's own verification that `maatwebsite/excel` has
  never been used for import here.
- New `tests/Feature/Batches/StudentCsvImporterTest.php` (4 tests): a
  valid file parses and resolves batches correctly, bad headers refuse
  before writing any row, an empty file refuses, and a mixed file
  produces the exact right per-row errors without dropping valid rows.
- `composer test`: 1338 tests, 1337 pass, 1 pre-existing unrelated
  `CourseSlugUniquenessTest` fail (unchanged since B0), 0 errors. First
  run failed with connection-refused errors — Docker Desktop wasn't
  running (unrelated to this block's own code); started it and reran.

### B10 — Bulk student CSV import — queued job + error report — done

- `ImportStudentsFromCsv` (`ShouldQueue`) — same tenant-rebind/disk-write
  shape `ExportAttendanceRegister` already established (bare `tenant_id`
  constructor property + `RebindsTenant` middleware). Unlike that job,
  not blind-retry-safe (a re-run would duplicate Users/Enrolments), so
  each row gets its own try/catch instead of leaning on queue retry.
  `findOrCreateStudent()`: existing same-tenant email reused as-is (no
  role re-assignment, doesn't demote an existing instructor/admin);
  existing email under a *different* tenant errors (email is globally
  unique, same finding B1 already made); a genuinely new `User` gets the
  `student` role via `RoleAssignmentPolicy::assign()`, the one
  chokepoint every real role grant already passes through.
  `BatchEnrolmentService::addManually()` needed no duplicate pre-check —
  confirmed by reading it: it already absorbs a duplicate Enrolment
  internally, no exception to catch there.
- Error report combines phase-1 (`StudentCsvImporter`'s own validation
  errors, carried through the job's constructor) with phase-2 failures
  into one sorted CSV, `EscapesCsvFormulas`-guarded, written to
  `Storage::disk('local')`. `student_import_batches.status`/
  `success_count`/`error_count`/`error_report_disk_path` all set once,
  at the end of `handle()`.
- `DownloadStudentImportErrorReportController` — same gated shape
  `DownloadRevenueExportController` already established (institute_admin
  role check + `role:institute_admin` route middleware, CLAUDE.md's
  double-check posture); `{importBatch}` resolved via `$request->route()`
  per the domain-route splicing gotcha, not a type-hinted parameter.
- `StudentImportBatchResource` (Admin panel) — upload (`FileUpload` ->
  synchronous phase 1 in `handleRecordCreation()`, always dispatches
  phase 2 even with zero valid rows so there's exactly one place that
  writes the final report) + status list with a conditional "download
  error report" row action. Admin-only by omission from Teach's explicit
  resource list, same precedent B3/B9 already established — no
  `canAccess()` override needed.
- New tests: `ImportStudentsFromCsvTest` (4, job-level — new student,
  existing-user linking, cross-tenant email refusal, combined+sorted
  error report), `DownloadStudentImportErrorReportControllerTest` (3),
  `CreateStudentImportBatchTest` (1, end-to-end through the real
  Filament upload page).
- Caught by the test run: `->fillForm(['csv' => $tempPath])` failed
  inside Filament's own `getValidationRules()` — `FileUpload`'s state is
  always an array (uuid-keyed), never a bare string, even when
  simulating an already-uploaded file. Fixed by wrapping the path in an
  array; `handleRecordCreation()`'s own `is_array($data['csv']) ?
  array_values(...)[0] : ...` already handled either shape.
- `composer test`: 1346 tests, 1345 pass, 1 pre-existing unrelated
  `CourseSlugUniquenessTest` fail (unchanged since B0), 0 errors.

### B11 — Bulk enrolment as a background job — done

- Verified before coding: design decision 7 names "seat-limit
  `lockForUpdate()`" as a guarantee this wrapper inherits, but
  `addManually()` itself has none — no lock, no seat check, lands
  `active` unconditionally (confirmed by reading the whole service, not
  assumed). That lock belongs to `enrolDirect()`/`approve()`, not the
  staff-add path. Reusing `addManually()` here inherits *that* exact
  existing behavior — same as the single-row "Add student" action
  already has — not a new gap.
  `BulkEnrolStudents` (`ShouldQueue`, `RebindsTenant`) — one
  `addManually()` call per email; unmatched/cross-tenant emails are
  skipped (logged), not created — that's F9's own job, not this one's.
  No status/error-report table (unlike B9/B10) — this block's own plan
  text scopes it to just the queued wrapper.
- New "Bulk enrol" header action on `EnrolmentsRelationManager`
  (alongside the existing single "Add student" one, not replacing it) —
  a newline-separated email `Textarea`, dispatches the job through the
  same `authorizedActor()` gate the single-add action already uses.
- New tests: `BulkEnrolStudentsTest` (4, job-level) +
  `EnrolmentsRelationManagerTest` (1 more, through the real header
  action).
- `composer test`: 1351 tests, 1350 pass, 1 pre-existing unrelated
  `CourseSlugUniquenessTest` fail (unchanged since B0), 0 errors.

### B12 — Bulk instructor invitations as a background job — done

- `App\Domains\Operations\Jobs\InviteInstructorsFromEmailList` — this
  slice's first file in the new `Operations` domain (design doc §3.1:
  "the bulk job itself is an operations concern" even though it
  dispatches through Tenancy's own `InstructorInvitationService`), one
  block ahead of B13's own scaffold-building — just the `Jobs/`
  subfolder exists for now, B13 adds the rest (`Models/`, `Filament/`
  etc. for the admission CRM). Same thin-wrapper posture `BulkEnrolStudents`
  (B11) already established — one `invite()` call per email, no
  duplicate-pending-invite guard added (design decision 8: "not a
  separate bulk-specific invitation code path" — adding one would be
  exactly that).
- New "Bulk invite" header action on `ListInstructorInvitations`
  (alongside the existing single `CreateAction`, not replacing it) —
  emails `Textarea` + the same batch-assignment `Select` the single-invite
  form already uses, all invited emails get the same batches.
- New tests: `InviteInstructorsFromEmailListTest` (1, job-level, asserts
  both invitations created and both notifications sent) +
  `InstructorInvitationTest` (1 more, through the real header action).
- `composer test`: 1353 tests, 1352 pass, 1 pre-existing unrelated
  `CourseSlugUniquenessTest` fail (unchanged since B0), 0 errors.

### B13 — Admission CRM schema — done

- `Operations` domain's `Jobs/` folder already existed (B12); this block
  adds `Models/` and `Filament/` — no manual scaffold/namespace step
  needed, `AppServiceProvider`'s own `glob(Domains/*)` view-namespace
  registration and Filament's `discoverResources()` both just work once
  the directories exist.
- `enquiries` migration — full design doc §3.2 schema (`name`, `email`,
  `phone` nullable, `course_id` nullable, `status`
  new|contacted|follow_up|converted|lost, `source` nullable,
  `assigned_to_user_id` nullable, `converted_enrolment_id` nullable —
  the plan bullet's own list was a subset, design doc's is authoritative).
- `Enquiry` model, registered in `TenantModelRegistry`.
- `EnquiryResource` (Admin + Teach panels, directory-wide discovery on
  both) — enquiries are tenant-wide CRM data, not per-instructor-scoped
  like `Batch`, so B2's own reason for switching Content/Batches to an
  explicit resource list doesn't apply here; no `canAccess()` override
  needed either (unlike `InstructorInvitationResource`, B1, which shares
  the same `Domains/Tenancy/Filament/Resources` directory but is
  deliberately Admin-only).
- New `tests/Feature/Operations/EnquiryResourceTest.php` (3 tests):
  create from Admin, reachable from Teach, tenant-scoped listing.
- `composer test`: 1357 tests, 1356 pass, 1 pre-existing unrelated
  `CourseSlugUniquenessTest` fail (unchanged since B0), 0 errors.

### B14 — Admission CRM follow-up + convert-to-enrolment — done

- `enquiry_follow_ups` migration + model, registered in
  `TenantModelRegistry`. `FollowUpsRelationManager` on `EnquiryResource`
  — note + `next_follow_up_at`, logger defaulted to the acting admin via
  `mutateFormDataUsing()`, not a form field.
- `EnquiryConversionService::convert()` — find-or-create the student
  `User` by email (same "existing same-tenant email reused, different-
  tenant email refused" posture B10/B11/B12 already established), grants
  the `student` role through `RoleAssignmentPolicy` for a genuinely new
  account, then `BatchEnrolmentService::addManually()` for the real
  `Enrolment` (design decision 9). `converted_enrolment_id` set from that
  call's own return value; `enquiries.status = 'converted'`. New
  `EnquiryConversionException` (409) for "already converted" and
  "different tenant" — registered in `bootstrap/app.php`.
- "Convert to enrolment" table action on `EnquiryResource`, batch
  `Select` scoped to the enquiry's own `course_id` when set.
- New tests: `EnquiryConversionServiceTest` (4, service-level) +
  `EnquiryResourceTest` (2 more, through the real Filament actions).
- `composer test`: 1364 tests, 1363 pass, 1 pre-existing unrelated
  `CourseSlugUniquenessTest` fail (unchanged since B0), 0 errors.

### B15 — ID card generation — done

- Verified before coding: `CertificateService` (S10's own precedent)
  uses dompdf but never `endroid/qr-code` — its QR only ever renders as
  inline SVG on the public verify *web page*
  (`VerifyCertificateController`), a different context. Embedding a QR
  *inside* a dompdf template is new to this codebase; used
  `Endroid\QrCode\Writer\PngWriter` + a base64 data URI (dompdf reads
  `<img>` sources off disk/inline, not remote URLs — same reason
  `CertificateService` passes absolute local paths for its own images).
- `id_cards` migration (`enrolment_id`, `card_number` unique per tenant,
  `disk_path`, `issued_at`). `IdCard` model + `Enrolment::idCard()`
  relation, registered in `TenantModelRegistry`.
- `IdCardGenerator` — `Pdf::loadView('batches.id-card-pdf', ...)->save($path,
  'local')`, same shape `CertificateService` already uses; disk path
  `id-cards/{tenant_id}/{enrolment_id}.pdf`.
- "Issue ID card" (on demand only, design decision 10) +
  "Download ID card" row actions on `EnrolmentsRelationManager`, gated
  by the same `authorizedActor()` policy check every other action there
  already uses.
- `DownloadIdCardController` — same bare-route, controller-only
  role+tenant-scoped-lookup shape `DownloadStudentImportErrorReportController`
  (B10) already established; `{enrolment}` resolved via `$request->route()`.
- New tests: `IdCardGeneratorTest` (1, service-level — PDF written,
  row created), `EnrolmentsRelationManagerTest` (1 more — issue then
  download visibility flips), `DownloadIdCardControllerTest` (3 — 404
  before issuance, downloads after, forbidden for a student).
- `composer test`: 1370 tests, 1369 pass, 1 pre-existing unrelated
  `CourseSlugUniquenessTest` fail (unchanged since B0), 0 errors.

### B16 — Noticeboard schema + authoring — done

- `notices` migration (`audience_roles` jsonb, `published_at` nullable —
  null is draft). `Notice` model (Comms domain, plain-text `body` same
  precedent `DirectMessage`/`ForumPost` already established), registered
  in `TenantModelRegistry`. `Notice::audienceRoleOptions()` derives the
  Select's own options from `RoleHierarchy::LEVELS` (design decision 11
  — a subset of the same 5 roles, no separate enum invented).
- `NoticeResource` — Admin-only by omission from Teach's own
  discovered/explicit resource lists, same precedent B3/B9 already
  established (no `canAccess()` override needed). Compose form leaves
  `published_at` optional (blank = draft, per the schema's own design);
  a "Publish now" row action turns an existing draft published without
  needing to re-open the edit form. B17 builds the actual display side.
- New `tests/Feature/Comms/NoticeResourceTest.php` (4 tests): draft
  creation with author auto-set, publish-now action, tenant-scoped
  listing, unreachable from Teach.
- Caught by the test run: `TextColumn::make('audience_roles')->formatStateUsing(fn (array $state) ...)` crashed with a
  `string given` type error under some Filament table-render paths for
  this jsonb column — widened the closure to `array|string|null` and
  `json_decode()` the string case, rather than trust the Eloquent cast
  always runs first.
- `composer test`: 1375 tests, 1374 pass, 1 pre-existing unrelated
  `CourseSlugUniquenessTest` fail (unchanged since B0), 0 errors.

### B17 — Noticeboard display + read tracking — not started

- `notice_read_receipts` migration (unique on `notice_id`+`user_id`) +
  model, registered in `TenantModelRegistry`.
- Portal/panel banner or list showing published notices matching the
  viewer's own role, mark-as-read action.

### B18 — Enrolment report export — not started

- `ExportEnrolmentRegister` `ShouldQueue` job, `ExportAttendanceRegister`-shaped
  (design decision 12): `RebindsTenant`, `Storage::disk('local')`,
  `EscapesCsvFormulas`, a bare Filament `Page` + gated download controller.

### B19 — Completion report export — not started

- `ExportCompletionRegister`, same shape as B18, reusing the
  gradebook-based completion definition `CheckCourseCompletionAndIssueCertificates`
  (S10) already established rather than re-deriving it.

### B20 — TenantModelRegistry sweep — not started

- Confirm all 11 new `BelongsToTenant` models (`InstructorInvitation`,
  `TimetableTemplate`, `ForumThread`, `ForumPost`, `BatchFeedback`,
  `StudentImportBatch`, `Enquiry`, `EnquiryFollowUp`, `IdCard`, `Notice`,
  `NoticeReadReceipt`) registered with `seedAttributes()`/`updateAttributes()`
  entries; `CrossTenantTest` generates coverage for each with no
  hand-written per-model test.

### B21 — Full test coverage, review pass — not started

- Lifecycle E2E test(s) mirroring S11 B16/S12 B14/S13 B17/S14 B18's own
  posture — at minimum: enquiry → follow-up → convert-to-enrolment → ID
  card issued, chained in one run; plus instructor invite → accept →
  assigned batch → conflict detected on a second overlapping session
  attempt.
- Explicit review pass across every B0-B20 file for real bugs, not just
  a green suite — same discipline S9 B14/S10 B15/S11 B16/S12 B14/S13
  B17/S14 B18 each already established (each found real bugs this way).
- Update `HANDOVER.md` (S15 complete entry + any new `## Avoid` entries),
  `docs/PROJECT-STATUS.md` (new S15 section), `docs/ROADMAP.md` (S15 row
  → Done).
