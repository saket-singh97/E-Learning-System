# S3 AI Spine — Implementation Plan

Date: 2026-08-10
Source design: `2026-08-10-s3-ai-spine-design.md`
Target demo: 2026-09-19
Status: COMPLETE — all 8 blocks done, 137/137 tests passing

---

## PROGRESS — read this first, update it last

One session per block. At the end of a block: tick its row, commit, close
the session. The next session reads this table and knows exactly where to
start.

| # | Block | Status | Commit | Notes |
|---|---|---|---|---|
| 0 | Pre-S3 close-out — embedding model decision, pgvector extension, `RebindsTenant` gap | ☑ done | — | `text-embedding-3-small`, D=1536 (owner delegated pick). `pgvector/pgvector` 0.2.2 installed, extension migration ships with package. `RebindsTenantTest` already existed against `NoopTenantJob` fixture — satisfies item 4 as-is. |
| 1 | `AiProvider` interface + `FakeAiDriver` | ☑ done | — | 116/116 green. `DIMENSIONS = 1536` const pins to Block 0's D. |
| 2 | `lesson_artifacts` / `lesson_artifact_chunks` migrations + models + registry | ☑ done | — | 118/118 green. HNSW index via raw DDL (fluent Blueprint has no opclass support). `lesson_artifact_chunks` has zero timestamp columns, matching the pinned schema exactly. |
| 3 | `LessonVideoReady` event + ingestion job chain | ☑ done | — | 123/123 green. First real cross-domain Event; RebindsTenant closed for real. |
| 4 | `/teach` ingestion status UI — badge, retry, transcript/concepts viewer | ☑ done | — | 126/126 green. Retry goes through a new event (`LessonIngestionRetryRequested`), not a direct AI-domain call. |
| 5 | Doubt chat — model, service, Livewire component, player integration | ☑ done | — | 134/134 green. `Livewire::component()` manual alias needed — domain component, outside auto-discovery root. |
| 6 | Token-cost measurement rollup | ☑ done | — | 137/137 green. No new table — reads `lesson_artifacts.token_cost` directly, unlike bandwidth's own incrementally-written table. |
| 7 | Tests, registry, docs | ☑ done | — | 1 real bug found (chunk duplication on retry) + fixed + regression-tested. 137/137. `docs/PROJECT-STATUS.md` updated. |

Status values: `☐ not started` · `◐ in progress` · `☑ done`

**Session opener, copy this:**

```
Block N chalu karo — docs/superpowers/specs/2026-08-10-s3-ai-spine-plan.md
```

Nothing else. No `/context-restore`, no skills. This file carries the state.

**Session closer:** tick the row, write the commit hash in, one commit per
block with the message `feat(s3): block N — <name>`. Then close.

---

## Why Block 0 exists

S1 and S2 both learned the same lesson the hard way: starting a slice
without re-auditing what the previous slice left half-finished creates a
bug that ships mid-slice instead of getting caught at the boundary. S2's
Block 0 folded in three real S1 gaps found by re-auditing before writing
code. This plan does the same, and one of the three items below is a hard
blocker, not a nice-to-have:

1. **Embedding model decision (blocks Block 2's migration).** Design §2.4:
   `lesson_artifact_chunks.embedding` is `vector(D)`, and `D` is a one-way
   door — pgvector requires a fixed dimension, changing it later means
   re-embedding every chunk in the system. This needs the owner's vendor
   pick before the migration is written. `FakeAiDriver` works with either
   choice, so Blocks 0-1 don't have to wait on it, but Block 2 does.
2. **`RebindsTenant` job middleware, still untested.** Flagged in S1's
   2026-08-09 verification pass: "code exists, zero test coverage... no job
   in any `Domains/*/Jobs/` folder yet... fix later once the first real
   queued job exists." S3's four ingestion jobs are that first real job.
   Closing this gap is folded into Block 3, not treated as separate S1
   cleanup, since it's the same code path either way.
3. **`CREATE EXTENSION vector`, never enabled.** The `pgvector/pgvector:pg16`
   Docker image has carried the extension since S1 Block 1's
   `docker-compose.yml`; nothing has ever run `CREATE EXTENSION IF NOT
   EXISTS vector` against it because nothing needed a `vector` column until
   now.

None of these are new scope — they're S1 promises S3 is the first slice to
actually collect on.

---

## Scope decisions locked (2026-08-10 planning session)

- **`FakeAiDriver` only in S3, real vendor is a fast-follow.** Same call S2
  made for `VideoProvider`/`BunnyDriver`. No API key exists yet for any
  transcription or LLM vendor; `AiProvider`'s interface makes the swap
  additive once one is chosen.
- **S3 ships 2 of the eleven S1 §5.2 consumers, not all eleven.** Artifact
  viewer (transcript + concepts) and doubt chat are what the 2026-09-19 demo
  needs. The other nine — question paper generation, revision notes,
  semantic search, weak-topic detection, WhatsApp nudges, auto
  chapters/notes, daily practice problems, full ask-at-timestamp, post-class
  auto quiz — are S6 and S8's whole subject, not S3 scope creep waiting to
  happen.
- **No auto re-ingestion on lesson edits.** Only a manual retry action
  re-triggers the pipeline. S2 doesn't support replacing an existing
  lesson's video file yet (only initial upload), so "the video changed,
  re-ingest" isn't even reachable today — this is a non-decision, not a cut.
- **Doubt chat is single-lesson scoped, not course-wide.** The retrieval
  query in design §3.2 filters to the one lesson the student is viewing.
  Cross-lesson retrieval is the same `pgvector` infrastructure but a bigger
  relevance-ranking problem; out of scope per design §2.2.

---

## Order

Blocks 0-2 are strictly sequential (each is a hard dependency of the next).
Block 3 depends on 1+2. Blocks 4 and 5 both depend on 3 but not on each
other — could run in either order or in parallel across two sessions. Block
6 depends on 3 (reads `token_cost` that only exists once ingestion runs).
Block 7 is last, always.

```
0 → 1 → 2 → 3 ─┬─→ 4
               └─→ 5
        3 → 6
  (0,1,2,3,4,5,6) → 7
```

---

## Block 0 — Pre-S3 close-out ☑ done (2026-08-10)

**Depends on:** S1 + S2 complete (they are — 109/109 passing as of the
2026-08-10 review round, see `docs/PROJECT-STATUS.md`).

1. ☑ Embedding model decided: **`text-embedding-3-small`, D = 1536**. Owner
   delegated the pick ("do at your own, don't ask me") — chose the OpenAI
   default over self-hosted MiniLM for better semantic recall on doubt-chat
   retrieval; `FakeAiDriver` (Block 1) is unaffected either way, real vendor
   driver is still a fast-follow per the design doc's §2.2 scope table.
2. ☑ `composer require pgvector/pgvector` (v0.2.2 — this is the current
   maintained package; `ankane/pgvector` is the same author's abandoned
   predecessor that now points here). Ships `Pgvector\Laravel\*`: `Vector`
   cast, `HasNeighbors` trait, `Blueprint::vector()` / `typeVector` grammar
   macros, cosine/L2/inner-product distance macros.
3. ☑ Extension migration — **not hand-written**. The package's own
   `PgvectorServiceProvider` auto-registers
   `vendor/pgvector/pgvector/src/laravel/migrations/2022_08_03_000000_create_vector_extension.php`
   (`CREATE EXTENSION IF NOT EXISTS vector`) via `loadMigrationsFrom()` —
   confirmed via Laravel package auto-discovery, no `providers.php` edit
   needed. A hand-written duplicate was drafted then deleted once this was
   found; using the package's own migration keeps the extension version
   tied to the package version instead of two sources of truth.
4. ☑ `RebindsTenantTest` — already existed
   (`tests/Feature/Tenancy/RebindsTenantTest.php` +
   `tests/Fixtures/Jobs/NoopTenantJob.php`), predates this block. It already
   matches item 4's spec exactly (trivial fixture job, with/without
   `tenant_id`, asserts bind vs `MissingTenantContextException`) — no new
   test needed here. The 2026-08-09 "untested" note is stale; closes once a
   *real* domain job exists in Block 3, tracked there.

