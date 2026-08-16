# Implementation Plan: S10 Assignments + Gradebook + Certificates

Full design: `2026-08-16-s10-assignments-gradebook-design.md`. This is the
block-by-block build log — update the PROGRESS table as each block lands,
same as every S1-S9 plan did. 16 blocks (B0-B15), more granular than S6-S8's
8-9 each, matching S9's own precedent that a wider slice benefits from
smaller, independently-testable blocks.

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-16-s10-assignments-gradebook-design.md and
docs/superpowers/specs/2026-08-16-s10-assignments-gradebook-plan.md, then build
Block 0 of the S10 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | Domain groundwork — `Lesson.TYPES += 'assignment'`, `courses.gradebook_weights`/`passing_threshold` columns, `app/Domains/Assignments/` scaffold, `assignments`/`assignment_submissions`/`certificates` migrations + models, tenant registry | ✅ done |
| B1 | Assignment CRUD (J1) — `/teach` resource, due date/max marks/file types/size limits, rubric builder | ✅ done |
| B2 | Student submission (J2) — `AssignmentSubmit` Livewire, up to 5 files, late-flag at submit, wired into syllabus tree's new `assignment` branch | ✅ done |
| B3 | Resubmission + version history (J4) — ≤3 versions, each a new row, student-facing version list | ✅ done |
| B4 | Grading + rubric scoring (J3) — `/teach` grading queue, marks/feedback/rubric_scores | ✅ done |
| B5 | Gradebook weighting config (J5 part 1) — `Course` edit form gains weights (sum-to-100 validated) + passing threshold | ✅ done |
| B6 | `GradebookService` computation (J5 part 2) — weighted score per student per course, reads Quiz+Assignment | ✅ done |
| B7 | At-risk flagging (J6) — gradebook-score threshold, own constant, surfaced in gradebook UI + `InstituteOverviewWidget` | ✅ done |
| B8 | Gradebook UI — `/teach` and `/admin` table per course, sortable, per-student drill-down | ✅ done |
| B9 | Gradebook export: CSV (J7 part 1) — `ShouldQueue` job, `EscapesCsvFormulas` trait reused from S9 B11 | ✅ done |
| B10 | Gradebook export: Excel + PDF (J7 part 2) — first `maatwebsite/excel`/`barryvdh/laravel-dompdf` use | ✅ done |
| B11 | Certificate schema + auto-issue sweep (K1) — completion definition, `CheckCourseCompletionAndIssueCertificates`, dompdf render | ✅ done |
| B12 | Certificate branding (K2) — tenant logo/signature/background via `BrandingService` | ✅ done |
| B13 | Certificate verification (K3) — public verify page + QR code | ✅ done |
| B14 | LinkedIn share (K4) — add-to-profile link + OG metadata on verify page | ✅ done |
| B15 | Full test coverage, isolation registration, review pass, `PROJECT-STATUS.md`/`HANDOVER.md` update | ✅ done |

**Test suite:** 707/708 at slice start (1 pre-existing, unrelated
`CourseSlugUniquenessTest` failure carried since S1/S2). **710/711 after
B0** (+3, all from `CrossTenantTest`'s dataset gaining 3 entries — one per
newly-registered model, same `it(...)->with(TenantModelRegistry::MODELS)`
shape every prior domain-groundwork block added to). **716/717 after B1**
(+6 — `AssignmentResourceTest`'s create/marks-only/validation/edit/
delete-guard×2). **723/724 after B2** (+7 —
`AssignmentSubmitTest`'s on-time/late/bad-file-type/too-many-files/
no-enrolment/duplicate-submit/staff-bypass). **725/726 after B3** (net +2
— B2's "duplicate submit refused" test replaced with 3 new ones:
resubmission-writes-new-version, grade-preserved-across-resubmission,
4th-submission-refused). **730/731 after B4** (+5 —
`AssignmentSubmissionResourceTest`'s marks-only-grade/
rubric-derived-grade/hide-once-graded/rubric-score-exceeds-criterion-max/
unknown-criterion-id-rejected). **734/735 after B5** (+4 —
`CourseGradebookWeightsTest`'s default-from-migration/create-with-custom-split/
reject-non-100-sum/edit). **743/744 after B6** (+9 —
`GradebookServiceTest`'s weighted-score/renormalization/best-quiz-attempt/
latest-graded-version/ungraded-version-fallback/zero-items/
incomplete-missing-attempt/complete-and-passing/complete-but-below-threshold).
**746/747 after B7** (+3 — 2 new `GradebookServiceTest` at-risk cases +
1 new `InstituteOverviewWidgetTest` case). **753/754 after B8** (+7 — 6
new `GradebookPageTest` cases (`/teach`) + 1 new `GradebookAdminPageTest`
smoke test (`/admin`)). **765/766 after B9** (+12 — 6 new
`ExportGradebookCsvTest` cases + 6 new `DownloadGradebookExportControllerTest`
cases). **777/778 after B10** (+12 — 4 `ExportGradebookExcelTest` + 2
`ExportGradebookPdfTest` + 3 `DownloadGradebookExcelExportControllerTest`
+ 3 `DownloadGradebookPdfExportControllerTest`). **786/787 after B11**
(+9 — 3 `CertificateServiceTest` + 6
`CheckCourseCompletionAndIssueCertificatesTest`). **794/795 after B12**
(+8 — 4 `CertificateSettingsTest` + 3 new `BrandingServiceTest` cases + 1
new `CertificateServiceTest` case). **798/799 after B13** (+4 —
`VerifyCertificateControllerTest`'s valid-with-qr/404-unknown-id/
cross-tenant-host/og-meta). **800/801 after B14** (+2 —
og:image-present-or-absent, LinkedIn-share-url-params). **804/805 after
B15** (+4 — 1 concurrent-submission-race-recovery test, 3
soft-deleted-batch-doesn't-crash tests across the sweep job, an export,
and one Gradebook page). **S10 COMPLETE, all 16 blocks.** Final tally
well past the ~50-new / ~757-758-total estimate from design doc §4 —
confirms that count was conservative, not wrong.

