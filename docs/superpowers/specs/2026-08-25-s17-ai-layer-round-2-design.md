# Design: S17 AI Layer, Round 2

## 1. Why this document exists

Locks scope and architecture for S17 before any block is built, same role
S9-S16's own design docs played. Full block-by-block build log lives in the
companion `2026-08-25-s17-ai-layer-round-2-plan.md`.

S17 is the ninth P2/P3-mixed backlog slice. Scope per `docs/ROADMAP.md`'s own
S17 row: `docs/FEATURES.md` §L9-L15 (AI Layer) — **7 items as counted there,
but L10 (auto-subtitles) already shipped in S14** (§2.3 below) and **L15 (AI
content-generation assist for instructors) is P3-tier and not named in the
roadmap's own S17 headline**, same "not named, not in scope yet" boundary
S16 drew the opposite way around (P3 items it *did* keep were all named in
its own headline). Real build scope is **5 items**.

## 2. Scope

### 2.1 In scope — 5 items

| # | Item | Source | Phase |
|---|---|---|---|
| L9 | AI course outline generation from a topic prompt | REQ-20 | P2 |
| L11 | Revision notes / lesson summaries from artifacts | NEW | P2 |
| L12 | Semantic search across course content | REQ-29, NEW | P2 |
| L13 | Weak-topic detection — quiz results × concept map | NEW | P2 |
| L14 | Photo-based doubt solving — student photographs a handwritten question → step-by-step solution | NEW, MKT | P2 |

All five are P2 — unlike S16, this is a clean phase-boundary slice, not a
mixed grab-bag.

### 2.2 Out of scope, and why (locked before any code, not discovered mid-build)

- **L10 (auto-subtitles) is not part of this slice — it already shipped in
  S14.** `docs/ROADMAP.md`'s own S14 row lists "auto-subtitles" explicitly.
  Re-verified before writing this design, not assumed from the roadmap
  range notation alone.
- **L15 (AI content-generation assist for instructors) is cut for now.** It
  is P3-tier in `docs/FEATURES.md` and, unlike every P3 item S16 kept
  in scope (I8/I9/I11/H11/H12/H13 — all named explicitly in S16's own
  headline), the S17 roadmap row's headline text ("course outline
  generator, semantic search, weak-topic detection, revision notes,
  photo-based doubt solving") never names L15 at all. Flagged as a future
  item once its own shape is clearer — "content-generation assist" is too
  broad to build against without its own scoping pass.
- **L9's outline generator produces structure only — module/lesson titles
  and one-line descriptions — never lesson bodies, video, or quiz
  questions.** Those already have dedicated, already-built pipelines (the
  S1-S3 ingestion spine for lesson content, L5's AI question-paper
  generation for quizzes). This feature solves the "blank course, blank
  page" problem, not full auto-authoring — building a second content
  generator here would duplicate L5's own teacher-review-gate machinery
  for no reason.
- **L11's revision notes are instructor-triggered, not auto-generated on
  every lesson at ingestion time.** Same "AI spend is a deliberate action,
  not an ambient cost" posture L6's teacher-approval gate already
  established for the ingestion pipeline — a course with 200 lessons
  should not silently burn `AiSpendCap` budget summarizing lessons nobody
  asked to have summarized.
- **L12's semantic search is scoped to already-ingested lesson video/audio
  transcripts (`lesson_artifact_chunks`) only.** It does not search PDFs,
  attachments, forum posts, or lesson Q&A (S13 items) — those are
  different content stores with no embeddings today. Extending embeddings
  to other content types is a separate, later item if requested.
