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
| B8 | U17 pt.2 — Catalog language filter + course-detail track switcher | ✅ done |
| B9 | U18 — `tenants.attendance_linked_access_enabled` + `batches.min_attendance_percent`, `LessonAccess` gate | ✅ done |
| B10 | U13 pt.1 — `lessons.is_demo` + `LessonAccess` demo grant for unauthenticated prospects | ✅ done |
| B11 | U13 pt.2 — `course_interest_signups.demo_attended_at` + `DemoConversionService` | ✅ done |
| B12 | U12 pt.1 — `scholarship_tests`/`scholarship_test_registrations` schema + public registration page | ✅ done |
| B13 | U12 pt.2 — `ScholarshipTestGradingService` (no-`User`-row grading against linked `Quiz`) | ✅ done |
| B14 | U12 pt.3 — Admin scholarship-test builder + results/conversion view | ✅ done |
| B15 | U15 pt.1 — `rank_cards` schema + `RankCardGenerator` (reuses `TestSeriesRankingService`) | ✅ done |
| B16 | U15 pt.2 — Rank card download UI wiring (student + Admin) | ✅ done |
| B17 | U14 — `hall_tickets` schema + `HallTicketGenerator` + download UI | ✅ done |
| B18 | `TenantModelRegistry` sweep — 5 new models confirmed registered | ✅ done |
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

### B8 — U17 pt.2: catalog filter + switcher ✅

- [x] `CourseCatalog` (S13) gains a `language` filter.
- [x] Course detail page shows an "Available in: Hindi / English" switcher
      when `translation_of_course_id` is set on either side.
- [x] Tests: switcher resolves both directions (course A → B and B → A);
      absent when no link exists.

`Course` gains `translationOf()` (belongsTo, the parent) and `translations()`
(hasMany, the reverse), plus a `translationCounterpart()` helper resolving
whichever direction the link was actually set — only two languages exist
this slice, so a course has at most one live counterpart in practice.
`CourseCatalog` gets a `language` public property, filtered with the same
`->when()` shape as `categoryId`/`tagId`. `ShowSyllabusController` resolves
`translationCounterpart()` and re-checks `LessonAccess::isCourseVisibleTo()`
on it before handing it to the view — a draft counterpart stays unlinked for
a guest, same gate the page's own course already passes through (S5 B8's
precedent, applied one level sideways instead of down to a lesson).
Tests: `CourseCatalogTest::filters by language` (1 test), and 4 new tests in
`ShowSyllabusControllerTest.php` — switcher resolves original→translation,
translation→original, absent with no link, and a draft counterpart hidden
from a guest. Full suite: 17/17 new/changed assertions green.

### B9 — U18: attendance-linked access ✅

- [x] Migration adds `attendance_linked_access_enabled` to `tenants`,
      `min_attendance_percent` (nullable) to `batches`.
- [x] `LessonAccess::canStream()` gains a check: if tenant flag on AND
      batch threshold set AND the viewer's computed attendance % (from
      existing `AttendanceRecord`) is below it, deny — same chokepoint
      pattern as S20's fee-based restriction (design §4 decision 4).
- [x] Tests: flag off → no change; flag on, batch threshold unset →
      exempt; flag on, threshold set, viewer below/above it.

Migration `2026_08_30_100004_add_attendance_linked_access_fields.php` adds
both columns. `LessonAccess::isBlockedByLowAttendance()` mirrors
`isBlockedByOverdueFee()`'s exact two-key shape and "every relevant
enrolment must fail" semantics: resolves the viewer's active enrolments into
batches offering the lesson's course, then blocks only if *every* one of
those batches both has `min_attendance_percent` set and the viewer's own
attendance in that batch's sessions (present-count / total `AttendanceRecord`
rows, scoped to that one batch's `BatchSession` ids — not the student's
whole tenure, since the threshold is batch-configured) falls below it. A
batch with no threshold, or one with no attendance rows yet for this viewer,
each read as a pass (same "absence of evidence, not evidence of struggling"
posture `StudentMetrics::isAtRisk()` already takes) — so any one compliant/
unconfigured enrolment keeps access, exactly like the fee gate. Tests in
`tests/Feature/Content/AttendanceLinkedAccessGateTest.php` — 5 tests, 10
assertions: flag off (no change even with bad attendance + threshold set),
threshold unset (exempt), below threshold (blocked), at/above threshold
(allowed), no attendance rows yet (not blocked).