---

## Block notes (fill in as each block builds — deviations, gotchas,
## decisions made mid-build)

### B0 — Domain groundwork — DONE (2026-08-16)

- Checked `LessonResource::form()`'s per-type `visible()` closures before
  assuming a new type just works (S5 B6's own gap class) — confirmed no
  Lesson-level field is gated to `'quiz'` either, so `'assignment'` needs
  no form change here, same as `'quiz'`. `Assignment`'s own fields
  (due date, max marks, rubric) live entirely on the new `assignments`
  table, surfaced by B1's own resource, not on `Lesson`.
- `lessons.type` turned out to be a real Postgres CHECK constraint
  (Laravel's `enum()` on pgsql), not a native `ENUM` type — no
  `ALTER TYPE ADD VALUE` exists for it. Queried `pg_constraint` on the live
  dev DB before writing the migration rather than guessing Laravel's
  naming convention; confirmed name `lessons_type_check`. New migration
  `2026_08_16_100000_add_assignment_type_to_lessons_table.php` drops and
  re-adds it with `'assignment'` appended (`down()` reverses cleanly).
- `courses.gradebook_weights`/`passing_threshold` — confirmed neither
  column existed (read `Course::$fillable` and every `courses` migration
  first). New `2026_08_16_100001_add_gradebook_columns_to_courses_table.php`,
  `jsonb` default `{"quiz":50,"assignment":50}` / `decimal(5,2)` default
  `40.00` — applies to every existing course with no backfill needed.
- `app/Domains/Assignments/Models/` only — `Services/`/`Filament/`/`Jobs/`/
  `Livewire/`/`Views/` land with the block that first needs them, matching
  S6 B0's own "don't pre-scaffold empty folders" precedent.
- Three new migrations per design doc §3.1
  (`2026_08_16_100002..100004_create_*_table.php`). `assignment_submissions`
  and `certificates` both `tenant_id ... cascadeOnDelete()` — got this
  right at B0, no repeat of S8 B8's one-line deviation.
  `assignment_submissions.version_number` is DB-checked `BETWEEN 1 AND 3`
  (design doc §2.4 decision 3) via a raw `DB::statement` — Postgres has no
  Blueprint helper for a bare CHECK constraint; confirmed this file lives
  in `database/migrations/`, not `app/Domains/`, so `SecurityBaselineTest`'s
  raw-SQL ban (scoped to `app_path('Domains')` only, confirmed by reading
  the test) doesn't apply here, same as the pre-existing partial-unique-index
  migrations already using `DB::statement` for the same reason.
- Three models (`Assignment`, `AssignmentSubmission`, `Certificate`), each
  `BelongsToTenant`. `Course.php` gained `gradebook_weights`/
  `passing_threshold` to `$fillable` + casts (`array`/`decimal:2`).
- Registered all three in `TenantModelRegistry::MODELS` **in this block**,
  with full `seedAttributes()`/`updateAttributes()` — S6 B0's own note
  applies verbatim: register-without-seed throws immediately once
  `CrossTenantTest`'s dataset iterates `MODELS`.
- `composer test`: 710/711 passing (+3, one new `CrossTenantTest` dataset
  entry per registered model — confirmed by reading the test file: it's a
  single `it(...)` with a `MODELS`-driven dataset, not several `it()`s per
  model, so 3 new models means exactly +3, not +9), same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated, still out of scope.

### B1 — Assignment CRUD (J1) — DONE (2026-08-16)

- New standalone `/teach` resource, `app/Domains/Assignments/Filament/
  Resources/Assignments/AssignmentResource.php` (+ `Pages/List|Create|Edit`)
  — `lesson_id` Select filtered to `type='assignment'` lessons, same shape
  S6 B4's `Quiz::lesson_id` select used for `type='quiz'`. Registered via a
  new `->discoverResources(...)` line in `TeachPanelProvider` (the domain's
  first Filament resource).
- `Assignment::FILE_TYPE_OPTIONS` (new const, `pdf`/`doc`/`docx`/`jpg`/
  `jpeg`/`png`/`ppt`/`pptx`/`xls`/`xlsx`/`zip`/`txt`) — same fixed
  allow-list posture as `Lesson::EMBEDDABLE_HOSTS` (S9 B6): form field is a
  multi-`Select` against this const, not free text, so B2's upload
  validation reads the assignment's own `allowed_file_types` values with no
  separate allow-list to drift out of sync.
- `max_file_size_kb` bounded 100-51200 (100KB-50MB) in the form; rubric is
  a `Repeater` over `criterion`/`max_points`, left genuinely optional (no
  `minItems`) — an empty repeater persists as `[]`, and `blank($assignment
  ->rubric)` reads that the same as `null` for B4's marks-only-grading
  branch, so no extra normalization needed.
- Delete guard mirrors `QuizResource`'s own fix (S6 B8): hidden once the
  assignment has any submissions, so a stray click can't destroy graded
  history — built in from B1 rather than a later review-pass fix this
  time.
- **Real bug found and fixed, not by the review pass but by this block's
  own test:** a `Hidden::make('id')->default(fn () => Str::uuid())` inside
  the rubric `Repeater`, meant to give each criterion a stable id for B4's
  `rubric_scores` to key against, silently never ran — Filament's
  per-repeater-item `default()` only fires when an item is added through
  the UI's own "add" action (it mounts that child schema fresh); a form
  fill that hands the Repeater its state directly — `fillForm()` in tests,
  and identically a real save reusing the same code path — never triggers
  it, so every rubric row's `id` stayed `null`. Fixed by removing the
  `Hidden` field entirely and backfilling `id` in `Assignment`'s own
  `saving()` hook instead (`$criterion + ['id' => Str::uuid()]`, so an
  already-present id from a prior save is preserved, not replaced) —
  guarantees every write path gets a stable id, not just this one Filament
  form.
- `composer test`: 716/717 passing (+6 — create-with-rubric, marks-only
  empty-rubric, missing-required-fields, edit, delete-guard-hidden,
  delete-guard-visible), same 1 pre-existing `CourseSlugUniquenessTest`
  failure, unrelated, still out of scope.

### B2 — Student submission (J2) — DONE (2026-08-16)

- `Assignment::isVisibleTo()` (new, on the model — same posture as
  `Quiz::isVisibleTo()`) is deliberately **stricter** than Quiz's own gate:
  Quiz allows any student who can see the published course, no enrolment
  check at all; Assignment additionally requires an active `Enrolment` in
  a `Batch` offering the course (design doc §2.4 decision), reading
  `Batches\Enrolment`/`Batch` directly rather than calling into Content's
  `LessonAccess` or a Batches service (HANDOVER's cross-domain rule —
  model reads are fine, service calls are not).
- `AssignmentSubmissionService::submit()` — B2 scope is version 1 only
  (first submission); re-checks `isVisibleTo()` itself, not just trusted
  from the page load, since a Livewire action runs through
  `/livewire/update` with no domain route constraint and never actually
  passes through `ShowAssignmentController`'s gate (same
  Livewire-bypasses-the-route shape `QuizAttemptService::start()` already
  guards against). Refuses a second submission via this same action
  (`AssignmentAlreadySubmittedException`) — B3 adds the real resubmit path.
- `AssignmentSubmit` Livewire component (`app/Livewire/`, matching
  `QuizRunner`'s top-level placement, not domain-namespaced), `WithFileUploads`
  — Livewire's own dynamic `mimes:`/`max:` rules (built from the
  assignment's own `allowed_file_types`/`max_file_size_kb` columns) give
  real client+server validation; the service re-checks file type again as
  defense-in-depth, same "never trust one layer alone" posture `S9 B6`'s
  `embed_url` allow-list established. `is_late` computed once at submit
  (`now() > assignment.due_at`), stored — not recomputed later, so a
  due-date edit after submission can't retroactively relabel history.
- New `ShowAssignmentController` + `routes/assignments.php` (mirrors
  `ShowQuizController`/`routes/assessments.php` exactly, including the
  404-vs-403 split), registered in `routes/web.php`'s tenant-domain group.
- `SyllabusTree` gained an `assignment` branch (resolves the `Assignment`
  row + `canSubmitAssignment`), same shape the existing `quiz` branch
  already has — extends `app/Livewire/SyllabusTree.php` and its blade, not
  a new component.
- `composer test`: 723/724 passing (+7 —
  on-time-submission/late-flag/bad-file-type/too-many-files/
  no-active-enrolment/duplicate-submission-refused/staff-can-preview),
  same 1 pre-existing `CourseSlugUniquenessTest` failure, unrelated, still
  out of scope.
- **Bug caught before it ever reached `composer test`'s real run**: the
  test file's own `enrolStudent()` helper function collided with an
  identically-named helper already declared in
  `tests/Feature/Comms/StudentProgressPageTest.php` — Pest loads every
  test file's bare (non-closure) functions into one shared global
  namespace, so the second declaration was a PHP fatal error
  ("Cannot redeclare function"), not a failing assertion. Renamed to
  `enrolStudentInAssignmentBatch()`. Worth remembering for every future
  block that adds a bare test-helper function, not just this one.

### B3 — Resubmission + version history (J4) — DONE (2026-08-16)

- **Deviation from the plan note's own framing**: rather than a separate
  "resubmit" method/action alongside B2's `submit()`, `AssignmentSubmissionService::submit()`
  was extended into the one entry point for both the first submission and
  every resubmission — it now computes `version_number` from the
  student's existing count (`existingCount + 1`) instead of hardcoding
  `1`, and throws the new `AssignmentResubmissionLimitExceededException`
  once `existingCount >= AssignmentSubmissionService::MAX_VERSIONS` (3). A
  second method would have duplicated the gate check, file-type
  validation, and storage logic verbatim for zero behavioural difference.
  B2's `AssignmentAlreadySubmittedException` (which unconditionally
  blocked any second submission) is now dead code superseded by the real
  version cap — deleted rather than left unused.
- `AssignmentSubmit` (the same Livewire component, not a new one) now
  renders the student's **full** version history (ordered by
  `version_number`, each row's own files/marks/feedback/grading status),
  not just the latest — matches J4's "full version history" wording. The
  submit form's own label switches "Submit" → "Resubmit" once ≥1 version
  exists, and disappears entirely once 3 exist (`canSubmitAnotherVersion`
  passed from `render()`).
- File storage path now includes the real `{versionNumber}` segment
  (previously hardcoded `/1` in B2) — so resubmitting no longer risks
  colliding with or silently overwriting the first version's stored
  files on disk, even though the DB row itself was always a separate
  insert either way.
- Verified (not assumed) that a resubmission genuinely leaves a prior
  graded version's `marks_awarded`/`feedback`/`graded_at` untouched — the
  new version is a wholly separate `INSERT`, `AssignmentSubmission::create()`
  never touches sibling rows, but this block's own test asserts the
  persisted column values directly rather than trusting that reasoning on
  its own (same "assert the actual persisted value, not just a method's
  return" discipline HANDOVER's own "Avoid" section already documents
  from S9 B2/B4).
- `composer test`: 725/726 passing (net +2 — B2's now-superseded
  duplicate-submit test replaced with
  resubmission-writes-new-version/grade-preserved-across-resubmission/
  4th-submission-refused), same 1 pre-existing `CourseSlugUniquenessTest`
  failure, unrelated, still out of scope.

### B4 — Grading + rubric scoring (J3) — DONE (2026-08-16)

- `AssignmentSubmissionResource` (`/teach`, nav label "Grading queue") —
  same read-only-table-plus-one-action shape `QuizAnswerResource` (S6 B6)
  already established: no create/edit pages, a `TernaryFilter` for
  graded/ungraded, tenant-wide scope (no instructor-course pivot exists
  anywhere in this app — HANDOVER's own "Avoid" section, S4/S8/S8-again).
- Rubric-vs-marks-only branching lives in `AssignmentGradingService::grade()`,
  keyed on `blank($assignment->rubric)` — not decided by the Filament form
  or the caller. The action's own `->schema(fn ($record) => [...])`
  closure (same dynamic-per-record shape
  `BatchSessionsRelationManager::markAttendance` already established)
  builds one `TextInput` per rubric criterion, each bounded to that
  criterion's own `max_points` — client/server-validated per-criterion
  before the service ever runs.
- **Deliberate decision, not in the plan note verbatim**: `marks_awarded`
  for a rubric-graded submission is *derived* (`array_sum($rubricScores)`),
  never accepted as a separately-typed number — a grader typing a total
  that disagrees with their own per-criterion breakdown was a real
  possibility the plan note's "marks/feedback/rubric_scores" phrasing
  left open; closed it by making the sum the only source of truth.
- `UnknownRubricCriterionException` guards `AssignmentGradingService`
  itself (a direct caller could still pass a bogus id even though the
  Filament UI can't — it only ever generates known ids) — tested directly
  against the service, not reachable through the Filament action in
  practice.
- `composer test`: 730/731 passing (+5 —
  marks-only-grade/rubric-derived-grade/hide-once-graded/
  rubric-score-exceeds-criterion-max/unknown-criterion-id-rejected), same
  1 pre-existing `CourseSlugUniquenessTest` failure, unrelated, still out
  of scope. One run this block hit composer's own default 300s
  process-timeout mid-suite (not a test failure — the run was still going,
  just cut off); re-ran with `COMPOSER_PROCESS_TIMEOUT=600` and it
  completed clean at 510s. Worth setting for any future block too, not a
  one-off.

### B5 — Gradebook weighting config (J5 part 1) — DONE (2026-08-16)

- `app/Domains/Content/Filament/Resources/Courses/CourseResource.php`
  (found by reading the folder, not assumed) gained
  `gradebook_weights.quiz`/`gradebook_weights.assignment`/
  `passing_threshold` fields, appended after the existing `status` field.
- Sum-to-100 check on the assignment-weight field's own `->rule()`, same
  "outer closure returns the real Laravel validator closure" shape
  `BatchResource`'s instalment-percent sum check already established (S7
  B4) — read that file first rather than reinventing the mechanics, since
  Filament's `->rule()` runs through its own dependency-injecting
  `evaluate()`, not handed straight to Laravel's validator (confirmed by
  that file's own comment, not re-derived).
- No dedicated `CourseResource` Filament test existed anywhere in this app
  before this block (confirmed — only model-level `Course*Test.php` files
  existed) — new `CourseGradebookWeightsTest.php` is this resource's first
  Filament-form test, covering both the new fields and confirming (via a
  plain model read) that B0's migration defaults already apply to an
  existing course with zero admin action, per the plan note's own
  requirement.
- `composer test`: 734/735 passing (+4 —
  default-from-migration/create-with-custom-split/reject-non-100-sum/
  edit-existing-course), same 1 pre-existing `CourseSlugUniquenessTest`
  failure, unrelated, still out of scope.

### B6 — `GradebookService` computation (J5 part 2) — DONE (2026-08-16)

- Read `StudentMetrics::quizAveragePercent()` first, per the plan note's
  own instruction — confirmed it does **not** generalize: it's tenant-wide
  (no course scope at all) and averages *every* completed attempt, not
  each quiz's *best* one. Wrote a genuinely new, course-scoped query in
  `GradebookService::quizPercentFor()` rather than reusing or wrapping it.
- `scoreFor()` built to design doc §3.2's contract, minus `is_at_risk` —
  **deliberate deviation, recorded here**: the design doc's own pinned
  return shape included `is_at_risk`, but B7 is a dedicated block that
  introduces the `GRADEBOOK_AT_RISK_THRESHOLD` constant this key would
  depend on; computing it here would preempt B7's own named deliverable.
  `is_passing` stayed in `scoreFor()` since it only needs `weighted_percent`
  plus the course's own `passing_threshold`, both already in scope — no
  new constant, nothing to preempt. B7 will extend the return array with
  `is_at_risk` when it adds the threshold.
- `isComplete()` built in full too (design doc §3.2's other pinned method)
  even though B11 is its only real consumer — same "build the class to its
  whole contract in one block" posture S1's `lesson_artifacts` schema and
  S5's `LiveSessionRoomCreated` event both already established, distinct
  from the `is_at_risk` deviation above since `isComplete()` doesn't touch
  B7's own scope at all.
- Renormalization test (written first, per the plan note's own warning)
  passed clean on the first run — a course with an assignment but no quiz
  scores 90% off the assignment alone, not 45% from averaging in a
  phantom 0% quiz component.
- **A second asymmetry decided and recorded, not just quiz-vs-assignment**:
  the assignment half scores the *latest graded* version, not the
  highest-scoring one (unlike the quiz half's best-attempt) — a
  resubmission is meant to supersede the grader's earlier feedback, tested
  explicitly (`scores the latest graded assignment version, not the
  highest-scoring one`) so this doesn't silently drift into "best of all
  graded versions" later.
- `composer test`: 743/744 passing (+9, all new — weighted-score math,
  renormalization, best-quiz-attempt, latest-graded-version,
  ungraded-newer-version-falls-back, zero-gradeable-items,
  incomplete-on-any-missing-attempt, complete-and-passing,
  complete-but-below-threshold), same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated, still out of scope.

### B7 — At-risk flagging (J6) — DONE (2026-08-16)

- `GradebookService::GRADEBOOK_AT_RISK_THRESHOLD` (60.0) — the constant B6
  deliberately deferred, now added along with the `is_at_risk` key
  `scoreFor()`'s return array was always missing. Same "absence of
  evidence" guard as `is_passing`: `is_at_risk` is `false` when the
  student has no graded data at all (`$totalWeight === 0`), not `true`
  just because `weighted_percent` defaults to `0.0`.
- **At-risk and passing are independent, tested explicitly**: a 50% score
  against a 40% `passing_threshold` is passing but still at-risk (below
  the 60% threshold) — the two flags can disagree, on purpose, and a test
  asserts exactly that combination rather than assuming one implies the
  other.
- `InstituteOverviewWidget` gained a second stat, "At-risk on grades" —
  additive, the existing attendance/coverage "At-risk students" stat is
  untouched. Gradebook at-risk has no student-wide meaning the way
  attendance does (a score is always per-course), so the widget counts
  distinct students flagged in *at least one* actively-enrolled course,
  reading every active `Enrolment` → `Batch` → `Course` in the tenant and
  calling `GradebookService::scoreFor()` per row.
- **"Surfaced in gradebook UI" (the plan note's own phrase) deferred to
  B8, not silently dropped**: no gradebook table exists yet to surface it
  in — B8 builds that table next and will read `is_at_risk` off the same
  `scoreFor()` this block already finished, not a new computation.
- `composer test`: 746/747 passing (+3 — 2 new `GradebookServiceTest`
  at-risk cases, 1 new `InstituteOverviewWidgetTest` case), same 1
  pre-existing `CourseSlugUniquenessTest` failure, unrelated, still out of
  scope.

### B8 — Gradebook UI — DONE (2026-08-16)

- Read `StudentProgress` (S8 B5) first, per the plan note's own
  instruction — it's this app's only precedent for a standalone
  `HasTable` Filament Page (`EmbeddedTable::make()` in `content()`, no
  Resource CRUD behind it). Confirmed it has **no real column-level
  `->sortable()`** on any of its computed `state()`-backed columns despite
  FEATURES.md's M11 promising "sortable" — the same doc-vs-code mismatch
  class this app's own review passes keep finding, just caught before
  building rather than after. `Gradebook` matches that same accepted
  posture rather than inventing real DB-level sorting for a four-table
  computed aggregate that has no materialized column to sort on.
- **Deviation from the plan note's own "/teach: per-course... /admin:
  tenant-wide, course-filterable" phrasing, recorded**: built as the exact
  same shape on both panels — one row per active `Enrolment` (not per
  student, since a gradebook score is always per-course), both
  course-filterable via a `SelectFilter`. No instructor-course pivot
  exists anywhere in this app to enforce a genuinely narrower `/teach`
  scope — the identical gap HANDOVER's own "Avoid" section already
  documents for every other "instructor's own X" surface (S4, S8×2), read
  the same way here rather than inventing enforcement with nothing behind
  it.
- A "Standing" badge column (Failing / At risk / On track) collapses
  `is_passing`/`is_at_risk` into one three-state read — not-passing
  outranks at-risk, since the two flags are independent (a
  passing-but-at-risk student is real, per B7's own test) and a reader
  scanning a table wants one glance, not two numbers to reconcile.
- Two separate `App\Filament\Teach\Pages\Gradebook` / `App\Filament\Admin\Pages\Gradebook`
  classes, deliberately not sharing a base class or trait — no precedent
  for cross-panel-page sharing exists anywhere in this app (checked), and
  each panel's own provider already duplicates its middleware array the
  same way; a two-class duplication this small didn't earn a new
  abstraction with zero existing precedent to match.
- `composer test`: 753/754 passing (+7 — `GradebookPageTest`'s
  placeholder-on-no-data/excludes-inactive-enrolment/excludes-cross-tenant/
  course-filter/Failing-badge/On-track-badge, plus
  `GradebookAdminPageTest`'s tenant-wide smoke test), same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated, still out of scope.

### B9 — Gradebook export: CSV (J7 part 1) — DONE (2026-08-16)

- Reused `App\Domains\Comms\Jobs\Concerns\EscapesCsvFormulas` (S9 B11)
  directly from the Assignments domain — no third copy of the CWE-1236
  guard.
- `ExportGradebookCsv` copies `ExportEngagementRegister`'s exact
  `ShouldQueue` + `RebindsTenant` + `Storage::disk('local')` shape (S9
  B11) — a fourth copy of the established pattern, not a new one. One row
  per active `Enrolment` (student × course), matching B8's own Gradebook
  table granularity, not per-student the way the engagement/attendance
  registers are (those have no per-course rollup to draw from;
  `GradebookService` does).
- **Deviation from precedent, deliberate**: the download controller
  allows both `instructor` and `institute_admin` (S9 B11's own
  engagement/attendance exports are `institute_admin`-only). The
  gradebook itself (B8) is the identical table on both `/teach` and
  `/admin` — gating the export tighter than the view it exports would be
  inconsistent for no reason.
- No new standalone export page — the generate/download header actions
  (same pair `EngagementExport`, S9 B11, established) were added directly
  onto the existing `Gradebook` Filament pages instead, since B8 already
  built the natural home for "everything about the gradebook." Extracted
  `GradebookService::standingFor()` out of both `Gradebook` page classes'
  own duplicate private methods once this job became a third caller
  needing the identical text — same "third copy is the bug class waiting
  to be forgotten" reasoning `EscapesCsvFormulas` itself was extracted
  for.
- **Real bug found by this block's own test, not the review pass**: that
  extraction surfaced a genuine defect neither page's test had caught —
  `standingFor()` returned `'Failing'` for a student with **no graded
  data at all**, because `is_passing` defaults to `false` when there's
  nothing to grade (the same "absence of evidence" guard `is_passing`/
  `is_at_risk` already carry individually), but the three-state collapse
  never accounted for that case separately. A brand-new enrolment would
  have shown as failing the moment it appeared in the gradebook. Fixed
  with an explicit `quiz_percent === null && assignment_percent === null`
  check returning a new `'No data yet'` state (gray badge, both
  `Gradebook` pages' color maps updated) before the passing/at-risk match
  runs at all.
- `composer test`: 765/766 passing (+12 — `ExportGradebookCsvTest`'s
  no-data-state/real-score-and-standing/excludes-inactive/csv-injection-guard/
  tenant-scoping/retry-config, `DownloadGradebookExportControllerTest`'s
  404-before-generation/admin-download/instructor-download/guest-blocked/
  student-blocked/cross-tenant-blocked), same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated, still out of scope.

### B10 — Gradebook export: Excel + PDF (J7 part 2) — DONE (2026-08-16)

- WebSearched both packages before requiring, per the plan note's own
  discipline: `maatwebsite/excel` 4.0.0 (php ^8.3, illuminate/support
  ^12.0||^13.0) and `barryvdh/laravel-dompdf` ^3.1 (Laravel ^9-^13) both
  confirmed compatible with this app's `"php": "^8.3"` /
  `"laravel/framework": "^13.8"` pins before `composer require`. Locked
  clean: `maatwebsite/excel` (4.0.0), `barryvdh/laravel-dompdf` (v3.1.2),
  no conflicts, no security advisories.
- Read the installed `maatwebsite/excel` v4 source directly (`Concerns/`
  still has `FromCollection`/`WithHeadings`/`Exportable`, the classic v3
  API) and `barryvdh/laravel-dompdf`'s `PDF.php` (`loadView()->save($path,
  $disk)`) before writing any export code, rather than assuming the
  well-known v3-era API carried over unchanged into a major version
  released 3 days before this block was built.
- **"One underlying data query, not three divergent queries" (the plan
  note's own instruction) applied literally**: new
  `App\Domains\Assignments\Jobs\Concerns\BuildsGradebookRows` trait holds
  the single row-building query/loop, used by `ExportGradebookCsv` (B9,
  **retrofitted** onto it — not left as a third divergent copy once Excel
  and PDF needed the identical rows), the new `GradebookExport` class
  (Excel), and `ExportGradebookPdf`'s own Blade view data. Dropped the CSV
  job's prior 200-row `chunk()` in the retrofit — Excel's `FromCollection`
  and the PDF's Blade view both need the whole collection in memory
  regardless, so chunking only one of three formats no longer made sense;
  recorded in the trait's own docblock, not silently dropped.
- **CWE-1236 (CSV formula injection) is not actually CSV-specific** — a
  cell starting with `=`/`+`/`-`/`@` is exactly as live in a real `.xlsx`
  opened in Excel/Sheets, so `GradebookExport` reuses
  `EscapesCsvFormulas::csvSafe()` too, not just the CSV job. Verified for
  real, not assumed: a test loads the actual generated `.xlsx` via
  `PhpOffice\PhpSpreadsheet\IOFactory` and asserts the escaped
  `'=SUM(1+1)` cell value, not a mocked `Excel::fake()` assertion that
  would only prove the job *dispatched*, not that the file itself came
  out safe. No equivalent guard needed on the PDF — a rendered document
  isn't a spreadsheet a viewer's app could reinterpret a cell as a
  formula in, and Blade's `{{ }}` already escapes each value into the
  table cell.
- Both `Gradebook` Filament pages (`/teach`, `/admin`) gained a third
  `exportActions()` helper call for Excel/PDF rather than six more inline
  action blocks — extracted once a third format made the copy-paste
  obvious, matching this whole slice's own recurring "Nth copy gets
  extracted" discipline (`EscapesCsvFormulas`, `GradebookService::standingFor()`,
  now this).
- `composer test`: 777/778 passing (+12 — `ExportGradebookExcelTest`'s
  real-xlsx-header-and-row/formula-injection-escaped/tenant-scoping/
  retry-config, `ExportGradebookPdfTest`'s real-pdf-magic-bytes/
  retry-config, plus 3 tests each for the two new download controllers),
  same 1 pre-existing `CourseSlugUniquenessTest` failure, unrelated,
  still out of scope. All 12 passed on the first run — no bugs this
  block, unlike B9's `standingFor()` defect.

### B11 — Certificate schema + auto-issue sweep (K1) — DONE (2026-08-16)

- `CheckCourseCompletionAndIssueCertificates` — daily, `'scheduled'`
  queue, `CurrentTenant::clear()` at the top of `handle()`, same shape
  `ExpirePendingEnrolments`/`RecordVideoCoverageAttendance` (S9 B10)
  already established for an unscoped cross-tenant sweep.
- Check-before-insert (a plain `exists()` query) is the cheap steady-state
  guard; `certificates`'s own `UNIQUE (tenant_id, user_id, course_id)`
  (already in place from B0) is what actually prevents a race — no
  exception-catching added on top, since a genuine race on a once-daily
  cron sweep with no concurrent invocation risk is a different threat
  model than the seat-limit races that pattern originally guarded.
- **Real gotcha this block ran straight into, not discovered by accident**:
  `Certificate` is `BelongsToTenant`, and this sweep runs with no
  `CurrentTenant` bound by design — `Certificate::create()` would have
  thrown `MissingTenantContextException` from the trait's own `creating()`
  hook. `CertificateService::issue()` uses `Certificate::withoutEvents(fn
  () => Certificate::create([..., 'tenant_id' => $student->tenant_id]))`,
  the exact shape `RecordVideoCoverageAttendance` (S5 B7) already
  established for creating a `BelongsToTenant` row from this same
  no-tenant-bound position — read that job first, not rediscovered from
  scratch.
- Certificate PDF template started from `resources/views/fees/invoice.blade.php`'s
  (S7 B5) general shape (inline `<style>`, table-based layout,
  `$tenant->legal_name ?? $tenant->name`) — a bordered "Certificate of
  Completion" layout with a verification ID footer, no branding yet (B12).
- **Two real bugs, both in this block's own new tests, not the
  application code**: (1) `CheckCourseCompletionAndIssueCertificatesTest`'s
  `beforeEach()` was missing `$this->seed(RoleSeeder::class)` —
  `assignRole('student')` failed with `RoleDoesNotExist` on every test in
  the file. (2) `CertificateServiceTest`'s "refuses a second certificate"
  test asserted `Certificate::count()` right after an expected
  `QueryException` from the unique-index violation — on Postgres, a failed
  query inside a transaction poisons the *whole* transaction (SQLSTATE
  25P02) until rollback, so the very next query in the same
  `RefreshDatabase`-wrapped test failed too, unrelated to the real
  behaviour being tested. Fixed by wrapping the expected-to-fail call in
  its own `DB::transaction()` (a Postgres SAVEPOINT nested inside the
  outer per-test transaction), isolating the poisoned state so the
  assertion after it runs clean.
- `composer test`: 786/787 passing (+9 — `CertificateServiceTest`'s
  issues-with-real-pdf/unique-verification-ids/duplicate-refused-by-db,
  `CheckCourseCompletionAndIssueCertificatesTest`'s
  issues-when-complete-and-passing/no-issue-when-incomplete/
  no-issue-below-threshold/no-duplicate-on-second-run/sweeps-every-tenant/
  retry-config), same 1 pre-existing `CourseSlugUniquenessTest` failure,
  unrelated, still out of scope.

### B12 — Certificate branding (K2) — DONE (2026-08-16)

- Read `BrandingService` before calling it, per the plan note's own
  instruction — `logoUrl()`/`primaryColor()`/`storeLogo()`/`storeFavicon()`
  all existed since S1, but a search confirmed **no Filament page anywhere
  had ever called `storeLogo()`/`storeFavicon()`** — this app's own logo/
  favicon upload UI has never been built, only the service backing one.
  New `tenants.certificate_signature_path`/`certificate_background_path`
  columns; `BrandingService` gained `storeCertificateSignature()`/
  `storeCertificateBackground()` + absolute-local-path getters (dompdf
  reads images straight off disk, no public-URL/remote-fetch detour
  needed), reusing the existing `storeUpload()` allow-list rather than
  duplicating it.
- `CertificateSettings` (`/admin`) — same "standalone Page, `HasForms`,
  `EmbeddedSchema` in `content()`, manual save `Action`" shape
  `CustomDomainSettings` (S9 B4) established.
- **Real bug, caught by this block's own test, not assumed away**: the
  page's first draft prefilled the signature/background `FileUpload`
  fields with the tenant's already-stored path in `mount()` — but that
  field's own configured disk is the *temp*-upload disk (`'local'`), not
  the disk the final file actually lives on (`'public'`) once
  `BrandingService` moves it. Filament's `FileUpload` resolves a prefilled
  string against its own configured disk to render a preview, so it
  silently read back nothing (`assertFormSet()` failed with `null`
  instead of the stored path). Matches `LessonResource`'s own
  `video_upload` field, checked after the fact: that field *never*
  prefills with the stored video path either, for the identical reason —
  not a coincidence this block should have caught before writing the
  test, not after. Fixed by never prefilling the upload fields at all
  (they're purely "drop a new file to replace" inputs) and showing
  current status via a separate `Placeholder` ("Uploaded" / "None
  uploaded yet"), decoupled from the upload field's own state — which
  also let the save action's fragile
  `$state['signature'] !== $tenant->certificate_signature_path`
  equality-check be deleted outright, since the field is never
  pre-populated with that value to begin with.
- `CertificateService::issue()` now threads `logoPath`/`signaturePath`/
  `backgroundPath` (all `?string`, absolute local paths, `null` when
  unset) into the certificate PDF view; the template omits each `<img>`
  entirely when its path is `null` rather than rendering a broken one.
- `composer test`: 794/795 passing (+8 — `CertificateSettingsTest`'s
  saves-both/shows-current-status-unchanged-on-unrelated-save/
  shows-none-uploaded-yet/replaces-existing, 3 new `BrandingServiceTest`
  cases, 1 new `CertificateServiceTest` case confirming a real PDF still
  renders once logo/signature branding is set), same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated, still out of scope.

### B13 — Certificate verification (K3) — DONE (2026-08-16)

- `GET /certificates/verify/{verificationId}`, host-agnostic
  (`routes/web.php`, outside every `Route::domain(...)` group, same
  pattern `/invite` already established), public, no auth.
- Confirmed no QR library existed in `composer.json` (plan note's own
  instruction) before adding one. Installed `endroid/qr-code` (^6.1) —
  read the actual installed `QrCode`/`SvgWriter`/`ResultInterface` source
  before writing any code (`new QrCode(data: $url)`, `(new
  SvgWriter())->write($qrCode)->getString()`), not assumed from a
  remembered API shape, since v6 was released within days of this block.
  SVG output specifically — no GD/Imagick dependency, embedded inline in
  the verify page.
- Flat 404 (not 403, not a rendered banner) on an unknown id — `abort_if`,
  no behavioural difference leaked between "no such id" and "id from a
  suspended tenant."
- **Two real bugs, both caught by this block's own tests, not by
  inspection**: (1) `Certificate::withoutGlobalScope('tenant')` on the
  top-level query alone wasn't enough — `Course` is `BelongsToTenant` too
  (`User` isn't, confirmed by reading the model), so the eager-loaded
  `course` relation silently re-applied *whatever tenant the request's own
  host resolved to* one level down, independent of the certificate's real
  tenant. A certificate opened from a different institute's subdomain hit
  a null `$certificate->course` and 500'd instead of verifying — the exact
  bug class this route's own `withoutGlobalScope` existed to prevent,
  just one relation deeper than the fix first reached. Closed by scoping
  the eager-load itself: `'course' => fn ($q) => $q->withoutGlobalScope('tenant')`.
  (2) Not a code bug but a test design flaw: the QR code encodes
  `$request->url()`, so an assertion checking for a specific expected URL
  string would have been fragile against the test's own request host —
  sidestepped by asserting the response contains a real `<svg` element
  instead of a specific encoded value.
- `composer test`: 798/799 passing (+4 —
  valid-certificate-with-qr/404-unknown-id/
  verifies-across-different-tenant-hosts/og-meta-tags), same 1
  pre-existing `CourseSlugUniquenessTest` failure, unrelated, still out of
  scope.

### B14 — LinkedIn share (K4) — DONE (2026-08-16)

- Checked LinkedIn's official help page
  (linkedin.com/help/linkedin/answer/a528030) before building — it
  confirmed a static "Add to Profile" URL exists but didn't itself list
  the parameters, so cross-checked a dedicated URL-builder reference
  page too rather than guessing from memory (the plan note's own warning
  that these params have changed before). Base URL
  `https://www.linkedin.com/profile/add`, `startTask=CERTIFICATION_NAME`,
  plus `name`/`organizationName`/`issueYear`/`issueMonth`/`certId`/
  `certUrl` — `organizationName`, not `organizationId`, since an
  institute tenant has no registered LinkedIn Company Page to reference.
  Zero OAuth, zero vendor account, same "real, zero-setup" posture
  `ExternalLinkProvider` (S5) already established.
- `og:title`/`og:description` already existed from B13; this block added
  `og:image` (the tenant's own logo, resolved from the same `$tenant`
  object the controller already fetched — not `BrandingService::logoUrl()`,
  which resolves off `CurrentTenant`, the exact binding B13's own fix
  proved can't be trusted on this route) — the `<meta>` tag is omitted
  entirely when no logo exists, not rendered with a broken/empty URL.
- `composer test`: 800/801 passing (+2 —
  og-image-present-or-absent-based-on-tenant-logo,
  linkedin-share-url-has-the-documented-parameters), same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated, still out of scope. Both
  passed on the first run.

### B15 — Full test coverage, isolation registration, review pass — DONE (2026-08-16)

- `TenantModelRegistry` re-verified: `Assignment`/`AssignmentSubmission`/
  `Certificate` all present with correct `seedAttributes()`/
  `updateAttributes()`, unchanged since B0 — no drift across the
  intervening 15 blocks.
- Renormalization math (B6) and certificate tenant-clearing (B11) were
  re-read fresh and found correct as built (both already had dedicated
  tests from their own blocks proving the specific edge cases). The
  resubmission version-cap race the plan note flagged turned out to be
  real, but not in the way phrased ("two tabs submitting version 3
  simultaneously" — the actual gap was in `version_number`'s own
  `count()`-then-`create()` step at *any* version, not specifically the
  cap boundary).
- **Two real bugs found and fixed, neither caught by any earlier block's
  own tests**:
  1. `AssignmentSubmissionService::submit()` computed `version_number`
     from a plain `count()` with no lock. Fixed with a one-retry-on-collision
     shape (`submitWithRetry()`, recomputes the count fresh on a
     `QueryException`), matching `BatchEnrolmentService::createEnrolment()`'s
     own S4 B3 precedent for an identical count-then-insert race. The
     retryable insert also needed its own `DB::transaction()` — a real
     SAVEPOINT when nested inside a caller's already-open transaction —
     or the unique-constraint failure poisons the *whole* enclosing
     transaction (Postgres SQLSTATE 25P02, the identical class S10 B11's
     own review pass already hit once on an unrelated query) and the
     retry's own recompute query fails too. Testing this needed its own
     fix in turn: the first test version hooked
     `AssignmentSubmission::creating()` to simulate a competing insert,
     but that fires *inside* the new `DB::transaction()`, so the
     simulated race gets rolled back together with the deliberately-failed
     attempt instead of surviving it — switched to `DB::listen()` on the
     `count()` query itself, which runs *before* the savepoint opens.
  2. `Batch`/`Course` are both `SoftDeletes`, and `BatchResource`'s own
     `DeleteAction` has **no** guard against active enrolments — unlike
     `AssignmentResource`/`QuizResource`, which both hide delete once real
     submissions/attempts exist (confirmed by reading `BatchResource.php`
     directly, not assumed). An active `Enrolment` can point at a
     soft-deleted batch, or a batch whose own course was soft-deleted, and
     every Gradebook consumer read `$enrolment->batch->course`
     unconditionally: the shared `BuildsGradebookRows` trait (covering all
     three export formats), both `/teach` and `/admin` `Gradebook` pages,
     and `CheckCourseCompletionAndIssueCertificates` — four call sites,
     one fix pattern (`->whereHas('batch', fn ($q) => $q->whereHas('course'))`
     added to each one's own base query, not a null-check scattered
     through every column/loop body). A daily sweep silently dereferencing
     null every single day for one stale enrolment would have been an
     easy miss without this pass.
- `docs/PROJECT-STATUS.md` and `HANDOVER.md` updated to COMPLETE, matching
  S1-S9's own post-completion format — full per-bug detail lives in this
  plan doc (this section), HANDOVER carries only the summary + two new
  `## Avoid` entries for the race-retry and soft-delete-guard patterns.
- `composer test`: 804/805 passing (+4 —
  concurrent-submission-race-recovery, plus one soft-deleted-batch
  regression test each for the certificate sweep, the CSV export, and the
  `/teach` Gradebook page), same 1 pre-existing `CourseSlugUniquenessTest`
  failure, unrelated, still out of scope.

---

## Post-S10 review

Two real bugs found by this block's own dedicated pass, both detailed in
B15's block notes above (not repeated here): the
`AssignmentSubmissionService::submit()` version-number race (fixed with a
retry-on-`QueryException` shape + its own `DB::transaction()` savepoint),
and the `Batch`/`Course` soft-delete null-dereference across four
Gradebook consumers (fixed by filtering at the query level in all four).
Every prior block (B0-B14) had already been reviewed and tested as it
landed — full per-block detail, deviations, and gotchas are in each
block's own notes above, not duplicated here. **S10 — Assignments +
Gradebook + Certificates is COMPLETE: all 16 blocks (B0-B15), 804/805
tests green** (same 1 pre-existing, unrelated `CourseSlugUniquenessTest`
failure carried since S1/S2).
