# Implementation Plan: S17 AI Layer, Round 2

Full design: `2026-08-25-s17-ai-layer-round-2-design.md`. This is the
block-by-block build log — update the PROGRESS table as each block lands,
same as every S1-S16 plan did. 14 blocks (B0-B13), finer-grained than the
5-item real build scope since outline generation, revision notes, and
photo-doubt each split into schema+service / UI parts, and weak-topic
detection splits three ways (service, student view, instructor view — two
materially different surfaces).

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-25-s17-ai-layer-round-2-design.md and
docs/superpowers/specs/2026-08-25-s17-ai-layer-round-2-plan.md, then build
Block 0 of the S17 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | `AiProvider::chatWithImage()` — new interface method, foundational for L14 | ✅ done |
| B1 | L9 pt.1 — `course_outline_drafts` schema + `CourseOutlineGenerator` service | ✅ done |
| B2 | L9 pt.2 — instructor review UI + approve action creating real draft `Course`/`Section`/`Lesson` rows | ✅ done |
| B3 | L11 pt.1 — `lesson_revision_notes` schema + `RevisionNotesGenerator` service | ✅ done |
| B4 | L11 pt.2 — instructor trigger action + student-facing revision notes view | ✅ done |
| B5 | L12 pt.1 — `SemanticSearchService` (`LessonAccess`-gated, tenant-scoped) | ✅ done |
| B6 | L12 pt.2 — search UI (student portal search bar + results) | ✅ done |
| B7 | L13 pt.1 — `WeakTopicDetectionService` (quiz answers × question concept × artifact concepts) | ✅ done |
| B8 | L13 pt.2 — student-facing weak-topics view | ✅ done |
| B9 | L13 pt.3 — instructor/class-wide weak-topic aggregate view | ✅ done |
| B10 | L14 pt.1 — `photo_doubt_queries` schema + `PhotoDoubtService` + queued vision job | ⬜ not started |
| B11 | L14 pt.2 — student-facing upload UI + result display (pending/processing/ready states) | ⬜ not started |
| B12 | `TenantModelRegistry` sweep — 3 new models registered | ⬜ not started |
| B13 | Full test coverage, review pass, `PROJECT-STATUS.md`/`HANDOVER.md`/`ROADMAP.md` update | ⬜ not started |

---

## Block detail (expand as each block builds; stubs below are the starting checklist)

### B0 — `AiProvider::chatWithImage()` — done

- Added `chatWithImage(array $messages, string $imageDiskPath): ChatResult`
  to `App\Integrations\AiProvider`.
- Implemented in `FakeAiDriver` (deterministic, hash-seeded off
  messages+imageDiskPath — same shape `chat()`/`embed()` already use),
  `SpendCapGuardedAiDriver` and `CircuitBreakerGuardedAiDriver` (both
  plain pass-through decorators). No real driver exists yet (confirmed —
  only `FakeAiDriver` is bound in `AppServiceProvider`), so no fourth
  implementation was needed.
- `tests/Feature/Support/CircuitBreakerGuardedAiDriverTest.php`'s inline
  anonymous `AiProvider` implementation also needed the new method (PHP
  fatals on an incomplete interface implementation) — added a
  throwing stub matching its existing three methods.
- Tests added to `FakeAiDriverTest.php`: canned response is deterministic
  for the same input, and differs for a different image path. No
  user-facing feature yet — this block only adds the capability B10/B11
  build on.
- `composer test`: 1519/1520 pass, only failure is the pre-existing
  unrelated `CourseSlugUniquenessTest` flake (tolerated per this plan's
  own B13 note).

### B1 — L9 pt.1: outline schema + generator service — done

- `course_outline_drafts` migration (see design §3.2) + `CourseOutlineDraft`
  model (AI domain, `BelongsToTenant`).
