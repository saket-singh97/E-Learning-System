# Implementation Plan: S6 Assessments

Full design: `2026-08-11-s6-assessments-design.md`. This is the block-by-block
build log — update the PROGRESS table as each block lands, same as the
S1–S5 plans did.

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-11-s6-assessments-design.md and
docs/superpowers/specs/2026-08-11-s6-assessments-plan.md, then build
Block 0 of the S6 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | Domain groundwork — `app/Domains/Assessments/` scaffold, `questions`/`quizzes`/`quiz_attempts`/`quiz_answers` migrations + models, tenant registry | ✅ done |
| B1 | Question bank CRUD (I1) — manual authoring in `/teach`, MCQ/true-false/short-answer/numerical | ✅ done |
| B2 | AI question paper generation (L5) — `QuestionGenerator`, `seed_questions`-first then blueprint `chat()` fallback | ✅ done |
| B3 | Review/edit/approve gate (L6) — draft → approved workflow; a draft question is never drawable | ✅ done |
| B4 | Quiz assembly (I2) — `Quiz` anchored to a `quiz`-type `Lesson`, blueprint-driven per-attempt draw, non-repeat rule | ✅ done |
| B5 | Quiz runner UI — `QuizRunner` Livewire component, wired into the syllabus tree's `quiz` branch | ✅ done |
| B6 | Auto-grading (I4) + short-answer review queue (I5) | ✅ done |
| B7 | Time-limit auto-submit sweep (I3) — `AutoSubmitExpiredQuizAttempts`, same shape as `ExpirePendingEnrolments` | ✅ done |
| B8 | Quiz performance trend (M9), full test coverage, isolation registration, review pass, `PROJECT-STATUS.md` update | ✅ done |

**Test suite:** 356/357 passing after B8 (4 new — 1 in
`QuizAttemptServiceSubmitTest` (soft-deleted-question regression), 2 in
`QuizResourceTest` (delete-guard regression), 1 in `QuizRunnerTest` (M9) —
same 1 pre-existing `CourseSlugUniquenessTest` failure, unrelated, still out
of scope). **S6 — Assessments: COMPLETE, all 9 blocks (B0-B8).**

---

## Block notes (fill in as each block builds — deviations, gotchas, decisions made mid-build)

### B0 — Domain groundwork — DONE (2026-08-11)

- New domain folder `app/Domains/Assessments/Models/` only — `Services/`,
  `Filament/`, `Jobs/` aren't created empty; they land with the block that
  actually puts something in them (B1+), matching how S4/S5's own B0s
  didn't pre-scaffold folders ahead of need.
- Four migrations exactly per design doc §3.1
  (`2026_08_11_100000..100003_create_*_table.php`), `jsonb` columns matching
  the newer S4/S5 migration convention, plain `string()` status/type
  columns (not `enum()`) with PHP-side `public const` arrays enforcing
  values — matches every model built since S4, not `lesson_artifacts`'
  older `enum()` style. `questions` is the only one of the four with
  `softDeletes()` (a retired bank question stays referenced by historical
  `quiz_answers.question_id` rows); `quizzes`/`quiz_attempts`/`quiz_answers`
  don't need it for B0's scope. `quiz_answers` carries a
  `unique(['quiz_attempt_id', 'question_id'])` — one answer row per question
  per attempt, DB-enforced.
- Four models (`Question`, `Quiz`, `QuizAttempt`, `QuizAnswer`), each
  `BelongsToTenant`, `Question` also `SoftDeletes`. Cross-domain reads
  wired now (`Question::lesson()`, `Quiz::lesson()` → `Content\Lesson`) —
  same Assessments→Content precedent Batches→Content already established;
  no new pattern, just a new domain using it.
- Registered all four in `TenantModelRegistry::MODELS` **in this block**,
  with full `seedAttributes()`/`updateAttributes()` entries — confirmed
  this really is mandatory now, not deferrable: `CrossTenantTest` iterates
  `MODELS` and calls `seedAttributes()` for every entry, so registering the
  class without a seed entry throws immediately (`RuntimeException`), unlike
  S5's B1-B7 pattern of extending an *already-registered* model's existing
  entry later. `QuizAnswer`'s seed fixture nests
  Course→CourseSection→Lesson→Quiz→QuizAttempt plus a separate Question
  create — deep, but the same nesting depth `LessonArtifactChunk`'s own
  seed fixture already uses; not a new shape.