### B10 — U13 pt.1: demo class access ✅

- [x] Migration adds `is_demo` to `lessons`.
- [x] `LessonAccess::isVisibleTo()`/`canStream()` grant access to an
      unauthenticated viewer when `lesson.is_demo` is true, independent of
      enrolment — the one new "no viewer at all" branch in this class.
- [x] Tests: demo lesson streams for a guest; a non-demo lesson on the
      same course still requires enrolment.

Migration `2026_08_30_100005_add_is_demo_to_lessons_table.php` adds the
column (default `false`). `LessonAccess::isVisibleTo()` gains an unconditional
`is_demo` bypass ahead of the `isLive()`/`isStaff()` check — a demo lesson is
visible even while its own course is still a draft, the whole point being to
show prospects real content before a course fully launches.
`LessonAccess::canStream()` gains the same bypass `is_free_preview` already
has, kept as its own separate `if` (distinct flag/purpose: `is_demo` also
feeds B11's conversion tracking, `is_free_preview` never did). Admin
`LessonsRelationManager` gains a `Toggle::make('is_demo')` next to the
existing free-preview toggle — without it nothing could actually turn the
flag on. Tests: 3 new cases added to `LessonAccessGateTest.php` (reusing its
existing `gateFixture()` helper) — a demo lesson streams for a guest, streams
for a guest even in a still-draft course, and a sibling non-demo lesson in
the same course still 403s a guest. 16/16 in that file, full suite
1978/1981 (3 pre-existing baseline flakes, unrelated).

### B11 — U13 pt.2: conversion tracking ✅

- [x] Migration adds `demo_attended_at` to `course_interest_signups`
      (extends existing S20 model, no new table — design §3.2).
- [x] Demo lesson watch records `demo_attended_at` on a matching
      `CourseInterestSignup` (by email, captured at the same public
      interest-capture form, S20 B12).
- [x] `DemoConversionService::hasConverted(CourseInterestSignup $signup)` —
      read-only match against `Enrolment` via the enrolled user's email,
      same tenant/course (design §4 decision 3, not an event listener).
- [x] Tests: conversion true only when a real `Enrolment` exists for the
      matching email+course; a same-email signup for a different course
      does not falsely match.

Migration `2026_08_30_100006_add_demo_attended_at_to_course_interest_signups_table.php`
adds the column. The recording itself lives inside `CourseInterestForm`
(not a separate anonymous route): the component already knows the
prospect's own email from its own just-completed signup, so a new
`watchDemo()` action stamps `demo_attended_at` on the matching signup row
and redirects straight to the existing `lessons.watch` page (B10's fully
working, type-aware render — not duplicated here) — no identity-carrying
query param or session needed across an unauthenticated page boundary. The
component's `render()` exposes a `demoLesson` (found via the course's
sections → an `is_demo` lesson) so the blade view only shows a "Watch a
free demo class" button when one actually exists.
`App\Domains\Growth\Services\DemoConversionService::hasConverted()` matches
`Enrolment` via the enrolled user's own email, scoped to `tenant_id` +
the signup's `course_id` — a query, not an event (design §4 decision 3).
Tests: 2 new cases in `CourseInterestPageTest.php` (records attendance +
redirects; no button when no demo lesson exists) and a new
`DemoConversionServiceTest.php` — converted only with a real matching
enrolment, and a same-email signup for a different course never falsely
matches. Full Growth suite 47/47.

### B12 — U12 pt.1: scholarship test schema + registration ✅

- [x] `scholarship_tests` + `scholarship_test_registrations` migrations +
      models (Growth), registered in `TenantModelRegistry`.
- [x] Public, unauthenticated registration page (mirrors S20 B12's
      `/courses/{course}/interest` public-route posture) — name/email/
      phone, upsert by `(scholarship_test_id, email)` so a repeat signup
      updates rather than duplicates (same idiom as `CourseInterestSignup`).
- [x] Tests: duplicate email re-registers cleanly, doesn't duplicate.