- **L13's weak-topic matching is exact, normalized-string equality between
  `questions.concept` and entries in `lesson_artifacts.concepts` — no
  concept-graph or embedding-similarity matching.** A concept phrased
  differently by two authors (e.g. "Newton's 2nd Law" vs "Newton's Second
  Law of Motion") will not match. Documented as a known gap rather than
  silently "solved" with a fuzzy heuristic that could just as easily
  mis-match unrelated concepts. §4 decision 3.
- **L14's photo capture is a plain web file upload (`<input type=file
  accept=image/*>`), not live native camera capture.** `docs/FEATURES.md`
  W4 already reserves "camera capture for photo doubts" as its own S25
  mobile-shell item — building native capture here would preempt work
  already scoped for a slice that needs Capacitor infrastructure this app
  doesn't have yet.
- **L14 makes no accuracy guarantee on handwriting quality.** No
  preprocessing (deskew, contrast correction, multi-page stitching) beyond
  what the vision model itself handles — same "flagged, not solved"
  boundary S16 drew around OMR's flat-scan-only assumption.

### 2.3 Verified before writing this design (grepped/read, not assumed)

- **`app/Domains/AI` already owns the full ingestion + doubt-chat spine.**
  `LessonArtifact` (transcript, chunks, concepts, syllabus_map, difficulty,
  seed_questions — all jsonb) and `LessonArtifactChunk` (`text`, `embedding`
  — a real `pgvector` column via `Pgvector\Laravel\Vector`, with
  `HasNeighbors` already mixed in) exist today, populated by
  `ChunkTranscript` → `EmbedLessonChunks` → `ExtractLessonConcepts`.
  `DoubtChatService::ask()` is the working reference implementation for
  every RAG-shaped feature in this slice: `AiProvider::embed()` the query,
  `LessonArtifactChunk::query()->nearestNeighbors('embedding', $vector,
  Distance::Cosine)->limit(...)`, build context, `AiProvider::chat()`. L12
  reuses this pattern almost verbatim, scoped wider than one lesson.
- **`AiProvider` (`app/Integrations/AiProvider.php`) has exactly three
  methods today: `transcribe()`, `chat()` (with an optional
  `$responseSchema` for structured output — already used for concept
  extraction), and `embed()`. No vision/image input method exists.** L14
  needs a new capability, not a reuse of an existing one — §4 decision 2.
- **Every `AiProvider` call already goes through `SpendCapGuardedAiDriver`
  and `CircuitBreakerGuardedAiDriver`** (decorator chain over the real/fake
  driver). Any new call site — outline generation, revision notes, photo
  doubt — is automatically spend-capped and circuit-broken with zero new
  plumbing, the same reason `DoubtChatService` needed no bespoke spend
  check of its own.
- **`Question.concept` (nullable string) already exists** —
  `database/migrations/2026_08_11_100000_create_questions_table.php`, and
  was populated in practice by S16 B17's E2E test tagging for deterministic
  `QuizAttemptService::draw()` selection. L13 is the first feature to
  actually *use* this column for its stated purpose (grouping by concept),
  not just as a draw-determinism tag.
- **`LessonAccess` (`app/Domains/Content/Services/LessonAccess.php`) is
  confirmed, per `CLAUDE.md`, the one class every visibility check must go
  through** — the stream route, player, heartbeat, and `DoubtChatService`
  all already call it. L12's semantic search is a new consumer of the same
  rule (§4 decision 5) — critical, since a raw `nearestNeighbors()` query
  has no concept of enrollment and would otherwise leak chunk text across
  courses.
- **`LessonArtifact` already holds a direct `belongsTo(Lesson::class)` FK
  into the `Content` domain** — the AI domain has never been purely
  event-isolated from `Content`; it already relates to it by foreign key.
  This precedent is load-bearing for §4 decision 1 (L9's outline-approval
  step).
- **Existing Filament CRUD for `Course`/`CourseSection`/`Lesson`** already
  exists in `Content`'s resources per `CLAUDE.md`'s naming convention
  (`Domain/Filament/Resources/{PluralModelName}/{ModelName}Resource.php`).
  L9's approval action creates real rows via these models directly — no
  new authoring UI duplicated.

## 3. Architecture

### 3.1 Domain ownership — no new domain

Every item lands in the existing `AI` domain — nothing here is a new
business surface the way `Operations` (S15) or `Billing` (S11) were.

- **`AI`** — all five items: `CourseOutlineDraft` + generator service (L9),
  `LessonRevisionNote` + generator service (L11), `SemanticSearchService`
  (L12, no new table), `WeakTopicDetectionService` (L13, no new table),
  `PhotoDoubtQuery` + service (L14). Same domain that already owns
  `LessonArtifact`/`DoubtChatMessage`/`DoubtChatService` — every item here
  is AI-pipeline-adjacent, the domain's own existing business.
- **`Content`** — touched only by L9's approval step, which creates real
  `Course`/`CourseSection`/`Lesson` rows directly (§4 decision 1) — no new
  `Content` domain code, just new callers of its existing models.

### 3.2 New schema

```
-- AI: L9
course_outline_drafts (AI — L9)
  id, tenant_id, created_by_user_id, topic, target_level (nullable string),
  status (draft|approved|rejected), generated_outline (jsonb — array of
  {section_title, lessons: [{title, description}]}), created_course_id
  (nullable FK -> courses, set on approval), token_cost (jsonb nullable),
  timestamps

-- AI: L11
lesson_revision_notes (AI — L11)
  id, tenant_id, lesson_id (FK -> lessons), source_artifact_version (int —
  which LessonArtifact version these notes summarize, same "pin the
  version" contract LessonArtifact.version already uses for quizzes),
  status (pending|ready|failed), notes (jsonb nullable — key points array),
  token_cost (jsonb nullable), timestamps
  -- unique (lesson_id, source_artifact_version): regenerating notes for a
  -- newer artifact version is a new row, not an overwrite — mirrors
  -- LessonArtifact's own versioned-not-overwritten shape

-- AI: L14
photo_doubt_queries (AI — L14)
  id, tenant_id, user_id, course_id (nullable — context if asked from
  inside a course, null if asked standalone), image_disk_path,
  status (pending|processing|ready|failed), extracted_question (text
  nullable), solution (text nullable), token_cost (jsonb nullable),
  timestamps

-- Integrations: L14 (interface addition, not a table)
AiProvider::chatWithImage(array $messages, string $imageDiskPath): ChatResult
  -- new interface method; FakeAiDriver, SpendCapGuardedAiDriver,
  -- CircuitBreakerGuardedAiDriver, and the real driver each implement it
  -- (§4 decision 2)

-- L12, L13: no new schema — both are pure query/aggregation services over
-- existing tables (lesson_artifact_chunks; quiz_answers × questions ×
-- lesson_artifacts)
```

### 3.3 New `BelongsToTenant` models (all registered in `TenantModelRegistry`)

`CourseOutlineDraft`, `LessonRevisionNote`, `PhotoDoubtQuery` — **3 new
models**, narrower than S16 (7) since two of the five items (L12, L13) are
pure services with no new table.

## 4. Design decisions

1. **L9's approval step creates real `Content` records via direct Eloquent
   calls inside the AI domain's approval action, not an event.** The
   `LessonArtifact -> Lesson` FK already precedents the AI domain relating
   directly into `Content`'s models (§2.3). The operation is a synchronous,
   user-triggered click ("approve this outline"), not a domain reacting to
   another domain's state change — the kind of thing `CLAUDE.md`'s
   events-only rule targets is *service*-to-service calls (`LessonAccess`,
   `QuizAttemptService`), not a one-time Eloquent `create()` for records
   the acting user explicitly asked to create. No new `Content` service is
   called; only its models.
2. **Photo-doubt vision is a new, additive `AiProvider::chatWithImage()`
   method, not an overload of `chat()`.** Keeps every existing `chat()`
   call site (concept extraction, L9 outline generation, L11 revision
   notes, `DoubtChatService`) untouched. `FakeAiDriver` and both decorators
   (`SpendCapGuardedAiDriver`, `CircuitBreakerGuardedAiDriver`) each gain
   one new pass-through method — same "decorator chain, add one method"
   shape as adding a method to any already-proven interface, not a
   restructure.
3. **Weak-topic matching is exact, lowercased-string equality — no
   fuzzy/embedding matching.** A concept the matcher can't find is simply
   excluded from the weak-topic report rather than guessed at. Flagged as a
   known gap in §2.2, not silently patched with a heuristic that could
   mis-match two genuinely different concepts that happen to share words.
4. **Revision notes are instructor-triggered, per-lesson, not a background
   sweep over the whole course library.** Same AI-spend-is-deliberate
   posture as L6's ingestion-pipeline review gate (§2.2) — no silent
   `AiSpendCap` drain from a feature nobody asked to run yet.
5. **Semantic search filters every candidate chunk through
   `LessonAccess::isVisibleTo()` before it reaches the results list**,
   the same centralized gate the stream route/player/heartbeat/doubt-chat
   already use. `CLAUDE.md`'s own rule — one class answers "may this
   viewer see this lesson" — applies here exactly as much as it does to
   video playback; a bare `nearestNeighbors()` query has no concept of
   enrollment and would otherwise return chunk text from courses the
   searching student never enrolled in.
6. **Course outline generation returns structure only (section/lesson
   titles + one-line descriptions), never lesson bodies, video, or quiz
   content.** Avoids duplicating L5's already-built, already-reviewed
   AI-question-generation pipeline and the S1-S3 ingestion spine — this
   feature's whole job is turning a blank course into an editable skeleton.
7. **L14's upload is a standard web file input, not native camera
   capture.** `docs/FEATURES.md` W4 already reserves camera capture for
   S25's mobile shell; building it here would preempt Capacitor
   infrastructure this app doesn't have.
8. **L14 processing is async, same shape S16's `ProcessOmrSheetScan`
   established for slow AI/image work.** Upload creates a `pending`
   `PhotoDoubtQuery` row; a queued job calls `chatWithImage()` and fills in
   `extracted_question`/`solution`. Avoids blocking the uploading student's
   browser on a multi-second vision-model round trip.
9. **L11's notes are versioned by `source_artifact_version`, never
   overwritten in place** — same contract `LessonArtifact.version` already
   uses. A lesson re-ingested (new artifact version) gets a fresh notes row
   on next generation; the old notes stay readable against the old version
   rather than silently disappearing.

## 5. Testing strategy

- **Unit**: concept-name normalization (L13's exact-match rule — case,
  whitespace); outline JSON shape validation (L9).
- **Feature, per service**: `CourseOutlineGenerator`, `RevisionNotesGenerator`,
  `SemanticSearchService`, `WeakTopicDetectionService`, `PhotoDoubtService`
  — all against `FakeAiDriver`, same "no real AI calls in the test suite"
  posture `DoubtChatServiceTest` already established.
- **Visibility regression (critical, L12)**: a student never enrolled in
  course A must never see course A's chunk text in a semantic search
  result, even when the vector similarity score is high — mirrors the
  existing `GeofenceCheckIn` enrolment-check regression class S16 B17
  added.
- **Cross-tenant**: `TenantModelRegistry` gains 3 entries
  (`CourseOutlineDraft`, `LessonRevisionNote`, `PhotoDoubtQuery`);
  `CrossTenantTest` auto-generates isolation coverage for each.
- **Security baseline**: no `DB::raw`/`whereRaw`/`DB::statement` in any new
  `app/Domains/AI` file — watch for the same docblock-prose false-positive
  class that tripped B5/B13 (S16) and B13 (this slice's own precedent risk)
  when explaining *why* a raw query was avoided.
- **End-to-end (final block)**: one lifecycle test chaining outline
  generation → teacher approval → real `Course`/`Lesson` rows exist, and
  one chaining quiz attempts with tagged concepts → weak-topic report
  correctly excludes the concept the student got right.