- `composer test`: 287/288 passing (4 new, all from `CrossTenantTest`'s
  generated suite — `CrossTenantTest` itself: 18/18), same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated. No dedicated B0 test file
  needed beyond the registry-generated isolation suite, same as S5 B0.

### B1 — Question bank CRUD (I1) — DONE (2026-08-11)

- New standalone `/teach` resource, `app/Domains/Assessments/Filament/
  Resources/Questions/` — a real top-level nav item (course-scoped via a
  `course_id` Select, not nested under `CourseResource` the way `Lessons`
  nests under `CourseSection`), matching the design doc's own file tree
  (`Resources/Questions/`, a plural noun folder, not a `RelationManager`).
  A question bank is browsed/filtered/searched across many rows per course,
  unlike the handful-per-section shape `Lessons` has — a dedicated list
  screen fits better than a relation manager tab. Registered by adding
  `Domains/Assessments/Filament/Resources` to `TeachPanelProvider`'s
  `discoverResources()` calls, alongside Tenancy and Content.
- `correct_answer` is one JSON column with four different shapes depending
  on `type` (design doc §3.1). The form exposes one *virtual* field per
  type (`correct_answer_mcq`, `_true_false`, `_short`,
  `_numerical_value`/`_tolerance` — none are real columns), composed into
  the real `correct_answer`/`options` columns in `mutateFormDataBeforeCreate`/
  `mutateFormDataBeforeSave`, and decomposed back out in
  `mutateFormDataBeforeFill` so editing an existing question shows its
  current answer instead of a blank field. Shared between `CreateQuestion`
  and `EditQuestion` via a `NormalizesQuestionFormData` trait rather than
  duplicated — same shape need in both places. Chose this over binding
  multiple type-visible widgets to the literal same `correct_answer` state
  path (which would avoid the compose/decompose step entirely) because
  there's no established precedent anywhere in this codebase for two
  components sharing one state path, and no way to browser-verify the
  Livewire/Alpine sync behaviour of that pattern from here — the
  virtual-field approach matches `EditLesson`'s already-proven
  `mutateFormDataBeforeSave` + `afterSave` shape instead.
- **Real bug caught by the test suite, not assumed away**: a purely-numeric
  MCQ option string (e.g. `"4"`, the plausible common case for this app's
  own JEE/NEET numerical-question audience) came back from the form as an
  `int`, not the original `string`, breaking the invariant that
  `correct_answer` must equal one of the strings in `options`. Root cause:
  `Select::options()` for the correct-answer picker builds
  `[option => option]` from the option list — PHP silently normalises a
  numeric-string array *key* to a real int, and Filament resolves the
  submitted state through that key. Fixed with an explicit `(string)` cast
  in the compose step. Caught because the test used a realistic numeric-MCQ
  fixture ("What is 2+2?", options `3/4/5`) rather than avoiding numbers —
  worth remembering for B4/B6's draw and auto-grade logic too, since a
  `numerical` question's own `correct_answer.value` needs the same
  type-consistency discipline (already float-cast there, see §3.1).
- `true_false` needs no options field in the form at all — `options` is
  set programmatically to `['True', 'False']` in the compose step,
  matching design's "options required only for mcq/true_false" note
  without asking the teacher to type "True"/"False" as free-text tags.
- New questions always start `status = 'draft'`, `source = 'manual'`,
  `created_by = auth()->id()` — set in `CreateQuestion` only. **Editing**
  an existing question does not touch `status` at all in this block — B1 is
  CRUD, not the review workflow; whether an edit to an *approved* question
  should force it back to `draft` is B3's call to make, not assumed here
  either way.
- `composer test`: 293/294 passing (6 new — `QuestionResourceTest`), same 1
  pre-existing `CourseSlugUniquenessTest` failure, unrelated.

### B2 — AI question paper generation (L5) — DONE (2026-08-11)

- `QuestionGenerator::fromSeedQuestions(Lesson)` reads
  `LessonArtifact::latestReadyFor($lesson->id)?->seed_questions ?? []` and
  promotes each item into a draft `Question` — free, no AI call, since
  extraction already happened during S3 ingestion. Confirmed (not assumed)
  it correctly no-ops for a lesson with no artifact at all, and separately
  for a lesson whose only artifact hasn't reached `ready` yet (both return
  an empty collection, no error) — two regression tests, matching the
  defensive shape S5 B7's video-duration null-check used.
