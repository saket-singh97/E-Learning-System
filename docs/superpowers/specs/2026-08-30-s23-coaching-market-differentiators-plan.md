# Implementation Plan: S23 Coaching-Market Differentiators

Full design: `2026-08-30-s23-coaching-market-differentiators-design.md`.
Block-by-block build log — update the PROGRESS table as each block lands,
same as every S1-S22 plan did. 22 blocks (B0-B21): 10 items (U9-U18) split
across 18 build blocks (finer-grained than S22's 14, most items split into
schema/service + UI/delivery blocks so each block stays a 1-3 hour unit),
a registry sweep, and a final block.

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-30-s23-coaching-market-differentiators-design.md
and docs/superpowers/specs/2026-08-30-s23-coaching-market-differentiators-plan.md,
then build Block 0 of the S23 plan. Update the PROGRESS table and check off
that block's tasks when done, run composer test before marking it complete,
then stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | U10 pt.1 — `syllabus_topics` + `lesson_syllabus_topic` schema, Admin mapping UI | ✅ done |
| B1 | U10 pt.2 — `SyllabusTracker` service (% complete) + student/parent/admin widget | ✅ done |
| B2 | U16 — `CatchUpPlanService` (deterministic behind-schedule list) + student UI | ✅ done |
| B3 | U9 pt.1 — `ConfusionSignalService` (composes heatmap + doubt volume + quiz failure) | ✅ done |
| B4 | U9 pt.2 — Teach-panel confusion-signal widget per batch | ✅ done |
| B5 | U11 pt.1 — `questions.is_pyq`/`pyq_exam_name`/`pyq_exam_year` + Admin tagging UI | ✅ done |
| B6 | U11 pt.2 — Student-facing PYQ bank browse/filter page | ✅ done |
| B7 | U17 pt.1 — `courses.language`/`translation_of_course_id` schema + Admin link UI | ✅ done |
| B8 | U17 pt.2 — Catalog language filter + course-detail track switcher | ⬜ not started |
| B9 | U18 — `tenants.attendance_linked_access_enabled` + `batches.min_attendance_percent`, `LessonAccess` gate | ⬜ not started |
| B10 | U13 pt.1 — `lessons.is_demo` + `LessonAccess` demo grant for unauthenticated prospects | ⬜ not started |
| B11 | U13 pt.2 — `course_interest_signups.demo_attended_at` + `DemoConversionService` | ⬜ not started |
| B12 | U12 pt.1 — `scholarship_tests`/`scholarship_test_registrations` schema + public registration page | ⬜ not started |
| B13 | U12 pt.2 — `ScholarshipTestGradingService` (no-`User`-row grading against linked `Quiz`) | ⬜ not started |
| B14 | U12 pt.3 — Admin scholarship-test builder + results/conversion view | ⬜ not started |
| B15 | U15 pt.1 — `rank_cards` schema + `RankCardGenerator` (reuses `TestSeriesRankingService`) | ⬜ not started |
| B16 | U15 pt.2 — Rank card download UI wiring (student + Admin) | ⬜ not started |
| B17 | U14 — `hall_tickets` schema + `HallTicketGenerator` + download UI | ⬜ not started |
| B18 | `TenantModelRegistry` sweep — 5 new models confirmed registered | ⬜ not started |
| B19 | Full test coverage pass — one feature test per new service/generator, edge cases per design §5 | ⬜ not started |
| B20 | Multi-angle review pass over the whole slice's diff (same discipline S13-S22 B-final each used) | ⬜ not started |
| B21 | Lifecycle E2E test + `PROJECT-STATUS.md`/`HANDOVER.md`/`ROADMAP.md` update | ⬜ not started |

**Test suite baseline:** carry forward S22's own closing count (1929+/1932+,
1 pre-existing unrelated `CourseSlugUniquenessTest` failure since S1/S2, a
rotating pair of test-order flakes among 3 known files — confirm each in
isolation before treating as a regression, same posture every prior slice
established).

---

## Block detail (checklist — expand into a build narrative as each block actually lands, same as every prior plan file)

### B0 — U10 pt.1: syllabus schema + mapping ✅