**Verify:** `docker compose exec postgres psql -U lms -d lms -c "\dx"` lists
`vector 0.8.6` (supports HNSW, confirmed for Block 2's index choice).
`composer test` → 109/109 still green, zero regressions. Embedding decision
recorded above.

---

## Block 1 — `AiProvider` + `FakeAiDriver` ☑ done (2026-08-10)

**Depends on:** nothing (parallel-safe with Block 0 except the
`pgvector-laravel` package install, which either block can do first).

1. ☑ `app/Integrations/AiProvider.php` — interface: `transcribe(string
   $diskPath): TranscriptResult`, `chat(array $messages, ?array
   $responseSchema = null): ChatResult`, `embed(string $text): array`.
2. ☑ Two value objects next to the interface (flat in `app/Integrations/`,
   same level as `AiProvider.php`, not a subfolder): `TranscriptResult`
   (`text` + `segments` — each `['start_s','end_s','text']`, same shape
   `ChunkTranscript` will split on in Block 3) and `ChatResult` (`text` when
   no schema given, `structured` array when `$responseSchema` was passed).
3. ☑ `app/Integrations/Drivers/FakeAiDriver.php` — deterministic via
   `crc32()`-seeded lookups, no global PRNG state touched (`mt_srand` was
   considered and rejected — it would mutate process-wide random state and
   risk flaking unrelated tests/factories). Same file → same transcript,
   same messages+schema → same structured output, same text → same
   embedding. `chat()`'s `$responseSchema` contract, since the design doc
   didn't pin one: a flat array of field names (or `key => description`),
   one fake scalar per key — `_s`/`cost`/`count`-suffixed keys get an int,
   everything else a fake sentence. Block 3's `ExtractLessonConcepts` is the
   first real caller and can widen this if the concept-extraction schema
   needs more than scalars.
4. ☑ Bound `AiProvider` → `FakeAiDriver` in `AppServiceProvider::register()`,
   same line pattern as `VideoProvider` → `FakeVideoDriver`.

**Verify:** `tests/Feature/Integrations/FakeAiDriverTest.php`, 7 cases —
container resolution, transcript determinism + variation, chat text vs.
structured mode + determinism, `embed()` returns exactly `FakeAiDriver::
DIMENSIONS` (1536) floats, stable across two calls, varies with input.
`composer test` → 116/116 (109 + 7), zero regressions.

---

## Block 2 — `lesson_artifacts` schema ☑ done (2026-08-10)

**Depends on:** Block 0 (extension + `D` decided).

1. ☑ Migration `2026_08_10_000100_create_lesson_artifacts_table.php` — exactly
   as pinned in S1 §5: `version`, `source_asset_version`, `status` enum
   (pending/processing/ready/failed, default pending), `transcript` text,
   five `jsonb` columns (`chunks`, `concepts`, `syllabus_map`, `difficulty`,
   `seed_questions`, `model_route`, `token_cost` — all nullable, filled
   progressively by Block 3's job chain), `failure_reason` text nullable,
   `timestamps()`, `unique(lesson_id, version)`.
2. ☑ Migration `2026_08_10_000101_create_lesson_artifact_chunks_table.php` —
   `embedding vector(1536)` via the pgvector package's `Blueprint::vector()`
   macro, nullable (chunk rows exist before `EmbedLessonChunks` fills them).
   HNSW index added via raw `DB::statement('CREATE INDEX ... USING hnsw
   (embedding vector_cosine_ops)')` — the fluent `Blueprint::index()` has no
   way to attach an operator class, and pgvector's own README recommends
   raw DDL for this exact case. Cosine ops chosen to match the
   `HasNeighbors`/`Distance::Cosine` scope Block 5's doubt-chat retrieval
   will use. **No timestamp columns** on this table — the pinned S1 §5
   schema block lists none (unlike `lesson_artifacts`, which explicitly
   lists `created_at · updated_at`), so the model sets `$timestamps = false`
   to match exactly rather than assume Eloquent's default.
3. ☑ `App\Domains\AI\Models\LessonArtifact` (casts all 7 jsonb columns to
   `array`) and `LessonArtifactChunk` (casts `embedding` to pgvector's
   `Vector` class, `HasNeighbors` trait for the nearest-neighbor scope Block
   5 needs) — both `BelongsToTenant`, explicit `$fillable`.
4. ☑ Both added to `TenantModelRegistry::MODELS` with `seedAttributes()` /
   `updateAttributes()`.

**Verify:** `composer test` → 118/118 (116 + 2, one parametrized isolation
case per new model, +8 assertions = 4 per model as expected). `php artisan
migrate` ran both migrations clean against the dev DB. Isolation guard test
(registry-completeness scan) green — nothing missing.

---

## Block 3 — Ingestion pipeline ☑ done (2026-08-10)

**Depends on:** Blocks 1 + 2.

1. ☑ `App\Domains\Content\Events\LessonVideoReady` — fired at the end of
   `Lesson::attachUploadedVideo()`, right after the same `update()` call
   that sets `video_status = 'ready'` (the only place that transition
   happens today). Plain `{lessonId, tenantId}` payload.
2. ☑ `App\Domains\AI\Listeners\StartIngestion` (queued) — binds
   `CurrentTenant` itself first (`Tenant::findOrFail($event->tenantId)`,
   inline — **not** `RebindsTenant` middleware; that's scoped to the 4
   chained jobs per this plan's own wording, and `CallQueuedListener` has no
   `middleware()` hook the way `ShouldQueue` jobs do, so it couldn't share
   the fixture-tested mechanism anyway). Computes `version = max(existing)
   + 1`, creates the `lesson_artifacts` row at `status = pending`, dispatches
   the chain via `Bus::chain([...])->dispatch()`.
3. ☑ Job chain, each using `RebindsTenant` middleware, confirmed via
   `Illuminate\Queue\CallQueuedHandler::call()` source
   (`dispatchNextJobInChain()` only fires when `!$job->hasFailed() &&
   !$job->isReleased()`, so a caught-then-`$this->fail()`'d job halts the
   chain exactly like an uncaught one):
   - `TranscribeLesson` — `AiProvider::transcribe()`, writes `transcript` +
     `chunks` (jsonb, `[{idx,start_s,end_s,text}]` — the transcript's raw
     segments, indexed). This jsonb copy is the hand-off to the next job;
     no data rides on the job payload itself, every job only carries
     `lessonArtifactId` + `tenant_id` and re-reads the DB, the standard
     Laravel job-chain idiom.
   - `ChunkTranscript` — reads `lesson_artifacts.chunks` back and
     materializes one `LessonArtifactChunk` row per entry (the "separated
     so pgvector indexes cleanly" table from Block 2).
   - `ExtractLessonConcepts` — `AiProvider::chat()` with schema `['concepts',
     'syllabus_map', 'difficulty', 'seed_questions']`, writes those 4 plus
     `model_route` (`class_basename($ai)` per stage — generic, no
     `FakeAiDriver` import, stays swap-safe for the real driver) and
     `token_cost` (from `ChatResult::$usage`, added this block — see below).
   - `EmbedLessonChunks` — `AiProvider::embed()` per chunk row, sets
     `lesson_artifacts.status = 'ready'`, fires `LessonArtifactReady`.
4. ☑ `App\Domains\AI\Jobs\Concerns\HandlesIngestionFailure` trait, shared by
   all 4 jobs — `status = 'failed'` + `failure_reason` + fires
   `LessonArtifactFailed` + `$this->fail($e)` to halt the chain (point 3).
5. ☑ `RebindsTenantTest` extended with a case against `TranscribeLesson`
   itself (null-tenant path — middleware fails before `handle()`, so no
   `AiProvider`/DB dependency needed). The bound-tenant path is proven by
   the end-to-end pipeline test below running `TranscribeLesson` for real.

**Widened during this block (flagged as likely in Block 1's notes):**
`ChatResult` gained a 3rd field, `usage: {in, out, usd}` — real vendor chat
APIs always return token usage alongside the completion, and `token_cost`
needed a source that wasn't a schema-requested field. `FakeAiDriver::
fakeValueFor()` now returns S1 §5-contract-shaped fake data for the 4 known
concept-extraction keys (`concepts`/`syllabus_map`/`difficulty`/
`seed_questions` — arrays/objects, not the old generic string/int), falling
back to the original generic scalar behavior for any other key.

**Verify:** `composer test` → 123/123 (118 + 5: 4 in
`tests/Feature/AI/IngestionPipelineTest.php`, 1 in `RebindsTenantTest.php`).
End-to-end: firing `LessonVideoReady` against `FakeAiDriver` produces a
`ready` artifact with transcript, concepts, chunks, and 1536-dim embeddings
on every chunk. Forced failure (a `ThrowingChatDriver` test double that
throws mid-chain): `status = failed` with the exact exception message,
chunks from before the failure point exist, embeddings from after it don't,
`LessonArtifactFailed` fires, `EmbedLessonChunks` never runs. Two
`LessonVideoReady` firings for the same lesson produce versions 1 and 2,
both reaching `ready`, version 1 untouched by version 2's run — structural
proof of contract rule 3 (no consumer reads "latest ready" yet to prove the
serving side; that lands with Block 4/5).

---

## Block 4 — `/teach` ingestion status UI ☑ done (2026-08-10)

**Depends on:** Block 3.

1. ☑ Status badge (`ai_status`, pending/processing/ready/failed/none) — one
   shared column, `LessonResource::aiStatusColumn()`, used by both
   `LessonResource::table()` (the plan's literal target) and
   `LessonsRelationManager::table()` (the table teachers actually browse
   day-to-day, per CLAUDE.md's note that the relation manager is the real
   UI). Reads via 2 new static lookups on `LessonArtifact` itself —
   `latestFor()` (any status, for the badge) and `latestReadyFor()` (S1 §5.1
   contract rule 1, for content) — centralized there so Block 5's doubt chat
   reuses the same rule instead of a second ad hoc query.
2. ☑ Retry — **not** a direct call into the AI domain. A new event,
   `App\Domains\Content\Events\LessonIngestionRetryRequested`, fired from
   the Filament action; `App\Domains\AI\Listeners\RetryIngestion` (queued,
   binds tenant itself, same reasoning as `StartIngestion`) picks the
   lesson's latest *failed* row itself, resets it to `pending`, clears
   `failure_reason`, redispatches the same 4-job chain at the *same*
   `lesson_artifacts.id` — no new version. Kept symmetric with
   `LessonVideoReady` → `StartIngestion` rather than having Content's
   Filament layer reach into AI's `Bus::chain`/job classes directly.
   Visible on `LessonsRelationManager`'s row actions and `EditLesson`'s
   header actions, both gated on `status === 'failed'`.
3. ☑ `ViewLessonArtifact` (new `ViewRecord` page, route `artifact` on
   `LessonResource`) + `LessonResource::infolist()` — transcript and
   concepts pulled via `latestReadyFor()` only, so a `processing`/`failed`
   newer version never leaks partial content into the reader (tested
   directly: a `ready` v1 + `processing` v2 on the same lesson still shows
   v1's transcript). Linked from the relation manager's row actions
   ("AI content") and `EditLesson`'s header.

**Cross-domain reads, not services:** `LessonResource`/`LessonsRelationManager`/
`EditLesson` import `App\Domains\AI\Models\LessonArtifact` directly for these
lookups. Treated as the same "data access, not a service call" category the
S3 design doc already carved out for AI's jobs reading `Lesson` — CLAUDE.md's
events-only rule is about domains calling into each other's *business logic*,
not about read-only Eloquent queries on another domain's own model.

**Verify:** `composer test` → 126/126 (123 + 3, in
`tests/Feature/AI/IngestionStatusUiTest.php`). Badge reads `ready` right
after an upload completes (sync queue means the transient `pending` state
isn't independently observable in-test — end state is what's verified, same
caveat Block 3's tests already had). Viewer shows only latest-ready content.
Retry: action visible only while failed, invisible again once retried back
to `ready`, and the artifact keeps `version = 1` throughout (retried, never
re-versioned).

---

## Block 5 — Doubt chat ☑ done (2026-08-10)

**Depends on:** Block 3.

1. ☑ Migration + `App\Domains\AI\Models\DoubtChatMessage` — `id · tenant_id
   · lesson_id · user_id · question · answer · cited_chunk_idxs jsonb ·
   created_at`, no `updated_at` (append-only, same pattern as
   `VideoWatchEvent`: `const UPDATED_AT = null` + a migration with only
   `timestamp('created_at')->useCurrent()`). Registered in
   `TenantModelRegistry`.
2. ☑ `App\Domains\AI\Services\DoubtChatService::ask(Lesson $lesson, User
   $viewer, string $question): DoubtChatMessage` — `$viewer` is
   non-nullable on purpose: `doubt_chat_messages.user_id` is `NOT NULL`, so
   a guest literally cannot produce a row, and the only caller (the Livewire
   component) already checks `auth()->check()` before ever reaching this
   method. Gates through `LessonAccess::isVisibleTo()` +
   `::canStream()` — the same class `StreamLessonController` and
   `WatchLessonController` use, no inline check added. No `ready` artifact
   yet → returns a persisted "still processing" message (not an exception,
   matching `LessonArtifact::latestReadyFor()`'s null-safe posture).
   Otherwise: `AiProvider::embed()` the question →
   `LessonArtifactChunk::nearestNeighbors('embedding', $vector,
   Distance::Cosine)` (pgvector package's own scope, added to the model in
   Block 2) filtered to the lesson's latest-ready `lesson_artifact_id` →
   top 5 → RAG prompt with `[start_s-end_s]` citations → `AiProvider::chat()`
   → persisted with `cited_chunk_idxs`.
3. ☑ `App\Domains\AI\Livewire\DoubtChat`, view at
   `app/Domains/AI/Views/doubt-chat.blade.php` (auto-namespaced `ai::` by
   the existing domain-glob registration — no manual step). **The plan's
   `<livewire:ai::doubt-chat />` tag syntax doesn't actually resolve** —
   `::` is Blade's *view*-namespace separator, not a valid Livewire
   component-tag separator, and the class lives outside Livewire's default
   `App\Livewire` auto-discovery root regardless. Registered explicitly —
   `Livewire::component('ai.doubt-chat', DoubtChat::class)` in
   `AppServiceProvider::boot()` — and embedded as `<livewire:ai.doubt-chat
   :lesson="$lesson" />` on `resources/views/content/watch.blade.php`,
   alongside the existing `<livewire:lesson-player />`.
4. ☑ Citations: `cited_chunk_idxs` (the only field the pinned schema has —
   no `start_s`/`lesson_artifact_id` stored) resolved back to
   `start_s`/timestamps at render time against the lesson's *current*
   latest-ready artifact — correct for a live session, the schema's own
   minimalism is the tradeoff. Clicking a citation dispatches a
   `seek-to` browser event; `lesson-player.blade.php` gained one line —
   `x-on:seek-to.window="$refs.video.currentTime = $event.detail.seconds"`
   — on its existing root `x-data`. Same video element, no new player.

**Verify:** `composer test` → 134/134 (127 + 7, in
`tests/Feature/AI/DoubtChatTest.php`). The isolation test seeds 4 decoy
chunks (stale same-lesson version, cross-lesson, cross-tenant, plus the one
real target) with real embeddings (pgvector's `nearestNeighbors` scope
`whereNotNull`s the column — a chunk created without one is invisible to
retrieval regardless of scoping, caught once by a first red run) and a
`RecordingChatDriver` test double that captures the exact RAG prompt sent
to `AiProvider::chat()` — asserts the target text is present and all 3
decoy texts are absent, not just that *a* row came back. Gate reuse: 404 on
draft/future-scheduled (same as `LessonAccessGateTest`), 403 for an
authenticated `parent`-role viewer (outside `LessonAccess::STREAM_ROLES`,
the one realistic 403 doubt chat can actually reach — a true guest never
gets this far, the Livewire action no-ops before calling the service).

---

## Block 6 — Token-cost measurement ☑ done (2026-08-10)

**Depends on:** Block 3 (needs real `token_cost` data to roll up).

1. ☑ `App\Domains\AI\Services\TokenCostLedger::totalForDate()` — **not** a
   written rollup table like `BandwidthUsageDaily`. `token_cost` already
   exists per artifact (written once by `ExtractLessonConcepts`), so this is
   a pure read: fetch the day's `lesson_artifacts` rows, sum `in`/`out`/`usd`
   in PHP (no `DB::raw`/`whereRaw` — CLAUDE.md forbids it in `app/Domains/`,
   and jsonb-field `SUM()` would have needed one). No explicit tenant filter
   in the query, and that's deliberate, not an oversight: `BelongsToTenant`'s
   global scope already narrows to `CurrentTenant` when one is bound
   (`/admin`) and is a no-op when none is (`/super`'s bare host, per
   CLAUDE.md's own note that the platform host has no tenant by design) —
   so the same query is tenant-scoped or platform-wide purely by which panel
   called it, no `withoutGlobalScope` bypass written or needed.
2. ☑ `App\Filament\Widgets\TokenSpendWidget` — **one shared class**, not
   two near-duplicates, for exactly the reason above. Lives in
   `app/Filament/Widgets/` (cross-panel, like `Providers/Filament/` per
   CLAUDE.md — this isn't one domain's resource CRUD). Auto-discovered by
   `SuperPanelProvider` (its widget-discovery root is that same directory);
   `AdminPanelProvider`'s root is `Filament/Admin/Widgets` instead, so it's
   registered there explicitly in `->widgets([...])`. One `Stat`: "AI spend
   today" — plan asked for "a read-only figure," not a breakdown table.

**Verify:** `composer test` → 137/137 (134 + 3, in
`tests/Feature/AI/TokenCostLedgerTest.php`). Two lessons ingested for the
same tenant same day, against real `FakeAiDriver` token_cost output (not
hand-seeded rows) — the ledger's sum matches manually adding both artifacts'
`in`/`out`/`usd` exactly, `count = 2`. Tenant-scoping test proves both
sides of point 1: bound to tenant A sees only A's `count = 1`, bound to B
sees only B's, `CurrentTenant::clear()` (the `/super` case) sees both,
`count = 2`.

---

## Acceptance criteria

- [x] `lesson_artifacts` / `lesson_artifact_chunks` migrations match S1 §5
      exactly — no field added, removed, or renamed. (Block 2)
- [x] All three new models (`LessonArtifact`, `LessonArtifactChunk`,
      `DoubtChatMessage`) registered in `TenantModelRegistry`, isolation
      suite green for all three. (Blocks 2, 5)
- [x] `RebindsTenant` has real test coverage against a real queued job
      (closes the 2026-08-09 gap). (Blocks 0, 3)
- [x] Ingestion pipeline runs end-to-end against `FakeAiDriver`: upload →
      `pending` → `processing` → `ready`, chunks + embeddings populated.
      (Block 3)
- [x] Ingestion failure path sets `status = failed` with a reason, doesn't
      silently retry, and a teacher can manually retry it. (Blocks 3, 4 —
      retry's own idempotency bug found and fixed in Block 7, see Post-S3
      review below)
- [x] Re-ingestion respects contract rule 3: the previous `ready` version
      keeps serving reads until the new version reaches `ready`. (Block 3,
      structural — no consumer reads "latest ready" across a version change
      yet to exercise the serving side live, tracked in Block 3's own notes)
- [x] Doubt chat answers are retrieved only from the asking lesson's
      latest-ready artifact — cross-lesson and cross-tenant leakage both
      explicitly tested against, not just assumed from the `WHERE` clause.
      (Block 5)
- [x] Doubt chat, the artifact viewer, and every other lesson-scoped
      **student-facing** read go through `LessonAccess`, not an inline
      check. (Reworded from the original wording, which read "every other
      lesson-scoped read" — Block 4's teacher-facing artifact viewer lives
      in `/teach`, gated by Filament panel role middleware + `Lesson`'s own
      `BelongsToTenant` scope, the same access model every other
      `LessonResource` page already uses; `LessonAccess` specifically gates
      the *student/anonymous* watch path, which is what doubt chat is.
      Applying it to a teacher CRUD page would be the wrong gate, not a
      missing one.)
- [x] Token-cost ledger sums correctly per tenant per day. (Block 6)
- [x] Demo #3 script (design §6) runs against `FakeAiDriver` without a real
      AI vendor API key. (All 4 steps map to tested code paths — Blocks
      3-5.)
- [x] All 109 existing S1+S2 tests still pass — zero regressions, 137/137
      total. (The "S3 adds, never edits, Content-domain code except the one
      new `LessonVideoReady` event" half of this line is stale: Block 4
      explicitly required editing `LessonResource`/`LessonsRelationManager`/
      `EditLesson` to add the status badge/retry/viewer UI, which the plan's
      own Block 4 section asked for. Read charitably as "S3 doesn't change
      Content's own domain *logic* — tenant scoping, the video pipeline,
      `LessonAccess`'s rules" — which held: every edit outside `Lesson.php`'s
      one new event line was new UI surface reading AI-domain data, not a
      change to Content's existing behavior.)

## Testing plan

Same shape as S1/S2: the generated isolation suite for the three new
models, plus hand-written feature tests per block (listed under each
block's Verify step above), plus one end-to-end pipeline test and one
end-to-end doubt-chat test that exercise the whole chain rather than each
job in isolation. `composer test` must stay the single command that runs
all of it — no new test command introduced for S3.

## Rollback plan

Every S3 table is additive (`lesson_artifacts`, `lesson_artifact_chunks`,
`doubt_chat_messages`) — none of S1/S2's existing tables gain or lose a
column. If S3 needs to be rolled back mid-build, the migrations reverse
cleanly and `LessonVideoReady` (the one new S2-side event) can be removed
without touching `Lesson::video_status` itself, which S2 already owns and
uses independently of whether anything listens to its transition.

## Effort estimate

Eight blocks, comparable in size to S2's eight — Block 3 (the job chain) and
Block 5 (doubt chat + retrieval) are the two with real design surface area;
the rest are closer to S2 Block 1's "build the interface + fake driver"
shape. No block here carries S2 Block 3's browser-only-behavior caveat —
everything in S3 is server-side and Pest-testable, including the retrieval
correctness that matters most (which chunks come back for which lesson).

## Files reference

```
app/Integrations/AiProvider.php
app/Integrations/Drivers/FakeAiDriver.php
app/Domains/AI/Models/{LessonArtifact,LessonArtifactChunk,DoubtChatMessage}.php
app/Domains/AI/Services/{IngestionPipeline,DoubtChatService,TokenCostLedger}.php
app/Domains/AI/Jobs/{TranscribeLesson,ChunkTranscript,ExtractLessonConcepts,EmbedLessonChunks}.php
app/Domains/AI/Events/{LessonArtifactReady,LessonArtifactFailed}.php
app/Domains/AI/Listeners/StartIngestion.php
app/Domains/AI/Livewire/DoubtChat.php
app/Domains/AI/Views/doubt-chat.blade.php
app/Domains/Content/Events/LessonVideoReady.php   (new, S2's domain)
database/migrations/*_create_lesson_artifacts_table.php
database/migrations/*_create_lesson_artifact_chunks_table.php
database/migrations/*_create_doubt_chat_messages_table.php
database/migrations/*_enable_pgvector_extension.php
tests/Feature/AI/**
```

## Out of scope

Everything listed in the design doc's §2.2 table — real AI vendor drivers,
the other nine S1 §5.2 consumers, cost enforcement, cross-lesson doubt
chat, streaming chat responses, and auto re-ingestion on edit. None of these
block the 2026-09-19 demo.

## Definition of done

Matches design §7: all 8 blocks done and tested, isolation suite green
with the 3 new models, `RebindsTenant` gap closed, demo script runs against
`FakeAiDriver`, zero regression in the existing 109 tests.

---

## Post-S3 review (2026-08-10, Block 7)

Same pattern S1 (two rounds) and S2 (one round) both used: after all blocks
were green, probed the paths the per-block tests didn't reach rather than
trusting green alone. One real bug found and fixed.

### Chunk duplication on retry (real bug, fixed)

**`app/Domains/AI/Jobs/ChunkTranscript.php`**, `handle()` — materialized
`lesson_artifacts.chunks` (jsonb) into `lesson_artifact_chunks` rows with a
plain `foreach (...) { LessonArtifactChunk::create([...]); }`, no clearing
step first.

Block 4's retry action (`RetryIngestion` listener) redispatches the *entire*
4-job chain from `TranscribeLesson` again, at the same `lesson_artifacts.id`
— including `ChunkTranscript`. Any ingestion that failed *after* chunking
already succeeded (the exact scenario `IngestionStatusUiTest`'s retry test
already used — `ThrowingChatDriver` fails at `ExtractLessonConcepts`, one
stage past chunking) meant a retry left the first attempt's chunk rows in
place and inserted a second full set alongside them: every chunk for that
`lesson_artifact_id` duplicated. Consequences if unfixed: `EmbedLessonChunks`
embeds twice the rows for no reason, and — more seriously — doubt chat's
`nearestNeighbors` search (Block 5) would surface duplicate/near-duplicate
passages as separate top-k results, degrading answer quality for any lesson
that was ever retried.

The existing Block 4 retry test exercised the exact reproduction path but
never asserted on chunk *count*, only on final `status`/`version` — a green
test suite was hiding this the whole time, same lesson S1/S2's reviews kept
teaching: passing tests prove what they check, not what they don't.

**Fix:** `ChunkTranscript::handle()` now deletes any existing
`lesson_artifact_chunks` rows for the artifact before rebuilding them —
correct because chunking is fully idempotent (`TranscribeLesson` always
rewrites `lesson_artifacts.chunks` first in the same chain, deterministically,
so nothing downstream of the delete is lossy). **Regression test:**
`tests/Feature/AI/IngestionStatusUiTest.php`'s retry test now records the
chunk count after the first (failed) attempt and asserts the count is
unchanged, not doubled, after a successful retry.

### Checked, not changed

- **`StartIngestion`'s version-increment race** (`max(version) + 1` between
  two concurrent `LessonVideoReady` events for the same lesson) — the
  `unique(lesson_id, version)` DB constraint (Block 2) would throw loudly on
  a genuine race rather than silently corrupt data. Not the "silent" failure
  class this review round was hunting for; left as-is, same reasoning S2's
  review applied to `BandwidthUsageDaily::record()`'s own race.
- **`TranscribeLesson`'s `Lesson::findOrFail()` if the lesson gets
  soft-deleted mid-pipeline** (a real gap class in S2's review, re-checked
  here since it's the same shape) — already inside the job's `try`/`catch`,
  so it correctly routes to `failArtifact()` instead of crashing the queue
  worker. No change needed; already correct.
- **Filament panel authorization on the new `ViewLessonArtifact` page** — no
  `LessonPolicy` exists, so Filament's default (allow) applies, same as
  every other `LessonResource` page already in the codebase. Not a new gap
  Block 4 introduced; `BelongsToTenant`'s scope on the underlying `Lesson`
  query already makes a cross-tenant record ID unreachable regardless.

Rolled up into `docs/PROJECT-STATUS.md`'s new S3 section.

### Post-S3 review, round 2 (2026-08-10)

Full re-read of every B0-B7 file (all 4 ingestion jobs, both listeners, all
3 AI-domain models, `DoubtChatService`, `TokenCostLedger` + its widget,
`FakeAiDriver`, all 3 migrations, `RebindsTenant`, `LessonAccess`,
`WatchLessonController`, the `LessonResource`/relation-manager/`EditLesson`
UI, the doubt-chat Livewire component + view, `lesson-player.blade.php`'s
`seek-to` wiring, and the tenancy/DI registrations in
`AppServiceProvider`) plus a fresh `composer test` run — **137/137, zero
regressions.**

**No new bug found.** Round 1's chunk-duplication fix
(`ChunkTranscript::handle()` deleting existing `lesson_artifact_chunks`
rows before rebuilding) is in place and doing its job: traced the retry
path by hand (`RetryIngestion` only resets `status`/`failure_reason`, not
the content columns) and confirmed every content column
(`transcript`/`chunks`/`concepts`/`syllabus_map`/`difficulty`/
`seed_questions`/`model_route`/`token_cost`) is fully overwritten as a unit
by whichever job produces it, so a retry that fails at a different stage
than the previous attempt can never leave a `ready` artifact with
mismatched old+new data.

**Checked, not changed:**

- **`TokenCostLedger::totalForDate()` buckets by `lesson_artifacts.created_at`
  (Block 6),** not by when `token_cost` was actually written
  (`ExtractLessonConcepts`, stage 3 of 4). In the normal path these are the
  same calendar day. They diverge only if a lecture fails ingestion, sits
  failed, and gets manually retried on a *later* day — then the eventual
  token spend is attributed to the original upload day, not the retry day.
  Not fixed: B6 is explicitly "measurement only, no cap or billing" (its own
  docblock), nothing reads this ledger for enforcement, and the fix
  (switching to `updated_at` or adding a dedicated `completed_at` column) has
  no test covering the multi-day-retry path to guard it — not worth the risk
  for a demo-scope dashboard figure.
- **`DoubtChatService::ask()`'s "still processing" placeholder answer**
  (`app/Domains/AI/Services/DoubtChatService.php:38-44`) is returned for any
  lesson with no `ready` artifact yet, including one whose only artifact is
  permanently `failed`. The wording ("check back in a few minutes") reads
  oddly for a lecture that will never finish without a teacher manually
  retrying it. Not a data or access-control bug — `LessonAccess` gating and
  `latestReadyFor()`'s read contract are both still correct — and
  `WatchLessonController::isPlayable()` guarantees every lesson reaching this
  code path already has a video, so an artifact does eventually exist for
  it. Cosmetic wording gap, not scope for this pass.
- **`DoubtChat::citationsFor()`** (`app/Domains/AI/Livewire/DoubtChat.php:57`)
  re-queries `LessonArtifact::latestReadyFor()` once per rendered message in
  the blade `@foreach`, an N+1 read pattern. No correctness impact (every
  message in a single-lesson chat resolves against the same artifact) —
  efficiency-only, out of scope for a bug-hunting pass.