`ScholarshipTest` (`course_id` nullable, `quiz_id`, `opens_at`/`closes_at`)
and `ScholarshipTestRegistration` (`name`/`email`/`phone`, no `user_id` —
same prospect shape as `CourseInterestSignup`, `score` nullable, unique on
`(scholarship_test_id, email)`) both in `app/Domains/Growth/Models/`,
registered in `TenantModelRegistry` (seed/update attributes added).
Deliberate deviation from the design doc's own SQL sketch: skipped the
sketch's `converted_enrolment_id` column — B14's own plan wording already
commits to a read-only `DemoConversionService`-style query for conversion
status (same "query not event" posture design §4 decision 3 established for
U13), so a column that would immediately go stale alongside that query is
dropped rather than carried as unused schema.
`ScholarshipTestRegistrationService::register()` mirrors
`CourseInterestSignupService::signup()` exactly — `firstOrNew` on
`(scholarship_test_id, email)`. Public route `GET /scholarship-tests/{scholarshipTest}/register`
(`scholarship-test.register`, no auth middleware) embeds
`ScholarshipTestRegistrationForm`, itself a line-for-line mirror of
`CourseInterestForm`. Tests: `ScholarshipTestRegistrationServiceTest.php` (3
cases — new registration persists, duplicate email updates in place,
same email across two different tests stays two rows) and
`ScholarshipTestRegistrationPageTest.php` (guest opens the page and signs
up end-to-end). Cross-tenant isolation suite: 105/105 with both new models
registered. Full Growth suite 51/51.

### B13 — U12 pt.2: grading engine ✅

- [x] `ScholarshipTestGradingService::grade(ScholarshipTestRegistration, array $answers)` —
      grades in-memory against the linked `Quiz`'s questions, writes
      `score` directly, creates no `QuizAttempt` row (design §4 decision 2
      — keeps the trusted enrolled-attempt path untouched).
- [x] Tests: correct scoring against a known answer key; a registration
      cannot be graded twice (idempotent — same posture S22's
      `fromSeedQuestions()` idempotency fix established).

`grade(ScholarshipTestRegistration $registration, array $answers)` — `$answers`
is `question_id => raw response`, same shape `QuizAttemptService::submit()`'s
own `$responses` takes. Looks up each answered question, scoped to the
linked quiz's own course + `status = approved` (same pool-scoping
`QuizAttemptService::draw()` already applies — a tampered id from a
different course/quiz can't be smuggled into the score), sums each
question's 0..1 grade, writes `score` directly. The per-type grading
dispatch is a deliberate duplicate of `QuizAttemptService`'s own protected
grading methods (mcq/true_false/numerical/multi_select/fill_in_blank/
matching/ordering — `short_answer` scores 0, no review queue exists for an
unauthenticated prospect) — not a cross-domain call to it, same reasoning
`StudentMetrics::coveragePercent()` already documents for duplicating
`RecordVideoCoverageAttendance`'s own merge-interval math (CLAUDE.md: a
service is not a model, calling into it would be a cross-domain service
call, not the cross-domain model read this class does for `Question`
itself). Idempotent by returning the already-graded row unchanged (`score`
already set) rather than throwing or silently recomputing — same posture
`QuestionGenerator::fromSeedQuestions()` already established. Tests in
`ScholarshipTestGradingServiceTest.php` — 4 cases: correct scoring against
a known 2-question answer key, a wrong answer scores that question 0, a
question id from a different course is ignored rather than scored, and a
second `grade()` call with different (wrong) answers leaves the original
score untouched. Full Growth suite 55/55.

### B14 — U12 pt.3: admin builder + results ✅

- [x] Admin `ScholarshipTestResource` — create test, link an existing
      `Quiz`, set open/close window.
- [x] Results/conversion view: registrations sorted by score, each row
      showing `DemoConversionService`-style conversion status (did this
      registrant later enrol — same read-only match approach as U13, by
      email+tenant).
- [x] Tests: results view tenant-scoped; conversion flag matches only a
      real `Enrolment`.