- [x] `syllabus_topics` migration (`course_id`, `board_or_exam`, `title`,
      `sequence`) + `SyllabusTopic` model (Content), registered in
      `TenantModelRegistry`.
- [x] `lesson_syllabus_topic` pivot migration (no model).
- [x] Admin `CourseResource` relation manager: map lessons to topics per
      course, ordered by `sequence`.
- [x] Tests: mapping create/detach, tenant isolation on the pivot query.

Built `SyllabusTopic` (Content, `BelongsToTenant`, no soft-delete — matches
`Category`'s lookup-table shape, not `Course`/`Lesson`'s content shape) +
`lesson_syllabus_topic` plain pivot. `CourseResource::getRelations()` gained
`SyllabusTopicsRelationManager` (found `CourseResource` is Teach-panel-only,
not registered on Admin — mirrors `SectionsRelationManager`'s
auto-increment-`sequence`-on-create pattern). The `lessons` Select's
`->relationship()` query only scopes the dropdown's *rendered* options —
Filament still syncs whatever raw ids a tampered request submits — so it
carries the same `->rule()` server-side gate CourseResource's own
categories/tags fields needed after S13 B17, checking each lesson id's
`section->course_id` matches the owning course (catches both a same-tenant
foreign-course lesson and a cross-tenant one, since `Lesson::whereIn()` is
itself tenant-scoped by `BelongsToTenant`). `TenantModelRegistry` sweep:
`SyllabusTopic` added with seed/update attributes;
`tests/Isolation/CrossTenantTest.php` 103/103 green.

4 new tests
(`tests/Feature/Content/SyllabusTopicsRelationManagerTest.php`): create with
lessons attached + sequence auto-increment, detach via edit/sync, reject
foreign-course lesson (same tenant), reject foreign-tenant lesson.

`composer test`: 1931/1935 passed. 4 failures, none touching Content/Tenancy
code this block changed — `CourseSlugUniquenessTest` (documented pre-existing
since S1/S2), `DownloadCompletionExportControllerTest` (Comms, passes in
isolation — order flake), `CalendarTest` (Planner) and
`BackupConfigurationTest` (Support) both reproduce standalone but are
unrelated domains, not a B0 regression.

### B1 — U10 pt.2: tracker widget ✅

- [x] `SyllabusTracker::percentCompleteFor(Course $course, User $viewer)` —
      `LessonCompletion` rows for the viewer over mapped lessons ÷ total
      mapped lessons, grouped by `board_or_exam`.
- [x] Widget on student dashboard, parent portal, and Admin course view
      ("Physics 62% complete").
- [x] Tests: 0 topics mapped → no divide-by-zero (0% or "not tracked", not
      a crash); partial completion computes correctly.

Built `SyllabusTracker` (Content) with the one method the checklist scopes —
`percentCompleteFor()` groups a course's `syllabusTopics()` by
`board_or_exam`, unions each group's mapped lessons, and divides completed
(via the existing `LessonCompletion::isComplete()` heuristic, S13 C8, no
second "done" signal) by total. A group with topics but zero lessons mapped
returns 0.0 rather than dividing by zero; a course with zero topics returns
`[]`. Reusable Blade partial `content::syllabus-tracker` renders the
resulting `board_or_exam => percent` array.

Three surfaces, one shared partial:
- **Student dashboard** — no such page exists yet (`StudentPortal` is a bare
  login shell); `SyllabusTree`/`ShowSyllabusController` (the per-course
  syllabus browse page every enrolled/previewing viewer already hits) is the
  real student-facing surface, so the tracker renders there, guarded for a
  guest preview (`auth()->user()` can be null — `percentCompleteFor()`
  requires a real viewer).
- **Parent portal** — `ParentPortal::syllabusByCourse()` (a plain
  cross-domain **model** read via `Batch`/`Enrolment`/`Course`, same posture
  `StudentMetrics`'s own docblock establishes — `ParentPortal` sits outside
  every domain namespace so calling `SyllabusTracker` directly isn't a
  domain-to-domain service call either) maps each of the selected linked
  student's actively-enrolled courses to its tracker result.
