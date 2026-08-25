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
| B4 | L11 pt.2 — instructor trigger action + student-facing revision notes view | ⬜ not started |
| B5 | L12 pt.1 — `SemanticSearchService` (`LessonAccess`-gated, tenant-scoped) | ⬜ not started |
| B6 | L12 pt.2 — search UI (student portal search bar + results) | ⬜ not started |
| B7 | L13 pt.1 — `WeakTopicDetectionService` (quiz answers × question concept × artifact concepts) | ⬜ not started |
| B8 | L13 pt.2 — student-facing weak-topics view | ⬜ not started |
| B9 | L13 pt.3 — instructor/class-wide weak-topic aggregate view | ⬜ not started |
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

### B4 — L11 pt.2: trigger action + student view — not started

- Instructor-facing "Generate revision notes" action on the lesson (button,
  not automatic — design §4 decision 4).
- Student-facing view: reuse `LessonPlayer`'s existing embed point or add a
  small host route, same "no existing embed point → add a small host
  route" shape S16 B3's `GeofenceCheckIn` took. Gated through
  `LessonAccess` (must be visible to view notes — never expose notes for a
  lesson the student can't already see).
- Tests: only a viewer who can see the lesson can see its notes; a
  not-yet-generated lesson shows an appropriate empty state.

### B5 — L12 pt.1: semantic search service — not started

- `SemanticSearchService::search(string $query, User $viewer, int
  $tenantId): Collection` — embeds the query, `nearestNeighbors()` across
  `LessonArtifactChunk` scoped to the tenant, then filters every candidate
  through `LessonAccess::isVisibleTo()` before returning (design §4
  decision 5 — this is the load-bearing test in this block).
- Tests: a result set never includes a chunk from a lesson the viewer can't
  see, even when its similarity score is highest; results are scoped to
  the viewer's own tenant only (cross-tenant regression, same shape every
  prior slice's own `CrossTenantTest`-adjacent service test uses).

### B6 — L12 pt.2: search UI — not started

- Livewire search component: query input, debounced, result list linking
  to `{lesson}#{start_s}` (same timestamp-anchor shape `DoubtChatService`
  already cites in its own answers).
- Wired into the student portal (existing embed point — confirm during
  build, don't invent a new page if one already fits).
- Tests: empty query returns nothing (no wasted `AiProvider::embed()`
  call); a real query returns ranked results a logged-in student can click
  through to the right lesson timestamp.

### B7 — L13 pt.1: weak-topic detection service — not started

- `WeakTopicDetectionService::weakTopicsFor(User $student, int
  $courseId): Collection` — joins the student's `quiz_answers` (via
  `quiz_attempts`) to `questions.concept`, computes a per-concept
  correctness ratio, cross-references against `lesson_artifacts.concepts`
  for the course (exact-normalized match only, design §4 decision 3) to
  attach "which lesson(s) cover this concept" for the weak ones.
- Tests: a concept the student answers correctly is excluded; a concept
  with no matching `lesson_artifacts` entry is still reported (just without
  a linked lesson) rather than silently dropped; cross-tenant isolation
  (student's own answers only, never another tenant's).

### B8 — L13 pt.2: student weak-topics view — not started

- Student-facing view (dashboard widget or standalone page — decide on
  build, follow whichever existing student-dashboard pattern is closest)
  showing weak concepts with a link to the lesson(s) that cover them.
- Tests: shows only the viewer's own weak topics, never a classmate's (same
  privacy posture S16 B13's `TestSeriesResults` already established for
  rank/percentile).

### B9 — L13 pt.3: instructor class-wide aggregate view — not started

- Instructor/admin-facing view (Filament widget or resource page) showing,
  for a batch, which concepts the largest share of students are weak on —
  aggregate only, never a per-student breakdown on this view (that's B8's
  job, and only the student themself sees it).
- Tests: aggregate counts are correct across multiple students; a batch
  with no quiz attempts yet shows an empty state, not an error.

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