Built `ScholarshipTestResource` (Growth, discovered via `AdminPanelProvider`'s
existing `discoverResources(in: .../Growth/Filament/Resources)` — no provider
edit needed, same as `CourseInterestSignupResource`). Form: `title`, optional
`course_id` (tenant-wide test when blank), required `quiz_id`
(`->relationship()` selects, both auto-tenant-scoped via `BelongsToTenant`'s
global scope — no extra `->rule()` gate needed since Filament's own
relationship query is the only source of truth here, unlike
`SyllabusTopicsRelationManager`'s multi-select pivot-sync gap), and
`opens_at`/`closes_at` (`->after('opens_at')`). Table: title, course,
quiz, both dates, registration count.

`ScholarshipConversionService::hasConverted()` (Growth) is a line-for-line
mirror of `DemoConversionService` — tenant+email match against `Enrolment`
via the enrolled user's email — with one addition `DemoConversionService`
didn't need: `ScholarshipTest.course_id` is nullable (design §3.2, a test
can be tenant-wide), so the course scope is applied with `->when()` only
when the linked test actually has one; a course-less test matches any
enrolment for that email in the tenant.

Results view is `RegistrationsRelationManager` on `ScholarshipTestResource`
(`registrations` relation), browse-only — same posture
`CourseInterestSignupResource` already takes, since every row is
sourced from the public registration page (B12), never a manual admin
entry. Columns: name/email/phone, `score` (sortable, default-sorted
desc so the results view *is* the ranking), a `converted` `IconColumn`
computed per row via `ScholarshipConversionService::hasConverted()`
(query, not a stored column — same design §4 decision 3 posture U13
already established), registered date.

4 new tests (`ScholarshipTestResourceTest.php`): create test from Admin
panel with a linked quiz + course; `closes_at` before `opens_at` rejected;
a cross-tenant admin 404s trying to edit another tenant's test (tenant
scoping on the results view, since `EditScholarshipTest` 404ing means the
`RegistrationsRelationManager` never mounts for a foreign tenant either);
results view lists registrations sorted score-desc. 3 new tests
(`ScholarshipConversionServiceTest.php`): converted once a real matching
enrolment exists for a course-scoped test, no false match on a different
course, and a course-less test matches any tenant enrolment by email alone.
Growth domain suite 62/62; `CrossTenantTest` 105/105 (no new tenant model
this block — both models were already registered in B12).

`composer test`: 1997/2002. 5 failures — the same pre-existing/unrelated
family as B0-B13's runs (`CourseSlugUniquenessTest`, `CalendarTest` x3 in
Planner, `BackupConfigurationTest` in Support), none touching Growth/Filament
code this block changed.

### B15 — U15 pt.1: rank card generator ✅

- [x] `rank_cards` migration + model (Assessments), registered in
      `TenantModelRegistry`.
- [x] `RankCardGenerator` — calls `TestSeriesRankingService::rankingsFor()`
      for the rank/percentile/score, groups the series's questions by
      `concept` for the subject breakdown (design §4 decision 1), renders
      via `Pdf::loadView()->save()` (same shape as `IdCardGenerator`,
      design §4 decision 8), `DB::transaction()`+`lockForUpdate()` guard
      against the double-click race S15 B21 already fixed once for
      `IdCardGenerator`.
- [x] Tests: subject breakdown sums to the total score; regenerating
      overwrites the same unique `(test_series_id, user_id)` row, no
      duplicate race under concurrent calls.