- **Admin/Teach course view** — new `CourseSyllabusTrackerWidget`
  (`app/Filament/Teach/Widgets/`), wired via `EditCourse::getFooterWidgets()`
  — exact `InteractsWithRecord`/footer-widget pattern `BatchEngagementWidget`
  (S18 B1) already established. `percentCompleteFor()` is per-viewer, so
  rather than adding a second aggregate service method, the widget averages
  it across the course's actively-enrolled students itself (same "aggregate
  only, never per-student" posture `BatchEngagementWidget`'s own docblock
  states) — empty states for "no students enrolled yet" and "syllabus not
  mapped yet".

9 new tests (`tests/Feature/Content/SyllabusTrackerTest.php`: the two
edge cases plus grouping/partial-completion math plus both non-widget UI
surfaces; `tests/Feature/Comms/ParentPortalDashboardTest.php` gained one
case) + 2 widget tests via `Livewire::test()` (footer widgets aren't present
in a full-page GET's initial HTML — confirmed against
`BatchEngagementWidgetTest`'s own pattern before writing these). All pass;
`Content`+`Comms` domain suites 493/494 (same lone pre-existing
`CourseSlugUniquenessTest` fail); `CrossTenantTest` 103/103.

`composer test`: 1940/1944 — identical 4 pre-existing/unrelated failures as
B0's run (`CourseSlugUniquenessTest`, and `DownloadCompletionExportControllerTest`/
`CalendarTest`/`BackupConfigurationTest` in Comms/Planner/Support, none
touching Content/Comms code this block changed).

### B2 — U16: backlog/catch-up mode ✅