- `QuestionGenerator::generate(Course, blueprint)` — **one `chat()` call per
  requested question, not one call for the whole blueprint.** Design doc
  §3.2 says reuse `seed_questions`' *shape*; it doesn't say reuse the
  literal `responseSchema` key, but `FakeAiDriver::fakeValueFor()` only
  contract-shapes the exact field name `'seed_questions'` — asking for a
  new field (e.g. `'questions'`, an array of N) would have needed either a
  new fake-driver case or a new `AiProvider` shape, both against design's
  own "no new AI plumbing" call (§2.3). Looping `chat(messages,
  responseSchema: ['seed_questions'])` once per line-count instead reuses
  `ExtractLessonConcepts`'s exact existing contract verbatim — genuinely no
  new plumbing, at the cost of one HTTP round-trip per question once a real
  vendor exists (acceptable at this app's current + near-term scale; not
  re-litigated here). Each call's prompt includes a `(variant N)` marker
  specifically so repeated calls in the same blueprint line don't hash to
  the exact same `FakeAiDriver` seed and produce N identical fake
  questions — regression-tested (`toHaveCount(3)` on `pluck('stem')->unique()`
  for a count-3 line).
- `correctAnswerFromSeed()` handles both a bare scalar answer (what
  `FakeAiDriver` always returns — its `seed_questions` fake hardcodes
  `type: 'mcq'` and a string answer regardless of what's asked for, so the
  `numerical` branch is never exercised by the fake driver end-to-end) and
  a structured `{value, tolerance}` answer a real vendor might return
  directly. Tested via a small in-test `AiProvider` double
  (`NumericalSeedDriver`) rather than skipped as "unreachable with the fake
  we have" — same idiom S3's `ThrowingChatDriver` established for
  exercising a path the standard fake can't reach.
- Both generation paths always write `status = 'draft'`, `source =
  'ai_generated'` — B3 owns the only path that ever flips `status`; this
  block doesn't validate or repair a mismatched AI answer (e.g. `answer`
  not equal to any string in `options`) either — that's what the human
  review gate exists for, not `QuestionGenerator`'s job.
- UI: one header action, "Generate with AI", on `ListQuestions` — not
  mentioned by name in B2's own one-line scope, but design doc §3.0 groups
  "AI-generate action" into the same `Resources/Questions/` folder
  comment, and a service nothing can trigger isn't a shippable slice of
  L5. Single form, `mode` toggles between "from a lesson's notes"
  (`fromSeedQuestions`) and "custom blueprint" (`generate`, a `Repeater` of
  concept/type/difficulty/count lines) — one entry point covering both
  `QuestionGenerator` methods rather than two separate actions.
- `composer test`: 303/304 passing (10 new — `QuestionGeneratorTest`,
  `GenerateQuestionsActionTest`), same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated.

### B3 — Review/edit/approve gate (L6) — DONE (2026-08-11)