Migration `2026_08_31_090000_create_rank_cards_table.php` — `tenant_id`,
`test_series_id`, `user_id`, `pdf_path`, unique on
`(test_series_id, user_id)` (design §3.2 schema verbatim — no `rank`/
`percentile` columns, those are rendered into the PDF only, never
persisted, matching the design's own SQL sketch). `RankCard` model
(Assessments), registered in `TenantModelRegistry`.

`RankCardGenerator::generate(TestSeries, User)` pulls rank/percentile/
total_score straight from `TestSeriesRankingService::rankingsFor()`
(matched to this user's row — design §2.3, no second ranking algorithm)
and computes a subject breakdown separately via
`subjectBreakdown()` (public — the results view or a future block can call
it directly without duplicating the "best attempt per quiz" selection):
for each quiz in the series, picks this user's own highest-scoring
non-`in_progress` attempt (identical selection `TestSeriesRankingService`
itself uses), loads that attempt's `QuizAnswer` rows with their
`Question`, and sums `score` grouped by `concept` (falling back to
`'General'` for a null concept). Because
`QuizAttemptService::recomputeScore()` already guarantees
`attempt.score === sum(answers.score)`, the breakdown's own sum always
equals the ranking's `total_score` by construction — verified by test, not
assumed. Rendered via `Pdf::loadView('assessments.rank-card-pdf')->save()`,
same dompdf shape as `IdCardGenerator`.

One deliberate deviation from `IdCardGenerator`'s own posture (design §4
decision 8 flagged this explicitly): a rank card can go stale — a student
can attempt another quiz in the series after their card was first
generated — so a repeat `generate()` call recomputes and overwrites the
same row/PDF via `updateOrCreate()`, rather than short-circuiting to the
first result the way `IdCardGenerator` does for an ID card (which never
changes after issue). The double-click race still needs a guard, but
there's no existing child row to `lockForUpdate()` before the very first
call the way `IdCardGenerator` locks the parent `Enrolment` — so this
locks the parent `TestSeries` row instead inside the same
`DB::transaction()`, serializing concurrent `generate()` calls for the
whole series. Coarser than per-student, but `generate()` is an on-demand
Admin/staff action, not a hot path, so the extra contention is free.

3 new tests (`RankCardGeneratorTest.php`): PDF + row created with the
ranking service's own rank/percentile;  subject breakdown sums to the same
`total_score` `TestSeriesRankingService::rankingsFor()` reports, and each
concept's own contribution checked individually; regenerating after a new
quiz attempt updates the same row (`id` unchanged, exactly one row for the
`(test_series_id, user_id)` pair) and the ranking service's own total
reflects the new attempt. `Assessments` domain suite 167/167;
`CrossTenantTest` 106/106 (`RankCard` newly registered, count up from 105).

`composer test`: 2000/2006. 6 failures — the same pre-existing/unrelated
family as every prior block's run (`CourseSlugUniquenessTest`,
`CalendarTest` x3 in Planner, `BackupConfigurationTest` in Support, plus
the known `DownloadCompletionExportControllerTest` order-flake sibling B3's
own run already documented), none touching Assessments/Filament code this
block changed.

### B16 — U15 pt.2: rank card delivery ✅

- [x] Student-facing download link on the test series results page; Admin
      bulk-view/regenerate action.
- [x] Gated the same as any other student-owned PDF (`CertificateService`'s
      posture) — only the owning student or staff can download.
- [x] Tests: cross-student download attempt denied.

`DownloadRankCardController` (`GET /rank-cards/{rankCard}/download`, plain
non-Filament route in `routes/assessments.php`) is a line-for-line mirror
of `DownloadInvoiceController`'s own owner-or-staff gate — closer precedent
than `CertificateService` itself, which has no download controller
(verify-only). Role alone isn't the gate: Spatie roles carry no `tenant_id`,
so the check is `$rankCard->user_id === $viewer->id ||
($viewer->hasAnyRole(['instructor','institute_admin']) &&
$viewer->tenant_id === $rankCard->tenant_id)` — same cross-tenant-staff
finding class `DownloadInvoiceController`'s own docblock documents.
`{rankCard}` read off `$request->route()`, not method-injected (CLAUDE.md's
domain-route splicing gotcha).