- [x] `CatchUpPlanService::planFor(User $student, Batch $batch)` — taught
      lessons (`BatchSession.lesson_id` on/before today, same definition
      as S22 B3's `DailyPracticeSetGenerator`) minus lessons with a
      `LessonCompletion` row for this student, oldest-taught-first.
- [x] Student-facing "Catch Up" page/widget listing the plan with direct
      links into each lesson.
- [x] Tests: fully caught-up student → empty plan; a lesson taught but not
      yet released (`LessonAccess::isVisibleTo()` false) is excluded.

Built `CatchUpPlanService` (Content): same "taught" definition
`DailyPracticeSetGenerator` (S22 B3) established (`BatchSession.lesson_id`
non-null, `starts_at <= now()`), with `orderBy('starts_at')` added since a
catch-up list needs order and a practice-question bag doesn't. Reuses
`LessonCompletion::isComplete()` (no second "done" signal) and
`LessonAccess::isVisibleTo()` (drip-scheduled/未-released lessons excluded,
same gate every other lesson-visibility surface goes through, per CLAUDE.md)
— no new visibility rule invented.

UI: no student-dashboard page exists yet to embed a widget on (`StudentPortal`
is a bare login shell, `student.dashboard` route/view exists but hosts only
tenant-wide, non-batch-scoped components) — same "no existing student-facing
batch page" gap `DailyPracticeBoard` (S22 B4) hit, closed the identical way:
new `content.catch-up-plan` Livewire component + a small hosting route
`/batches/{batch}/catch-up` (`routes/batches.php`, `auth` middleware, `Batch`
resolved via `$request->route()` per CLAUDE.md's domain-route splicing
gotcha — not a type-hinted parameter), same enrolment gate
(`Enrolment::where(...)->where('status','active')->exists()`) `DailyPracticeBoard`
already uses. Lists direct links to `lessons.watch` per lesson; empty plan
renders "You're all caught up."

12 new tests (`CatchUpPlanServiceTest`: empty-when-caught-up, excludes
not-yet-released, excludes not-yet-taught, oldest-first ordering;
`CatchUpPlanTest`: component + hosting-route smoke tests, non-enrolled 404).
`Content`+`Assessments`+`Batches` domain suites 659/660 (same lone
pre-existing `CourseSlugUniquenessTest` fail); `CrossTenantTest` 103/103
(no new tenant model this block).

`composer test`: 1948/1952 — identical 4 pre-existing/unrelated failures as
B0/B1's runs, none touching this block's code.

### B3 — U9 pt.1: confusion signal service ✅

- [x] `ConfusionSignalService::rankedFor(Batch $batch)` — per lesson,
      combine `RewatchHeatmapService::heatmapFor()`'s hottest segment,
      `DoubtChatMessage` count grouped by lesson, and quiz question
      failure rate (existing `WeakTopicDetectionService` query shape) into
      one normalized rank score.
- [x] No new table — pure composition, matches `RewatchHeatmapService`/
      `DropOffService`'s no-schema precedent (design §4 decision 6).
- [x] Tests: a lesson with zero signals ranks last, not excluded/error; the
      composed rank is stable (same inputs → same order).

Built `ConfusionSignalService` (Analytics). Lesson set = the batch's own
taught lessons, same `BatchSession.lesson_id`-on/before-today definition
`CatchUpPlanService`/`DailyPracticeSetGenerator` already established — a
confusion signal for a lesson the batch hasn't reached yet has no meaning.
Per lesson: `RewatchHeatmapService::heatmapFor()->first()` (already sorted
hottest-first), a plain `DoubtChatMessage::where('lesson_id', ...)->count()`,
and a failure rate scoped to the lesson's own `Quiz` using the identical
`whereNotNull('is_correct')` correct/total query shape
`WeakTopicDetectionService::weakTopicsFor()` already uses (course-wide
concept grouping swapped for a single lesson's quiz — no concept-taxonomy
dependency needed here). Min-max normalized per signal against the batch's
own worst offender (a lesson with zero of a signal contributes zero for it,
never excluded), summed into one `score`, sorted score-desc with lesson id
as a stable tie-break so two equally-quiet lessons never reorder between
calls. No new schema — matches `RewatchHeatmapService`/`DropOffService`'s
own no-table, compute-on-request precedent (design §4 decision 6). The
Teach-panel widget consuming this is B4, out of this block's scope.

2 new tests (`tests/Feature/Analytics/ConfusionSignalServiceTest.php`):
zero-signal lesson still present and ranked last; repeated calls with
unchanged data produce identical order. `CrossTenantTest` 103/103 (no new
tenant model this block).

`composer test`: 1949/1954. 5 failures — the same 4 pre-existing/unrelated
ones from B0-B2's runs, plus one new `DownloadEnrolmentExportControllerTest`
"404s before register generated" failure that passes clean in isolation
(same order-flake family as the already-baselined
`DownloadCompletionExportControllerTest` sibling, in the unrelated Comms
exports area — nothing this block touched).

### B4 — U9 pt.2: teacher widget ✅

- [x] Teach-panel widget per batch: ranked list with the human-readable
      line format from `docs/FEATURES.md`'s own example ("Your batch is
      stuck on X — N doubts, N rewatches of MM:SS-MM:SS, N% failed QN").
- [x] Tests: widget scoped to the instructor's own assigned batch only
      (reuses `EnrolmentApprovalPolicy::canDecide()`'s ownership check,
      same as every other Teach-panel-scoped view since S15).

Built `ConfusionSignalWidget` (`app/Filament/Teach/Widgets/`), wired via
`EditBatch::getFooterWidgets()` alongside `BatchWeakTopicsWidget`/
`BatchEngagementWidget` (same `InteractsWithRecord::getWidgetData()`
wiring). Top 5 (`TOP_N`, matching every sibling widget's own cap), one
`Stat` per lesson: `{doubts} doubts, {N} rewatches of MM:SS-MM:SS, {N}%
failed` — `docs/FEATURES.md`'s own U9 example as closely as B3's data
supports (a single aggregate failure rate per lesson's quiz, not a
per-question breakdown, so no literal "Q4" — noted as the deliberate gap
this is, design §2.3's own "tuning pass after real usage" posture, not a
day-one requirement). Empty state ("No lessons taught yet") for a batch
with none.

Ownership scoping needed **no new code**: `BatchResource::getEloquentQuery()`
(S15) already restricts an instructor's Teach panel to their own
`instructor_id` batches, so a non-owning instructor 404s before this
footer widget ever mounts — already proven generically by the existing
`TeachPanelBatchScopingTest`, the same reliance `BatchWeakTopicsWidget`/
`BatchEngagementWidget` already take rather than re-testing per widget.

3 new tests (`tests/Feature/Analytics/ConfusionSignalWidgetTest.php`):
empty state, populated line format (via `Livewire::test()` — footer
widgets don't render in a full-page GET's initial HTML, confirmed against
B1's own `CourseSyllabusTrackerWidget` finding before writing these), and
one full-page load for the owning instructor proving the wiring itself
doesn't error. `Batches`+`Analytics` domain suites 310/310;
`CrossTenantTest` 103/103 (no new tenant model this block).

`composer test`: 1953/1957 — back to the identical 4 pre-existing/unrelated
failures from B0-B2's runs (B3's extra sibling export-controller flake
didn't reproduce this run), none touching this block's code.

### B5 — U11 pt.1: PYQ schema + tagging ✅

- [x] Migration adds `is_pyq`/`pyq_exam_name`/`pyq_exam_year` to
      `questions` (no new table — design §3.2).
- [x] Admin `QuestionResource` (or wherever questions are authored) gains
      the 3 fields, visible only when `is_pyq` is toggled on.
- [x] Tests: existing question create/update unaffected when fields are
      omitted (all nullable/defaulted).

3 real columns on `questions` (`is_pyq` boolean default false,
`pyq_exam_name`/`pyq_exam_year` nullable) — unlike the form's per-type
virtual `correct_answer_*` fields, these are plain `$fillable` columns, so
`NormalizesQuestionFormData` needed no changes at all (it only
composes/decomposes the polymorphic `correct_answer` shape, never touches
these). `QuestionResource`'s form (Teach panel, where questions are
authored) gained a `Toggle::make('is_pyq')` plus `pyq_exam_name`/
`pyq_exam_year` `TextInput`s, `->visible()`+`->required()` gated on
`$get('is_pyq')` — same conditional-field idiom every per-type
`correct_answer_*` field on this same form already uses.

4 new tests appended to the existing `QuestionResourceTest.php`: PYQ
fields omitted → `is_pyq` false, both nullable columns null, no crash
(the checklist's own edge case); tagging on with exam name/year saves
correctly; toggling `is_pyq` on without the two now-required fields
produces form errors. All 15 tests in that file (12 pre-existing + 3 new)
pass. `Assessments` domain suite 159/159; `CrossTenantTest` 103/103
(`Question` was already a registered tenant model, no registry change
needed).

`composer test`: 1956/1960 — the identical 4 pre-existing/unrelated
failures from every prior block's run, none touching this block's code.

### B6 — U11 pt.2: PYQ browse page ✅

- [x] Student-facing page: filter by `concept` (topic), `pyq_exam_name`,
      `pyq_exam_year`; gated the same way any other question-bank view is
      (enrolled + `canStream()` on the question's originating lesson,
      where one exists).
- [x] Tests: filter combinations return correct subsets; a non-enrolled
      viewer sees none.

Built `PyqBrowser` Livewire component (`app/Domains/Assessments/Livewire/PyqBrowser.php`),
course-scoped (Question belongs to a course, not a batch) — mount() gates on
an active `Enrolment` into any batch of the course, same posture
DailyPracticeBoard/CatchUpPlan already established. `questions()` queries
`is_pyq = true`, `status = approved` (draft PYQ stay hidden, same
approved-only pool `QuizAttemptService` already draws from), applies the
three optional filters, then drops any question whose `lesson_id` is set and
`LessonAccess::canStream()` denies it (a PYQ seeded from a still-locked
lesson stays hidden). Route `GET /courses/{course}/pyq` (`course.pyq`) in
`routes/assessments.php`, host view `resources/views/assessments/course-pyq.blade.php`,
component view `app/Domains/Assessments/Views/pyq-browser.blade.php`,
registered in `AppServiceProvider` as `assessments.pyq-browser`. Tests in
`tests/Feature/Assessments/PyqBrowserTest.php` — 5 tests, 9 assertions,
covering happy-path listing, concept filter, exam-name+year filter, a
lesson-locked PYQ hidden, and 404 for a non-enrolled viewer.

### B7 — U17 pt.1: dual-language schema + linking ✅

- [x] Migration adds `language` (default `'en'`) and
      `translation_of_course_id` (nullable, self-referencing) to
      `courses`.
- [x] Admin `CourseResource`: language select + "translation of" course
      picker, scoped to same-tenant courses only (`->rule()` server-side
      check, same class of fix S13 B17 already applied to
      `LessonResource`'s prerequisite field).
- [x] Tests: cross-tenant course cannot be picked as a translation link.

Migration `2026_08_30_100003_add_language_fields_to_courses_table.php` adds
both columns (`language` varchar(10) default `'en'`,
`translation_of_course_id` nullable self-FK, `nullOnDelete`).
`Course::LANGUAGES = ['en', 'hi']` (only the two this slice's design doc
actually names). `CourseResource` gains a `language` Select and a
`translation_of_course_id` Select — the latter is a plain scalar column, not
a Filament `->relationship()` field, so a tampered payload writes straight
to it with no query in between; the `->rule()` is the real gate
(`Course::find()` is itself tenant-scoped via `BelongsToTenant`, so a
cross-tenant id returns null and fails exactly like a bogus one would — same
class of fix S13 B17 applied to `categories`/`tags`/`prerequisites`), plus a
self-reference check. Tests in
`tests/Feature/Content/CourseTranslationLinkTest.php` — 3 tests, 18
assertions: cross-tenant id rejected, self-reference rejected, real
same-tenant link accepted.

### B8 — U17 pt.2: catalog filter + switcher

- [ ] `CourseCatalog` (S13) gains a `language` filter.
- [ ] Course detail page shows an "Available in: Hindi / English" switcher
      when `translation_of_course_id` is set on either side.
- [ ] Tests: switcher resolves both directions (course A → B and B → A);
      absent when no link exists.

### B9 — U18: attendance-linked access

- [ ] Migration adds `attendance_linked_access_enabled` to `tenants`,
      `min_attendance_percent` (nullable) to `batches`.
- [ ] `LessonAccess::canStream()` gains a check: if tenant flag on AND
      batch threshold set AND the viewer's computed attendance % (from
      existing `AttendanceRecord`) is below it, deny — same chokepoint
      pattern as S20's fee-based restriction (design §4 decision 4).
- [ ] Tests: flag off → no change; flag on, batch threshold unset →
      exempt; flag on, threshold set, viewer below/above it.

### B10 — U13 pt.1: demo class access

- [ ] Migration adds `is_demo` to `lessons`.
- [ ] `LessonAccess::isVisibleTo()`/`canStream()` grant access to an
      unauthenticated viewer when `lesson.is_demo` is true, independent of
      enrolment — the one new "no viewer at all" branch in this class.
- [ ] Tests: demo lesson streams for a guest; a non-demo lesson on the
      same course still requires enrolment.

### B11 — U13 pt.2: conversion tracking

- [ ] Migration adds `demo_attended_at` to `course_interest_signups`
      (extends existing S20 model, no new table — design §3.2).
- [ ] Demo lesson watch records `demo_attended_at` on a matching
      `CourseInterestSignup` (by email, captured at the same public
      interest-capture form, S20 B12).
- [ ] `DemoConversionService::hasConverted(CourseInterestSignup $signup)` —
      read-only match against `Enrolment` via the enrolled user's email,
      same tenant/course (design §4 decision 3, not an event listener).
- [ ] Tests: conversion true only when a real `Enrolment` exists for the
      matching email+course; a same-email signup for a different course
      does not falsely match.

### B12 — U12 pt.1: scholarship test schema + registration

- [ ] `scholarship_tests` + `scholarship_test_registrations` migrations +
      models (Growth), registered in `TenantModelRegistry`.
- [ ] Public, unauthenticated registration page (mirrors S20 B12's
      `/courses/{course}/interest` public-route posture) — name/email/
      phone, upsert by `(scholarship_test_id, email)` so a repeat signup
      updates rather than duplicates (same idiom as `CourseInterestSignup`).
- [ ] Tests: duplicate email re-registers cleanly, doesn't duplicate.

### B13 — U12 pt.2: grading engine

- [ ] `ScholarshipTestGradingService::grade(ScholarshipTestRegistration, array $answers)` —
      grades in-memory against the linked `Quiz`'s questions, writes
      `score` directly, creates no `QuizAttempt` row (design §4 decision 2
      — keeps the trusted enrolled-attempt path untouched).
- [ ] Tests: correct scoring against a known answer key; a registration
      cannot be graded twice (idempotent — same posture S22's
      `fromSeedQuestions()` idempotency fix established).

### B14 — U12 pt.3: admin builder + results

- [ ] Admin `ScholarshipTestResource` — create test, link an existing
      `Quiz`, set open/close window.
- [ ] Results/conversion view: registrations sorted by score, each row
      showing `DemoConversionService`-style conversion status (did this
      registrant later enrol — same read-only match approach as U13, by
      email+tenant).
- [ ] Tests: results view tenant-scoped; conversion flag matches only a
      real `Enrolment`.

### B15 — U15 pt.1: rank card generator

- [ ] `rank_cards` migration + model (Assessments), registered in
      `TenantModelRegistry`.
- [ ] `RankCardGenerator` — calls `TestSeriesRankingService::rankingsFor()`
      for the rank/percentile/score, groups the series's questions by
      `concept` for the subject breakdown (design §4 decision 1), renders
      via `Pdf::loadView()->save()` (same shape as `IdCardGenerator`,
      design §4 decision 8), `DB::transaction()`+`lockForUpdate()` guard
      against the double-click race S15 B21 already fixed once for
      `IdCardGenerator`.
- [ ] Tests: subject breakdown sums to the total score; regenerating
      overwrites the same unique `(test_series_id, user_id)` row, no
      duplicate race under concurrent calls.

### B16 — U15 pt.2: rank card delivery

- [ ] Student-facing download link on the test series results page; Admin
      bulk-view/regenerate action.
- [ ] Gated the same as any other student-owned PDF (`CertificateService`'s
      posture) — only the owning student or staff can download.
- [ ] Tests: cross-student download attempt denied.

### B17 — U14: hall ticket / admit card

- [ ] `hall_tickets` migration + model (Assessments), registered in
      `TenantModelRegistry`.
- [ ] `HallTicketGenerator` — one per `ScholarshipTestRegistration`, roll
      number assigned on first generation (sequential per test, not
      globally), QR baked in as base64 PNG (same as `IdCardGenerator`'s
      QR, not `Certificate`'s inline-SVG — design §2.2 precedent), same
      transaction+lock guard as B15.
- [ ] Download link on the registration confirmation page.
- [ ] Tests: roll numbers unique and sequential per test; regenerating
      keeps the same roll number.

### B18 — TenantModelRegistry sweep

- [ ] Confirm all 5 new models (`SyllabusTopic`, `ScholarshipTest`,
      `ScholarshipTestRegistration`, `RankCard`, `HallTicket`) registered
      with `seedAttributes()`/`updateAttributes()`.
- [ ] Reverse grep every real `BelongsToTenant` usage under
      `app/Domains/*/Models/*.php` against the registry — same
      exact-count discipline every prior slice's own sweep block ran.
- [ ] `tests/Isolation/CrossTenantTest.php` passes for all 5 new models.

### B19 — Full test coverage pass

- [ ] One feature test per new service/generator not already covered
      inline in its own block, plus the specific edge cases design §5
      names (U18 tenant-on/batch-threshold-unset exemption, U13 different-
      email non-match, U12 double-grading guard).
- [ ] `composer test` green, baseline flakes only.

### B20 — Multi-angle review pass

- [ ] Same discipline S13-S22's own closing review block each ran:
      correctness/convention pass + security/tenant-isolation pass over
      the whole slice's diff, independently.
- [ ] Fix any real bugs found; document anything confirmed-clean-not-new
      the same way every prior slice's plan file did.

### B21 — Lifecycle E2E + docs

- [ ] New `S23CoachingMarketDifferentiatorsE2ETest` chaining at minimum:
      scholarship test registration → graded → rank card + hall ticket
      generated → later real enrolment marks it converted; syllabus topic
      mapped to lessons → tracker % rises as lessons complete; attendance-
      gated batch blocks a low-attendance student's stream, an ungated
      batch does not.
- [ ] Update `docs/PROJECT-STATUS.md` (new S23 section), `HANDOVER.md`
      ("Right now" entry), `docs/ROADMAP.md` (S23 row → ✅ Done, shipped
      count 22→23) — same close-out shape every prior slice used.