- One new table row `Action::make('approve')` on `QuestionResource`
  (`ListQuestions`'s table, same screen as B1's CRUD — no separate "review
  inbox" page for P1), `visible()`-gated to `status === 'draft'`,
  `requiresConfirmation()`. Shape copied directly from
  `EnrolmentsRelationManager`'s `approve`/`reject` row actions (S4) — same
  "closure calls `$record->update([...])`, no dedicated service class"
  precedent, since there's no business logic here beyond the status flip
  (unlike enrolment approval, which needs an actor/authorization check via
  `BatchEnrolmentService` — a question's approve action only needs panel
  access, which Filament's own auth already gates). Deliberately did not
  add a manual "unapprove"/"send back to draft" button alongside it — out
  of what B3's one-line scope asks for; the only path back to draft that
  exists is the edit-reverts rule below.
- **Decided the open question B1 explicitly left open**: editing an
  *already-approved* question's content sends it back to `status =
  'draft'`, unconditionally — implemented in `EditQuestion::
  mutateFormDataBeforeSave()` (`if ($this->record->status === 'approved')
  { $data['status'] = 'draft'; }`), checked against `$this->record`'s
  pre-save state, not the incoming form data. Chose "any edit reverts,
  full stop" over trying to allowlist "safe" fields (e.g. `concept`) vs
  "unsafe" ones (`correct_answer`) — no allowlist to maintain, and errs
  toward re-review rather than trusting a diff. Updated the one B1 test
  this changed (`QuestionResourceTest`'s edit test) — it originally
  asserted an *approved* question keeps its status through an edit; that
  assertion was explicitly provisional pending B3's call (see B1's own
  note), now asserts against a *draft* fixture instead, and a new test in
  `QuestionApprovalTest` covers the revert case directly.
- **The gate itself — "a draft question is never drawable" — is not yet
  enforced by any query, because nothing draws questions yet.** B4 owns
  that query (`status = 'approved'` in the draw `WHERE`) and B3
  deliberately didn't reach into B4's scope to pre-build it; the CLAUDE.md
  `LessonAccess` lesson ("a gate applied at only one layer is not a gate")
  is a reminder for B4 to act on, not something B3 could satisfy in
  advance with no draw path to gate yet.
- `composer test`: 306/307 passing (3 new — `QuestionApprovalTest`; 1
  existing `QuestionResourceTest` case updated), same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated.

### B4 — Quiz assembly (I2) — DONE (2026-08-11)

- New standalone `/teach` resource, `Resources/Quizzes/` — `lesson_id`
  Select filtered to `Lesson::where('type', 'quiz')`, matching
  `BatchSessionsRelationManager`'s exact `live_session` precedent (S5 B1)
  **at the same depth**: the options list is UI-filtered only, no
  server-side re-validation that a submitted `lesson_id` actually has
  `type = 'quiz'`. Confirmed (not assumed) this is the real precedent by
  reading `BatchSessionsRelationManager` and its tests before building —
  S5 never added that server-side check either and nothing in this app's
  test suite currently exercises "submit a mismatched-type id past the UI
  filter," so matching it exactly is following precedent, not introducing
  a new gap. `blueprint` is a `Repeater` bound directly to the real
  `blueprint` json column (unlike B2's identically-shaped but *ephemeral*
  action-form Repeater) — same four sub-fields (concept/type/difficulty/
  count), `minItems(1)`. `time_limit_minutes` (1-180) and `max_attempts`
  (1-10) are plain `TextInput::numeric()` with `minValue`/`maxValue`,
  matching I3's stated ranges even though I3 itself is B7's block — the
  Quiz form is the only place those numbers are ever entered, so
  validating them here isn't scope creep into B7, just where the column
  they'll later be *read by* happens to be written.
- `QuizAttemptService::start(Quiz, User): QuizAttempt` — the "start/draw"
  half of the service design doc §3.0 describes (submit/auto-grade are
  B6's). Draws per blueprint line from `Question::where('course_id',
  $quiz->lesson->section->course_id)->where('status', 'approved')`
  (**this is the L6 gate finally getting enforced by a query**, not just
  hidden in the UI, closing the gap B3 explicitly left open for B4),
  filtered further by whichever of type/difficulty/concept the line
  specifies, excluding IDs already drawn earlier in the same blueprint (no
  duplicate question within one attempt) and preferring IDs the student's
  immediately-previous attempt on this quiz didn't use. Exactly matches
  this block's own plan note: when a line's approved pool is too small to
  both hit its `count` *and* avoid every previously-used id, the count
  wins and the repeat is allowed rather than silently returning fewer
  questions — regression-tested directly (a 1-question pool, second
  attempt, asserts the repeat happens) alongside the large-pool case
  (asserts the previous set really is avoided when there's room to).
- Added attempt-limit enforcement (`quiz.max_attempts`) inside `start()`,
  even though I2's own line doesn't ask for it and I3 (which does) is
  officially B7's block — B7's one-line scope only covers the *sweep*, no
  other block claims "refuse a Nth attempt past the limit," and leaving
  `max_attempts` a stored-but-unenforced column would repeat the exact
  "built but nothing reads it" gap this design doc's own §1 opened by
  naming (`seed_questions` before S6, `Lesson::TYPES` before S1). Counts
  *all* attempts regardless of status (an `in_progress` one already
  consumes a slot) — deliberately no separate "attempt already in
  progress" guard, since counting-toward-the-cap is self-limiting without
  needing one. New `QuizAttemptLimitExceededException` (`app/Exceptions/`,
  registered `abort(409, ...)` in `bootstrap/app.php`) — same shape as
  `BatchFullException`, same "no more capacity" semantics.
- `composer test`: 316/317 passing (10 new — `QuizResourceTest`,
  `QuizAttemptServiceTest`), same 1 pre-existing `CourseSlugUniquenessTest`
  failure, unrelated.

### B5 — Quiz runner UI — DONE (2026-08-11)

- **New shared gate, `Quiz::isVisibleTo(?User $viewer)`** — added because
  neither `ShowQuizController` nor `QuizAttemptService::start()` (B4) had
  one yet, and both need the same answer to "is this quiz reachable at
  all." Deliberately reads `Content\Lesson`/`Content\Course` directly
  rather than calling `Content\Services\LessonAccess` — cross-domain
  *model* reads are this codebase's established precedent (`Question`/
  `Quiz belongsTo Lesson`, S4 §3.0), cross-domain *service* calls are the
  one thing CLAUDE.md rules out ("domains talk through Events only").
  Documented on the method itself so a future reviewer doesn't "helpfully"
  rewire it to call `LessonAccess` and quietly break the domain boundary.
  Wired into `QuizAttemptService::start()` in this block (a real gap B4
  left open, closed now that something finally calls `start()`) via a new
  `QuizNotAvailableException` (404) — re-checked there and not just
  trusted from the page load, because `QuizRunner`'s own `startAttempt()`
  Livewire action fires through `/livewire/update`, which has no domain
  route constraint and so never passed through `ShowQuizController`'s gate
  at all (same class of gotcha CLAUDE.md's tenant-context section
  documents for a different gate). Had to fix B4's own
  `QuizAttemptServiceTest` fixture once this landed — its `Course::create`
  had no explicit `status`, defaulting to `draft`, which this new guard
  now correctly rejects; updated the fixture to `published` rather than
  weakening the guard.
- **Two separate gates on `ShowQuizController`**, same split
  `WatchLessonController` already uses for lessons: `isVisibleTo()` (404 —
  a draft quiz's existence stays hidden) then a role check,
  `hasAnyRole(['student','instructor','institute_admin'])` (403 — a
  *published* quiz's existence isn't secret, a guest is just not allowed
  to open it). Quizzes have no `is_free_preview` equivalent, so unlike
  `canStream()` there's no bypass in the second check — every viewer needs
  one of those three roles, full stop.
- New `App\Livewire\QuizRunner` (`app/Livewire/`), matching `LessonPlayer`'s
  placement precedent. **Ships a complete slice on its own scope, not a
  half-built version of the eventual full runner**: start an attempt
  (`QuizAttemptService::start()`, catching `QuizAttemptLimitExceededException`/
  `QuizNotAvailableException` into `addError('attempt', ...)` — same
  catch-and-`addError()` idiom `StudentPortal::login()` already
  established for a domain exception surfacing in a plain Livewire
  component, not a Filament one), then view the drawn questions with
  per-type inputs bound to `$responses` (component state only). **No
  submit action** — design doc's own file tree bundles "submit,
  auto-grade" as one `QuizAttemptService` capability, and the plan
  explicitly assigns both to B6 together; wiring a submit button now would
  mean either faking grading or shipping a button that silently does
  nothing, and CLAUDE.md rules out unfinished-looking half-implementations.
  A page refresh mid-attempt currently loses whatever's in `$responses` —
  known, not silently swept under the rug, and not a regression since
  nothing persisted responses before this block either.
- `SyllabusTree` gains a third per-type branch (`quiz`) alongside
  `live_session` and the generic fallback, reading `Quiz` directly (same
  posture it already has toward `Batches\Enrolment` via
  `myLiveBatchSession()` — this component sits outside every domain
  namespace, so it isn't bound by the inter-domain rule the same way a
  domain's own code is). A `quiz`-type lesson with no `Quiz` record yet,
  or one that exists but isn't visible, renders "Not yet available" —
  same posture as `live_session`'s "Not offered in your batch" state for
  an unmatched session.
- `composer test`: 328/329 passing (12 new — `ShowQuizControllerTest`,
  `QuizRunnerTest`, 2 new cases added to the existing `SyllabusTreeTest`),
  same 1 pre-existing `CourseSlugUniquenessTest` failure, unrelated.

### B6 — Auto-grading (I4) + review queue (I5) — DONE (2026-08-11)

- `QuizAttemptService::submit(QuizAttempt, array $responses)` — one
  `QuizAnswer` row per drawn question, answered or not (an unanswered
  objective question grades wrong rather than being skipped, so the review
  queue and the score sum both see a complete, gap-free set). Per-type
  grading: mcq/true_false exact string match against `correct_answer`
  (matching B1's own `(string)`-cast discipline so a numeric-string option
  like `"4"` compares correctly); numerical checks `abs(response - value)
  <= tolerance`, boundary **inclusive** (a response exactly `tolerance`
  away still counts correct — boundary-tested, not just the obviously-
  correct/obviously-wrong cases); short_answer lands `pending_review`
  **unless the response is blank**, in which case it auto-grades zero
  immediately — a blank answer is unambiguously wrong and doesn't need a
  human read, so it never reaches the queue at all.
- **Resolved a wording mismatch between this plan's own B6 note ("score
  stays null until every pending_review answer is reviewed") and the
  design doc's §3.3 ("score = sum of graded answers; stays partial until
  every pending_review answer is reviewed")** — implemented the design
  doc's literal version: `score` is the running sum of every currently-
  graded answer, visible immediately after submit (not held back to null
  while a short_answer question awaits review). A quiz with *only* a
  pending short-answer question and nothing graded yet does sum to `0.00`,
  not `null` — mathematically correct for "sum of zero graded items," and
  what a first draft of this block's own test asserted wrong before the
  test (not the code) was fixed.
- `QuizAttemptService::reviewAnswer(QuizAnswer, float $score, User
  $reviewer)` — the only path that ever grades a `pending_review` answer;
  recomputes and writes the parent `QuizAttempt.score` itself rather than
  leaving that to the caller, specifically because the plan's own note
  demanded verifying "this recompute happens on each review, not just at
  submit time" — regression-tested directly (score before review vs. after).
- New top-level `/teach` resource, `Resources/QuizAnswers/`
  (`QuizAnswerResource`, nav label "Review queue") — I5 names itself a
  *queue*, which reads across quizzes/students the same way I1's question
  bank does, so this follows B1's own "browsed across many rows → top-level
  resource, not nested" reasoning rather than B3's "no separate inbox, put
  it beside the existing screen" call (there is no existing "attempts"
  screen to put it beside — B4/B5 never built one, quizzes and attempts
  were never meant to have their own CRUD/browse UI beyond this one). No
  create/edit pages exist — `QuizAnswer` rows only ever come from
  `submit()`, matching `Resource::form()`'s own harmless default (returns
  an empty schema) rather than overriding it for a form nothing uses. One
  row action, `review` (`visible()`-gated to `status === 'pending_review'`,
  same shape as `EnrolmentsRelationManager`'s status-gated actions and S5
  B5's `markAttendance`'s "action with its own form" shape), a single
  `score` `TextInput` clamped `0-1` (one point per question, matching
  B4's `max_score = count(question_ids)`).
- `QuizRunner` (B5) gains `submitAttempt()` — re-derives the in_progress
  attempt from the DB by `(quiz_id, user_id, status)`, the same
  ownership-by-query posture `render()` already used, rather than trusting
  any client-held attempt id. After submit, `render()` also resolves the
  student's most recent non-`in_progress` attempt and shows a results view
  (score, per-question correct/incorrect + explanation, "pending review"
  for an unreviewed short answer) — the "start an attempt, see your drawn
  questions" UI B5 shipped and the "submit, see your result" UI this block
  adds are one continuous component, not two.
- `composer test`: 346/347 passing (19 new — `QuizAttemptServiceSubmitTest`,
  `QuizAttemptServiceReviewTest`, `QuizAnswerResourceTest`, new cases in
  `QuizRunnerTest`), same 1 pre-existing `CourseSlugUniquenessTest` failure,
  unrelated.

### B7 — Time-limit auto-submit sweep (I3) — DONE (2026-08-11)

- **Deviated from this block's own plan note on schedule cadence.**
  Written as "scheduled daily... third instance of the same shape" before
  building; built as `->everyFiveMinutes()` instead. `ExpirePendingEnrolments`/
  `RecordVideoCoverageAttendance` both have day-granularity domain
  semantics (an approval window measured in days, an end-of-day attendance
  roll-up) — a quiz's time limit is *minutes* (1-180 per I3), and B5/B6
  deliberately built no other expiry enforcement anywhere else (`QuizRunner`'s
  own `submitAttempt()` never checks `expires_at`, on purpose, trusting this
  sweep entirely). A daily cadence would have left an expired attempt
  answerable for up to 24 hours, which defeats I3's own point — the job's
  *shape* (unscoped, tenant-agnostic, `Schedule`-registered) is still the
  same third instance the plan called for; only the frequency changed, and
  for a stated reason, not silently.
- **The plan note's other assumption was wrong too, caught by a test, not
  assumed away**: "this job only updates existing quiz_attempts rows... so
  the B7-of-S5 wrinkle does not apply here" — false.
  `QuizAttemptService::submit()` (B6) also *creates* `QuizAnswer` rows, and
  `BelongsToTenant`'s `creating()` hook reads `CurrentTenant::id()`
  unconditionally, so calling `submit()` with nothing bound throws
  `MissingTenantContextException`. Unlike `RecordVideoCoverageAttendance`'s
  sidestep (`withoutEvents()` + explicit `tenant_id`), `submit()` is also
  the real student-facing path (`QuizRunner`) and shouldn't be
  special-cased for a sweep caller — instead, `CurrentTenant` is bound to
  each locked row's own `tenant_id` immediately before that row's own
  `submit()` call.
- **That fix immediately caused a second bug, also caught by a test**: a
  bound `CurrentTenant` left over from processing row N leaked into row
  N+1's own re-fetch-under-lock query, silently tenant-scoping a
  *different* tenant's row out of existence (`lockForUpdate()->first()`
  returned `null`, so the sweep quietly skipped it). Fixed by re-fetching
  the locked row via `QuizAttempt::withoutGlobalScope('tenant')` — the
  per-row tenant bind is only ever used for the `submit()` call itself, and
  every lookup that spans rows stays explicitly unscoped regardless of
  whatever tenant happened to be bound a moment earlier. Applied the same
  `withoutGlobalScope('tenant')` to the sweep's own initial SELECT too, so
  the job's correctness doesn't depend on the caller remembering to
  `CurrentTenant::clear()` first (the real scheduler never binds anything,
  but this makes that an independent guarantee, not an assumption a test
  has to keep enforcing).
- **"Grading whatever was answered" (design doc §3.3) turned out to be
  aspirational, not actual** — documented plainly rather than quietly
  building toward it or ignoring the gap. `QuizRunner` (B5/B6) never
  persists in-progress responses anywhere the backend can read; a response
  only reaches the database at the moment of an explicit `submit()` call.
  An attempt swept here therefore grades every question as unanswered,
  because that is genuinely all the server knows. A student who answered
  everything but never clicked Submit loses that work when the sweep
  closes their attempt. Closing this gap needs some form of incremental
  answer persistence (autosave) in `QuizRunner`, which is out of this
  block's one-line scope and explicitly not built here — flagged as a
  fast-follow candidate, not silently designed around.
- `AutoSubmitExpiredQuizAttempts` reuses `QuizAttemptService::submit()`
  verbatim (`responses: []`, `attemptStatus: 'auto_submitted'`) rather than
  duplicating the grading loop — required extending `submit()`'s signature
  with a third `$attemptStatus` parameter (default `'submitted'`, so
  `QuizRunner`'s existing call site and every B6 test needed no changes).
  **That extension itself shipped a variable-shadowing bug on the first
  attempt**: the new `$status` parameter name collided with the existing
  per-answer grading loop's own `[$isCorrect, $score, $status] =
  $this->grade(...)` destructure, so every attempt's final `status` column
  silently got overwritten with whatever the *last graded answer's own*
  status happened to be (`auto_graded`/`pending_review`) instead of
  `submitted`/`auto_submitted` — caught immediately by this block's own
  new tests *and* by three previously-green B6 tests failing on rerun.
  Fixed by renaming to `$attemptStatus` (parameter) and `$answerStatus`
  (loop-local), not by working around the collision.
- Concurrency: same `DB::transaction` + `lockForUpdate()` + re-check-under-
  lock shape as `ExpirePendingEnrolments`, regression-tested the same way
  (a `DB::listen()` hook races a raw `quiz_attempts` update into the exact
  window between the sweep's initial SELECT and its per-row write; the
  student's own in-flight submission wins, the sweep does not clobber it).
- `composer test`: 352/353 passing (7 new —
  `AutoSubmitExpiredQuizAttemptsTest`), same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated.

### B8 — Quiz performance trend, full test coverage, review pass — DONE (2026-08-11)

- M9 built into `QuizRunner` itself (no new component/page) — a fourth
  section alongside start/in-progress/last-result, listing every one of
  the student's own non-`in_progress` attempts on this quiz in chronological
  order (`Attempt 1 (date): score / max_score`), flagging which ones were
  `auto_submitted`. Raw list, no chart, no cross-quiz aggregation — exactly
  what the design doc's own M9 line asks for, nothing more.
- Full read-through review pass, same practice as S1 (×2), S2 (×1), S3 (×2),
  S4 (×2), S5 (×1 + two mid-build gaps) — 3 real issues found and fixed,
  detail in "Post-S6 review" below.
- `docs/PROJECT-STATUS.md` and `HANDOVER.md` updated to COMPLETE, matching
  S1-S5's own post-completion format.
- `composer test`: 356/357 passing (4 new regression tests for the review
  pass's own findings), same 1 pre-existing `CourseSlugUniquenessTest`
  failure, unrelated.

---

## Post-S6 review

Full read-through of every B0-B7 file, not just the block that touched it
last — same practice as every prior slice. 3 real issues found and fixed:

1. **A retired question silently under-scored an in-flight attempt.**
   `QuizAttemptService::submit()` skips a drawn question if it can no longer
   be found (`Question::whereIn('id', $attempt->question_ids)->get()`
   respects `SoftDeletes`'s own global scope) — correct, since there's
   nothing left to grade. But `max_score` was still the count fixed at
   `start()`'s draw time, never adjusted. An instructor retiring a bad
   question (a routine, ordinary action — `QuestionResource`'s
   `DeleteAction` has no guard against it) while a student had it mid-attempt
   would silently understate that student's achievable score with no
   explanation anywhere in the UI (e.g. a perfect run on the remaining
   questions showing 4/5 instead of the true 4/4). Fixed: `submit()` now
   sets `max_score` to the count of questions actually found and graded,
   not the original draw-time count. Regression-tested (soft-delete one of
   two drawn questions mid-attempt, assert `max_score` drops to 1 and the
   surviving answer's `QuizAnswer` row is the only one created).
2. **`QuizResource`'s plain `DeleteAction` could destroy a quiz's entire
   graded history in one click, with no way back.** Unlike `Question`
   (soft-deletes, by design, specifically because "a retired bank question
   stays referenced by historical quiz_answers.question_id rows" — B0's own
   words), `Quiz` was deliberately left without soft-deletes in B0 because
   at that point nothing referenced it yet. By B6/B7 that was no longer
   true: `quiz_attempts.quiz_id` is `cascadeOnDelete()`, so deleting a
   `Quiz` with real attempts silently destroys every one of those
   students' scores, answers, and review history in the same transaction —
   full data loss, no confirmation beyond Filament's generic "are you
   sure." Not hypothetical: the action was sitting unguarded on every quiz
   row regardless of whether it had ever been attempted. Fixed with a
   `visible()` guard — `DeleteAction` only shows once
   `$record->attempts()->doesntExist()` — rather than retrofitting
   soft-deletes onto the model this late (a bigger, riskier change for a
   gap a simple guard closes just as well). A quiz nobody has attempted
   yet can still be deleted freely. Regression-tested both ways (hidden
   with an attempt present, visible without one).
3. **A partially-credited short answer displayed as a flat "Correct" in
   the student's results view**, the same unqualified label a fully-correct
   MCQ gets. `reviewAnswer()`'s own data is accurate (`score` holds the
   real partial value, e.g. `0.5`), and the attempt's own total already
   summed it correctly — only the per-question label in `QuizRunner`'s
   results list collapsed a partial score into a binary correct/incorrect
   read, overstating a half-credit answer as equivalent to a full one.
   Fixed by showing the actual `score / 1` for every graded answer instead
   of a boolean label — strictly more informative, no less simple. Updated
   the one existing test this changed
   (`QuizRunnerTest`'s submit-and-see-the-result case).

One item checked and deliberately left alone: `QuizAnswerResource`'s
`question.stem` column reads through a `belongsTo` relation that respects
`Question`'s own `SoftDeletes` scope, so a retired question's stem renders
blank on a historical review-queue row instead of the original text. This
is a display nicety, not a correctness or access-control bug (the answer's
own `response`/`score`/`status` data is all still intact and correctly
shown) — not fixed here.

**What's not real yet, on purpose:** no real AI vendor for question
generation (`FakeAiDriver` only, same posture every prior slice shipped
with). Extended question types, proctoring, ranked test series, and OMR
scanning are P2/P3 in FEATURES.md, deliberately absent. `QuizRunner` has no
answer autosave — a response only reaches the database at the moment of an
explicit Submit click, so `AutoSubmitExpiredQuizAttempts`'s own sweep
(B7) grades an expired-but-never-submitted attempt as entirely blank; this
is documented plainly in that job's own class docblock as a known
limitation, not silently designed around. Instructor-facing score/coverage
dashboards beyond M9's own raw per-quiz list are out of scope, same "basic
only" call S5 made for attendance.

**Demo #6 target: 2026-10-31** — a teacher generates a question paper from
their own lecture notes (`seed_questions`/`chat()`), reviews and approves
it, publishes the quiz to a topic node in the syllabus tree; a student
opens it, attempts it under the time limit, and sees an auto-graded result
with explanations the moment they submit. Fully buildable and demoable
against what exists today.