- `CourseOutlineGenerator::generate(string $topic, ?string $targetLevel,
  User $creator): CourseOutlineDraft` — calls `AiProvider::chat()` with
  `responseSchema: ['outline']` (structured-output mode, same pattern
  `ExtractLessonConcepts` already uses), requesting an array of
  `{section_title, lessons: [{title, description}]}`. Constructor-injects
  `AiProvider` (`DoubtChatService`'s own shape), not the `app()` helper.
- `FakeAiDriver::fakeValueFor()` gained an `'outline'` case (2 fake
  sections, contract-shaped) — the same "add a case when a real caller
  needs a structured shape" precedent `concepts`/`seed_questions` already
  set; no existing case could fake this nested shape.
- Registered `CourseOutlineDraft` in `TenantModelRegistry` now rather than
  deferring to B12 — CLAUDE.md's registry rule is a hard invariant enforced
  by a guard test in `CrossTenantTest.php` on every `composer test` run,
  not something a later block can safely leave failing in between; B12's
  sweep will simply reconfirm zero gaps, same outcome S16 B16 found.
- Tests: `CourseOutlineGeneratorTest.php` — well-formed draft shape
  (topic/status/target_level/generated_outline/token_cost), null
  `target_level` when omitted, and an `AiSpendCapExceededException`
  bubbling up uncaught from a real `LessonArtifact` spend-cap breach (no
  new spend-cap code — reuses the existing container-level decorator, same
  as `DoubtChatServiceTest`'s own equivalent case).
- `composer test`: 1523/1524 pass, only failure is the pre-existing
  unrelated `CourseSlugUniquenessTest` flake.

### B2 — L9 pt.2: review UI + approval → real Content records — done

- `CourseOutlineDraftResource` (AI domain, Teach panel, new
  `discoverResources()` line in `TeachPanelProvider` — new domain, no
  split-visibility concern like `Content`'s directory, so directory-wide
  discovery is safe). No `create` route: generation is a header
  Action on the list page (`ListCourseOutlineDrafts::getHeaderActions()`,
  topic + target_level fields), same "header Action with its own schema,
  no blank create form" shape L5's `ListQuestions::getHeaderActions()`
  ("Generate with AI") already established for `QuestionGenerator`.
- Edit page: `generated_outline` as a nested `Repeater` (sections →
  lessons, `section_title`/`title`/`description`), disabled once the
  draft leaves `status=draft`. Approve/Reject as table row actions
  (`CourseOutlineDraftResource::table()`) and mirrored as Edit-page header
  actions — same `Action::make('approve')->visible(status===draft)`
  shape `QuestionResource`'s own approve action already established.
- `CourseOutlineApprovalService::approve()`/`reject()` (AI domain) —
  approve creates `Course` (`status=draft`, `Content`'s own convention) +
  `CourseSection` + `Lesson` (`type=text`) rows directly inside a
  transaction, no `Content` service call (design §4 decision 1). The
  outline's one-line lesson descriptions are **not** copied into
  `Lesson.text_body` — doing so would blur "structure only" (design §4
  decision 6) into real lesson content; they stay readable on the draft
  row itself. Re-approving an already-decided draft (approved *or*
  rejected) is a **no-op**, not blocked — decided over throwing because the
  UI already hides the action once `status != draft`, so the only
  realistic trigger is a double-click race, and a no-op can never leave a
  duplicate `Course` behind.
- Tests (`CourseOutlineApprovalServiceTest.php`): approval produces the
  exact section/lesson count and titles from the draft; reject creates
  nothing; re-approving an already-approved draft doesn't create a second
  `Course`; approving an already-rejected draft stays rejected.
- `composer test`: 1527/1528 pass, only failure is the pre-existing
  unrelated `CourseSlugUniquenessTest` flake. (A second flake,
  `BackupConfigurationTest`, surfaced mid-block from stray test-artifact
  files — OMR scans/exports/id-cards — accumulated in
  `storage/app/private` across several consecutive full-suite runs earlier
  today, pushing the backup zip over its 1MB test threshold; unrelated to
  this block's code. Cleaned with the user's OK, confirmed gone on rerun.)

### B3 — L11 pt.1: revision-notes schema + generator — done

- `lesson_revision_notes` migration (design §3.2: `source_artifact_version`,
  `status` pending/ready/failed, `notes` jsonb, `token_cost` jsonb, unique
  on `[lesson_id, source_artifact_version]`) + `LessonRevisionNote` model
  (AI domain, `BelongsToTenant`, own `latestReadyFor()` mirroring
  `LessonArtifact`'s).
- `RevisionNotesGenerator::generateFor(Lesson $lesson): LessonRevisionNote`
  — reads `LessonArtifact::latestReadyFor($lesson->id)`, calls
  `AiProvider::chat(responseSchema: ['notes'])` to summarize
  `transcript`/`concepts` into a key-points array, writes a `status=ready`
  row pinned to that artifact's `version`.
- **No-ready-artifact guard throws `LessonArtifactNotReadyException`
  (new, 409) rather than writing a placeholder row** — deviates from a
  literal reading of "guard it the way `DoubtChatService::ask()` does"
  (which writes a placeholder chat message): `source_artifact_version` is
  the column the table's own unique constraint is built around, so a
  placeholder row would need a fake version number, colliding with the
  unique index on a second call, or `NULL`, breaking the "pin the version"
  contract design §3.2 sets up on purpose. A clean, catchable exception
  is the actual "refuses gracefully" this case needs — the method's own
  declared return type is non-nullable `LessonRevisionNote`, never `null`.
- `FakeAiDriver::fakeValueFor()` gained a `'notes'` case (3 fake key-point
  strings) — same "add a case when a real caller needs a structured
  shape" precedent as B1's own `'outline'` case.
- Registered `LessonRevisionNote` in `TenantModelRegistry` immediately
  (same B1 precedent — the registry guard test runs on every
  `composer test`, not deferrable to B12).
- Tests (`RevisionNotesGeneratorTest.php`): generates notes pinned to the
  ready artifact's version; throws cleanly (no row written) when no
  artifact is ready yet, and also when the only artifact is still
  `processing`; regenerating after a newer artifact version creates a
  second row rather than overwriting the first, and the first row's own
  status stays untouched.
- `composer test`: 1532/1533 pass, only failure is the pre-existing
  unrelated `CourseSlugUniquenessTest` flake.

### B4 — L11 pt.2: trigger action + student view — done

- Instructor-facing "Generate revision notes" row action on
  `LessonsRelationManager` (same table `viewAiContent`/`retryIngestion`
  already live on) — visible only once `LessonArtifact::latestReadyFor()`
  exists, so it's never enabled before a lesson finishes ingesting.
- Student-facing `App\Domains\AI\Livewire\LessonRevisionNotes` component
  (same "AI-domain component embedded on Content's watch page" placement
  `DoubtChat` already established), registered as `ai.lesson-revision-notes`
  and embedded on `resources/views/content/watch.blade.php`'s existing
  video/live_session branch — **reused the existing embed point**, no new
  host route needed (unlike `GeofenceCheckIn`, which had no page to embed
  on at all).
- Gated through `LessonAccess::isVisibleTo()` directly inside the
  component's own `mount()` (`abort_unless(...)`) — not left to the host
  page's controller alone, since `Livewire::test()` exercises the
  component in isolation (CLAUDE.md's own warning about this exact gap).
- **Test-writing gotcha hit and fixed**: `Livewire::test()`'s initial mount
  runs through a real (temporary) route via `RequestBroker::
  temporarilyDisableExceptionHandlingAndMiddleware()`, which disables
  exception handling for everything *except* `HttpException`/
  `AuthorizationException` — so `abort_unless(..., 404)` surfaces as a
  normal 404 response (`->assertNotFound()`), not a thrown PHP exception
  the way a plain service-method gate (`DoubtChatService::ask()`,
  `abortStatus()` in `DoubtChatTest.php`) does. First draft of this
  block's tests assumed the latter and silently passed with no gate ever
  exercised; caught by chasing down why two tests reported "no exception
  thrown."
- Tests (`LessonRevisionNotesTest.php`): empty state before generation;
  generated notes render once ready; a non-staff viewer on a draft-course
  lesson gets 404 with the notes never appearing in the response; a guest
  on a future-scheduled lesson gets 404; staff can preview notes on their
  own tenant's unreleased lesson.
- `composer test`: 1537/1538 pass, only failure is the pre-existing
  unrelated `CourseSlugUniquenessTest` flake. (Two more storage-debris
  flakes — `DownloadCompletionExportControllerTest`/
  `DownloadEnrolmentExportControllerTest`, same root cause as B2's
  `BackupConfigurationTest` — surfaced mid-block from repeated full-suite
  runs; cleaned `storage/app/private` again, same already-approved
  cleanup as B2, confirmed gone on rerun. A third, one-off Postgres
  connection-timeout error on an unrelated Batches test also appeared on
  one run and did not recur — transient, not investigated further.)

### B5 — L12 pt.1: semantic search service — done

- `SemanticSearchService::search(string $query, User $viewer, int
  $tenantId): Collection` — embeds the query, `nearestNeighbors()` across
  `LessonArtifactChunk` explicitly scoped to `$tenantId` (belt-and-suspenders
  alongside `BelongsToTenant`'s own ambient scope), restricted to chunks
  whose parent `LessonArtifact.status = 'ready'` (same "never read a
  non-ready artifact's content" contract `DoubtChatService::ask()` already
  enforces). Over-fetches a 50-candidate pool, **then** filters every
  candidate through `LessonAccess::isVisibleTo()` before taking the top 10
  (design §4 decision 5) — filtering before limiting, not after, so a
  viewer with plenty of real visible matches never sees an
  artificially-shrunk result set just because the top raw matches happened
  to be from lessons they can't see.
- No new schema (design §3.2 confirms L12 is a pure query service).
- Tests (`SemanticSearchServiceTest.php`): a chunk from a lesson the viewer
  can't see is excluded even when it's embedded with the *exact* query
  text (guaranteed cosine distance 0, the nearest possible match) — the
  load-bearing visibility-gate test; cross-tenant regression (same
  decoy-tenant shape `DoubtChatTest.php` already uses); chunks from a
  still-processing artifact are excluded; an empty/whitespace-only query
  short-circuits to an empty result with no `embed()` call.
- `composer test`: 1541/1542 pass, only failure is the pre-existing
  unrelated `CourseSlugUniquenessTest` flake.

### B6 — L12 pt.2: search UI — done

- `App\Domains\AI\Livewire\SemanticSearch` — query input debounced
  client-side (`wire:model.live.debounce.400ms`), `updatedQuery()` calls
  `SemanticSearchService::search()` and short-circuits (no service call at
  all) for an empty query or no authenticated viewer. Result links use
  `route('lessons.watch', [...]).'#t='.$chunk->start_s` — the same
  `{lesson}#{start_s}` timestamp-anchor shape `DoubtChatService`'s own
  citations already point at, as a real navigable URL here since a search
  result may point at a *different* lesson than whatever the viewer is
  currently on (unlike `DoubtChat`'s same-page seek-to dispatch).
- Wired into `resources/views/student/dashboard.blade.php` — the existing
  student dashboard host page — no new route/page invented.
- Tests (`SemanticSearchTest.php`): empty query calls `AiProvider::embed()`
  zero times (recording fake driver); a real query returns the visible
  chunk and its watch URL contains both the correct lesson id and the
  `#t={start_s}` fragment; a guest sees empty results rather than an error
  (the service's own `User` param is non-nullable, so the component must
  never call it without an authenticated viewer).
- `composer test`: 1544/1545 pass, only failure is the pre-existing
  unrelated `CourseSlugUniquenessTest` flake.

### B7 — L13 pt.1: weak-topic detection service — done

- `WeakTopicDetectionService::weakTopicsFor(User $student, int
  $courseId): Collection` — joins the student's `quiz_answers` (via
  `quiz_attempts.user_id`, only graded answers with `is_correct` not null)
  to `questions.concept` (`Question.course_id` used directly for the
  course filter — no need to go through `Quiz -> Lesson -> Section ->
  Course` since Question already carries its own `course_id`), groups by
  exact-normalized concept name, computes a correctness ratio per group.
  Below a **0.6 threshold** (placeholder round number, same
  "deliberately doesn't invent a business number" posture
  `AiSpendCap::CAPS_USD` already documents) a concept is reported weak,
  cross-referenced against `lesson_artifacts.concepts[].name` (exact,
  lowercased-string equality only, design §4 decision 3) to attach
  covering lesson(s) — an unmatched concept stays in the results with an
  empty `lessons` collection, never silently dropped.
- No new schema (design §3.2 confirms L13 is a pure query/aggregation
  service, same as L12/B5).
- Tests (`WeakTopicDetectionServiceTest.php`): a concept answered
  correctly (ratio ≥ threshold) is excluded; a weak concept with a
  matching artifact returns its lesson, matched despite extra
  whitespace/casing in the source data; a weak concept with no matching
  artifact is still reported with an empty `lessons` collection; a
  classmate's answers (same tenant) and a different tenant's answers
  entirely never leak into the student's own report.
- Build-time fix: `Quiz::create()` in the new fixtures needed
  `blueprint`/`time_limit_minutes`/`max_attempts` explicitly (both NOT
  NULL on `quizzes` — missed on the first pass, caught immediately by the
  targeted test run).
- `composer test`: 1548/1549 pass, only failure is the pre-existing
  unrelated `CourseSlugUniquenessTest` flake.

### B8 — L13 pt.2: student weak-topics view — done

- `App\Domains\AI\Livewire\WeakTopics`, embedded on the existing per-course
  `content.syllabus` page — decided this over a dashboard widget since the
  service is course-scoped (`weakTopicsFor(User, int $courseId)`) and the
  syllabus page is the natural already-gated, course-scoped surface (same
  reasoning that put `SyllabusTree`/`CourseReviews` there); no new page.
- Privacy posture matches `TestSeriesResults` (own row only, never the
  roster) — but structurally guaranteed here, not just convention:
  `WeakTopicDetectionService` already scopes every query to
  `auth()->user()->id`, so the component has no classmate data to leak
  even if it wanted to.
- Tests (`WeakTopicsTest.php`): shows the viewer's own weak concept and its
  linked lesson; a classmate's differently-named weak concept (same
  course, same tenant) never appears; a guest sees a login prompt instead
  of an error.
- `composer test`: 1551/1552 pass, only failure is the pre-existing
  unrelated `CourseSlugUniquenessTest` flake. (Same storage-debris
  `BackupConfigurationTest` flake as B2/B4 surfaced on the first run —
  cleaned `storage/app/private` again, confirmed gone on rerun.)

### B9 — L13 pt.3: instructor class-wide aggregate view — done

- `WeakTopicDetectionService::classWideWeakTopicsFor(Batch $batch):
  Collection` — reuses `weakTopicsFor()` per actively-enrolled student
  (`Enrolment.status = 'active'`), counts distinct weak students per
  concept, sorted desc. Reads `Batches`' `Enrolment`/`Batch` models
  directly — cross-domain *model* reads are established precedent
  (`Quiz::isVisibleTo()` already does the same into `Content`), not the
  cross-domain *service* calls CLAUDE.md's events-only rule targets.
- `App\Filament\Teach\Widgets\BatchWeakTopicsWidget` (`StatsOverviewWidget`
  — same base class every existing widget in this codebase already uses,
  no new custom-view widget mechanism invented), placed via
  `EditBatch::getFooterWidgets()`. Filament's own
  `InteractsWithRecord::getWidgetData()` already hands every footer widget
  `['record' => $batch]` for free — confirmed by reading Filament core
  rather than assumed. Top 5 weak concepts as stat tiles
  (`"N/M students weak"`); an empty aggregate renders one "None yet" tile
  instead of a blank widget.
- Aggregate only — no per-student breakdown here, that's B8's `WeakTopics`
  component and only the student themself sees it.
- Tests (appended to `WeakTopicDetectionServiceTest.php`): counts are
  correct across 3 active students (2 weak on the same concept, 1 not);
  a student with a weak concept but only *waitlisted* (not active)
  correctly excluded from both the count and `total_students`; an empty
  batch (no attempts, or zero active enrolments) returns an empty
  collection, not an error.
- Build-time fix: `Enrolment::create()` needed `requested_at` explicitly
  (NOT NULL on `enrolments`) — caught by the targeted run before it
  reached the full suite.
- `composer test`: 1554/1555 pass, only failure is the pre-existing
  unrelated `CourseSlugUniquenessTest` flake.

### B10 — L14 pt.1: photo-doubt schema + async service — not started

- `photo_doubt_queries` migration (see design §3.2) + `PhotoDoubtQuery`
  model.
- `PhotoDoubtService::submit(UploadedFile $image, User $student, ?int
  $courseId): PhotoDoubtQuery` — validates image (format/size cap), stores
  to disk, creates a `pending` row, dispatches a queued job.
- `ProcessPhotoDoubtQuery` job — calls `AiProvider::chatWithImage()`
  (B0), fills `extracted_question`/`solution`, sets `status = ready` (or
  `failed` on error) — same async shape `ProcessOmrSheetScan` (S16)
  established (design §4 decision 8).
- Tests: submit creates a pending row and dispatches the job; the job
  transitions pending → ready with a solution from the fake driver; a
  driver failure transitions to `failed`, not an uncaught exception.

### B11 — L14 pt.2: upload UI + result display — not started

- Student-facing upload component: plain file input (design §4 decision
  7 — no native camera capture), shows pending/processing/ready states,
  polls or re-renders on completion (reuse whatever polling shape an
  existing async-result view in this app already uses, if one exists —
  check before inventing a new one).
- Tests: a student sees only their own queries, never another student's;
  the UI reflects each of the three terminal-ish states correctly.

### B12 — `TenantModelRegistry` sweep — not started

- Register `CourseOutlineDraft`, `LessonRevisionNote`, `PhotoDoubtQuery` in
  `TenantModelRegistry::MODELS` with `seedAttributes()`/`updateAttributes()`
  entries. `CrossTenantTest` picks up the generated isolation suite
  automatically — no hand-written per-model isolation tests.
- Grep sweep for any `BelongsToTenant` usage not yet in the registry
  (same reverse-check shape S16 B16 ran).

### B13 — Full test coverage, review pass — not started

- Lifecycle E2E test(s): outline generation → approval → real `Course`
  exists with correct structure; quiz attempts with tagged concepts →
  weak-topic report correctly excludes the concept answered correctly.
- Review pass across every B0-B12 file for real bugs (not just a green
  suite) — same discipline every prior slice's own final block applied.
  Particular attention to the semantic-search visibility gate (§B5) and
  the photo-doubt async failure path (§B10), the two spots most likely to
  hide a real bug the same way S16's `GeofenceCheckIn` missing-enrolment-
  check hid until B17's own review pass.
- `composer test` full suite green (only the pre-existing unrelated
  `CourseSlugUniquenessTest` failure tolerated).
- Update `HANDOVER.md` (S17 complete entry), `docs/PROJECT-STATUS.md` (new
  S17 section), `docs/ROADMAP.md` (S17 → Done, shipped count 16→17).