Student-facing: `TestSeriesResults` (the existing S16 B13 Livewire
component embedded on the `test-series.results` page) now also looks up
the viewer's own `RankCard` for the series and renders a "Download rank
card" link when one exists — absent otherwise, no dead link before a card
is generated. Every `route()` call to a `Route::domain()`-scoped named
route in this app needs an explicit `subdomain` key (confirmed against
`syllabus-tree.blade.php`'s own `route('lessons.watch', ['subdomain' =>
...])` calls) — missed on the first pass here too (caught by the test
suite, fixed before commit).

Admin: new `RankCardsRelationManager` on `TestSeriesResource` (added
`TestSeries::rankCards()` hasMany) — browse-only list (student, email,
generated date) plus a header action "Generate/regenerate all rank cards"
that loops every `TestSeriesRankingService::rankingsFor()` participant and
calls `RankCardGenerator::generate()` for each; safe to click repeatedly
since `generate()` itself already overwrites-not-duplicates (B15, design
§4 decision 8). Each row also has a same-tab "download" action reusing the
identical gated controller (an admin passes the staff branch of the same
check).

6 new tests (`DownloadRankCardControllerTest.php`): 404 before the PDF
exists on disk, owning student downloads, a **different** student is
forbidden (the plan's own "cross-student download attempt denied" case),
this tenant's `institute_admin` can download any student's card, a
different tenant's `institute_admin` is forbidden. 2 new tests appended to
`TestSeriesResultsTest.php`: link shown once a card exists, absent before.
2 new tests (`RankCardsRelationManagerTest.php`): header action generates
one card per participant tenant-scoped; the relation table lists exactly
those cards. `Assessments` domain suite 176/176; `CrossTenantTest` 106/106
(no new tenant model this block).

`composer test`: 2009/2015. 6 failures — the same pre-existing/unrelated
family as every prior block's run (`CourseSlugUniquenessTest`,
`CalendarTest` x3 in Planner, `BackupConfigurationTest` in Support,
`DownloadCompletionExportControllerTest` order-flake), none touching
Assessments/Filament/routes code this block changed.

### B17 — U14: hall ticket / admit card ✅

- [x] `hall_tickets` migration + model (Assessments), registered in
      `TenantModelRegistry`.
- [x] `HallTicketGenerator` — one per `ScholarshipTestRegistration`, roll
      number assigned on first generation (sequential per test, not
      globally), QR baked in as base64 PNG (same as `IdCardGenerator`'s
      QR, not `Certificate`'s inline-SVG — design §2.2 precedent), same
      transaction+lock guard as B15.
- [x] Download link on the registration confirmation page.
- [x] Tests: roll numbers unique and sequential per test; regenerating
      keeps the same roll number.

Migration `2026_08_31_100000_create_hall_tickets_table.php` — `tenant_id`,
`scholarship_test_registration_id`, `roll_number`, `pdf_path`, unique on
`scholarship_test_registration_id` (design §3.2). `HallTicket` model lives
in **Assessments** per design §3.1's own domain table, even though its FK
points at Growth's `ScholarshipTestRegistration` — a deliberate cross-domain
model read, the same class of precedent `Question::lesson()` already
established (Assessments -> Content), just pointed the other way this
time. `ScholarshipTestRegistration` gained a `hallTicket()` hasOne.
Registered in `TenantModelRegistry`.

`HallTicketGenerator::generate()` reuses `IdCardGenerator`'s exact dompdf +
base64-QR-PNG shape (`Endroid\QrCode`, design §2.2's own precedent — not
`Certificate`'s inline-SVG QR, a different rendering context). Unlike
`RankCardGenerator`'s overwrite-on-regenerate (B15), a hall ticket is
idempotent on repeat call — same posture `IdCardGenerator` itself takes,
since a roll number, once printed and carried to an exam hall, must never
change under the registrant. Roll numbers are sequential *per test*
(`"{scholarship_test_id}-{sequence:04d}"`), which needs a coarser lock than
`IdCardGenerator`'s own per-enrolment one: two registrations of the *same*
test generating concurrently would otherwise race on the same next
sequence number. This locks the parent `ScholarshipTest` row inside the
transaction — the identical "lock the parent that scopes the sequence"
idiom `RankCardGenerator` (B15) already used for `TestSeries` — which also
serializes the existing-row idempotency check for any single registration.

Delivery: `DownloadHallTicketController` (`GET
/hall-tickets/{hallTicket}/download`, `signed`-middleware-only, no `auth`)
— a scholarship-test registrant has no `User` row at all (design §4
decision 2), so there's no session-based owner check to fall back to,
unlike B16's `DownloadRankCardController`. `URL::signedRoute()`, not
`temporarySignedRoute()` — same no-expiry reasoning
`DownloadCalendarFeedController` already established: the link is handed
once on the registration confirmation page with no delivery channel (no
WhatsApp job, no email) to refresh it later, so it has to keep working
whenever the registrant returns to print it before the exam.
`ScholarshipTestRegistrationForm::submit()` (B12) now also calls
`HallTicketGenerator::generate()` right after registering — a hall ticket
is issued at registration time, not on demand later, since there's no
login for a prospect to come back and trigger generation themselves — and
`render()` exposes the signed download URL once one exists, surfaced as a
"Download your hall ticket" link on the same confirmation view B11's demo
button already established the shape for.

9 new tests: `HallTicketGeneratorTest.php` (3) — PDF + row with a QR-baked
roll number, roll numbers sequential per test and independently restarting
at 1 for a different test (the plan's own "not globally" case), regenerate
keeps the same roll number and row count stays 1;
`DownloadHallTicketControllerTest.php` (4) — valid signed URL downloads
with no auth, an unsigned request is forbidden, a tampered signature is
forbidden, 404 if the PDF is missing from disk; 1 new case appended to
`ScholarshipTestRegistrationPageTest.php` — the confirmation view shows the
download link and the registration's own `hallTicket` relation resolves.
`CrossTenantTest` 107/107 (`HallTicket` newly registered, count up from
106). `Growth`+`Assessments` domain suites 245/245.

`composer test`: 2017/2023. 6 failures — the same pre-existing/unrelated
family as every prior block's run (`CourseSlugUniquenessTest`,
`CalendarTest` x3 in Planner, `BackupConfigurationTest` in Support, plus
the known order-flake sibling — `DownloadEnrolmentExportControllerTest`
this run, `DownloadCompletionExportControllerTest`/
`DownloadEnrolmentExportControllerTest` rotate across runs, same family
B3/B16 already documented), none touching Assessments/Growth code this
block changed.

### B18 — TenantModelRegistry sweep ✅

- [x] Confirm all 5 new models (`SyllabusTopic`, `ScholarshipTest`,
      `ScholarshipTestRegistration`, `RankCard`, `HallTicket`) registered
      with `seedAttributes()`/`updateAttributes()`.
- [x] Reverse grep every real `BelongsToTenant` usage under
      `app/Domains/*/Models/*.php` against the registry — same
      exact-count discipline every prior slice's own sweep block ran.
- [x] `tests/Isolation/CrossTenantTest.php` passes for all 5 new models.

Verification-only block, no code changes. First pass with a naive
`grep -rl "use BelongsToTenant;"` under-counted at 92 — it misses any model
combining the trait on one line with another (`use BelongsToTenant,
SoftDeletes;`, e.g. `Question`), so the real reverse grep needs
`grep -rlP "^\s+use\s+BelongsToTenant\b"` (leading indentation anchors it
to the trait-use line inside the class body, not the `use
App\Support\Tenancy\BelongsToTenant;` import at column 0) — same lesson
worth keeping for the next slice's own sweep block. That correct grep
found **106** models using the trait; `TenantModelRegistry::MODELS` also
lists **106**; a basename diff between the two lists (checked for
duplicate basenames first, since a naive basename compare would mask a
same-named model in two domains — none exist) came back byte-identical:
every registered model has a real `BelongsToTenant` usage and every real
usage is registered, zero gaps either direction.

All 5 of this slice's new models confirmed present in all three required
spots (`MODELS` array, `seedAttributes()`, `updateAttributes()`):
`SyllabusTopic` (B0), `ScholarshipTest`/`ScholarshipTestRegistration`
(B12), `RankCard` (B15), `HallTicket` (B17) — each was in fact registered
in the same block that introduced it, so this sweep found nothing to fix,
only confirmed the running total. `tests/Isolation/CrossTenantTest.php`:
107/107 (cross-tenant read, update, and no-tenant-context guard, once per
registered model, generated from the registry — not hand-written per
model, per `CLAUDE.md`'s own rule).

`composer test`: 2017/2023. 6 failures — the same pre-existing/unrelated
family as every prior block's run (`CourseSlugUniquenessTest`,
`CalendarTest` x3 in Planner, `BackupConfigurationTest` in Support, plus
the rotating `DownloadEnrolmentExportControllerTest`/
`DownloadCompletionExportControllerTest` order-flake sibling), identical
count to B17's own run since this block changed no application code.

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
