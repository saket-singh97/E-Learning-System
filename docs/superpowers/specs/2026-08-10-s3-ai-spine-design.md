# Design: S3 AI Spine

Date: 2026-08-10
Status: LOCKED — embedding decision made 2026-08-10 (see §2.4)
Scope: Sub-project 3 of 8 in the P1 build (Institute Zero)
Window: 2026-09-05 (S2 demo) → 2026-09-19 (S3 demo)
Depends on: `2026-08-08-s1-foundation-design.md` (the `lesson_artifacts`
contract, §5, pinned but unbuilt) and `2026-08-09-s2-content-video-design.md`
(video storage, `video_status`, the `Integrations/` interface+FakeDriver
pattern this reuses)

---

## 1. Why this document exists

Same reason S2 got one: the P1-wide decomposition (all eight sub-projects,
the calendar, the dependency graph) already lives in
`2026-08-08-s1-foundation-design.md` §2 and is not repeated here. What's new
here is S3-specific: scope, architecture, schema, and the block plan that
follows in `2026-08-10-s3-ai-spine-plan.md`.

S3 is different from S1/S2 in one important way: its central table was
**already designed**, not discovered mid-slice. S1 Block 10 pinned the full
`lesson_artifacts` / `lesson_artifact_chunks` schema and verified all eleven
future consumer queries against it on paper (§5 of the S1 design). S3 is the
first slice to actually build a migration for it and the first to write to
it. Nothing about that schema changes here — this document is the pipeline
and the first two consumers around it, not a schema redesign.

---

## 2. S3 scope

### 2.1 In scope

| # | Deliverable | Source |
|---|---|---|
| 1 | `lesson_artifacts` + `lesson_artifact_chunks` migrations, exactly as pinned in S1 §5 | S1 Block 10 |
| 2 | `AiProvider` interface + `FakeAiDriver` (transcribe, chat, embed) | This doc §3.3, mirrors S2's `VideoProvider` pattern |
| 3 | Ingestion pipeline: transcript → chunks → concepts/syllabus-map/difficulty/seed-questions → embeddings, triggered when a lesson's video becomes `ready` | Roadmap: "Upload a lecture → get a transcript, concepts" |
| 4 | Versioning per S1 contract rule 3 — a new upload creates a new `version`; the previous `ready` version keeps serving until the new one reaches `ready` | S1 §5.1 |
| 5 | Ingestion status in `/teach`: pending/processing/ready/failed badge per lesson, manual retry, a transcript+concepts viewer | Teacher-facing half of the demo script |
| 6 | Doubt chat: a student asks a question against a lesson; answer is RAG'd from that lesson's chunks, cites a timestamp | Roadmap: "an answered doubt" |
| 7 | Token-cost measurement, per tenant per day (measurement only, no cap) | S1 contract rule 4; same "measure now, cap later" pattern as S2's bandwidth metering |
| 8 | `RebindsTenant` job-middleware test coverage against a real queued job | S1 verification-pass finding, 2026-08-09 — flagged as "fix once the first real queued job exists"; S3's ingestion jobs are that job |

### 2.2 Out of scope, and where each goes

| Cut | Lands in | Why deferring is safe |
|---|---|---|
| Real transcription/LLM vendor (Whisper/AssemblyAI/Deepgram, Claude/OpenAI) | Fast-follow, same slice, once a vendor + API key is chosen | Same shape as S2's `BunnyDriver`: `AiProvider` makes the swap additive, zero call-site changes. `FakeAiDriver` is enough to build and test the pipeline and demo the shape of the answer. |
| Question paper generation, revision notes, semantic search across lessons, weak-topic detection, auto chapters/lecture notes, daily practice problems, ask-at-timestamp UI, post-class auto quiz | S6 (mostly), except WhatsApp nudges → S8 | These are 8 of the "eleven consumers" from S1 §5.2 that read the *same* `lesson_artifacts`/`lesson_artifact_chunks` tables S3 builds — S3 ships the pipeline plus the 2 consumers (artifact viewer, doubt chat) the 2026-09-19 demo needs; the rest are each other sub-projects' whole subject |
| Cost caps, spend alerts, per-tenant AI budget enforcement | Blocked on Q1/Q2 (P2), same reasoning as S2's bandwidth quota | `token_cost` is recorded (contract rule 4) so spend is measurable from data later; enforcing a cap against plan tiers that don't exist yet is a P2 problem |
| Doubt chat across a whole course/syllabus (not just the one lesson the student is watching) | Later slice | Demo script (§6) only asks a question against the lesson in view; cross-lesson retrieval is the same infrastructure but a bigger ranking problem, not needed for 2026-09-19 |
| Streaming token-by-token chat responses (SSE/websocket) | Later slice, additive | `AiProvider::chat()` returns a complete response; a typing-indicator UX is a frontend nicety, not a demo blocker |
| Re-ingestion triggered automatically on every lesson edit | Manual retry only in S3 | Auto-retrigger on every title/position edit would re-run paid AI calls for no reason; only a genuine video-file replacement should re-ingest, and S2 doesn't yet support replacing an existing lesson's video file (only initial upload) |

### 2.3 Reviewed before writing this: nothing changed in S1/S2's contract

`lesson_artifacts` and `lesson_artifact_chunks` were re-checked against the
current S1/S2 codebase (post the 2026-08-10 bug-review round documented in
`docs/PROJECT-STATUS.md`) rather than assumed still valid. Nothing in either
review round touched the Content domain's `Lesson` model shape in a way that
invalidates the S1 §5 schema or its eleven read-queries. `lessons.video_status`
(added in S2 Block 1) is the trigger signal S3 needs and didn't exist when
S1 §5 was written — see §3.1 below for how that gap is closed.

### 2.4 Decided (2026-08-10, Block 0) — `text-embedding-3-small`, D = 1536

S1 §5 flags this as a one-way door: `lesson_artifact_chunks.embedding` is
`vector(D)`, a pgvector index requires a fixed dimension, and changing `D`
later means re-embedding every chunk in the system. Owner delegated the pick
rather than choosing between the two defaults below; `text-embedding-3-small`
won on semantic recall for doubt-chat retrieval, with vendor API cost/key
deferred to the real-driver fast-follow (`FakeAiDriver` needs neither now).

- `text-embedding-3-small` (OpenAI-compatible APIs) → `D = 1536` — **picked**
- A smaller self-hosted option (e.g. `all-MiniLM-L6-v2`) → `D = 384`, cheaper
  to run, weaker semantic recall — not picked

`FakeAiDriver::embed()` doesn't care which — it returns a deterministic
`D`-length vector regardless of vendor, so Blocks 1-6 can be built and fully
tested against either choice, and the migration just needs `D` filled in
before Block 2.

---

## 3. Architecture

### 3.0 Where this lands in the existing domain layout

A new domain, `app/Domains/AI/`, following the same shape as `Tenancy` and
`Content`:

```
app/Domains/AI/
  Models/       LessonArtifact, LessonArtifactChunk, DoubtChatMessage
  Services/     IngestionPipeline, DoubtChatService, TokenCostLedger
  Filament/     artifact status column/page surfaced on Lesson (in /teach)
  Livewire/     DoubtChat (embedded on the student lesson-player view)
  Jobs/         TranscribeLesson, ChunkTranscript, ExtractLessonConcepts,
                EmbedLessonChunks
  Events/       LessonArtifactReady, LessonArtifactFailed
```

`app/Integrations/AiProvider.php` + `app/Integrations/Drivers/FakeAiDriver.php`
sit alongside `VideoProvider`/`FakeVideoDriver`, not inside the domain —
same reasoning as S2: it's a swappable external capability, not
tenant-specific business logic.

**Cross-domain boundary, respected on purpose.** CLAUDE.md's rule — domains
talk to each other through `Events/` only — applies here for real, for the
first time: the ingestion trigger is "a Content-domain fact" (a lesson's
video became playable), but the pipeline that reacts to it is AI-domain
logic. Content fires a `LessonVideoReady` event when `Lesson::video_status`
transitions to `ready` (new event, added in S3 Block 3 since S2 built the
column but never needed to broadcast its transition); AI's own listener
dispatches the ingestion job chain. AI's jobs still read `Lesson` directly
for the video's disk path — that's data access, not calling into Content's
services, which is what the rule actually forbids.

### 3.1 Data flow — ingestion

```
S2: lesson.video_status → 'ready'
  │
  ▼
Content fires LessonVideoReady(lessonId, tenantId)
  │
  ▼
AI\Listeners\StartIngestion  (queued)
  │  creates lesson_artifacts row: version = prior+1, status = pending
  │  dispatches job chain, tenant_id threaded through every job
  ▼
TranscribeLesson       AiProvider::transcribe(diskPath) → text + timestamps
  ▼
ChunkTranscript         splits into lesson_artifact_chunks rows (idx, start_s, end_s, text)
  ▼
ExtractLessonConcepts   AiProvider::chat() with a structured-output schema →
                         concepts, syllabus_map, difficulty, seed_questions
  ▼
EmbedLessonChunks       AiProvider::embed() per chunk → writes .embedding
  ▼
lesson_artifacts.status = 'ready'  →  fires LessonArtifactReady
```

Every job **re-binds `CurrentTenant` from the `tenant_id` it was dispatched
with**, before touching any tenant-scoped model — this is the exact pattern
S1 §4.2 specified in advance for this exact situation ("Queue jobs have no
subdomain... every queued job must carry `tenant_id` explicitly and re-bind
it before touching a model... this is specified in S1, not S3, because S3's
entire ingestion pipeline is queue-based"). `RebindsTenant` (the job
middleware S1 built and flagged as untested, §2.1 item 8 above) is what
these four jobs actually use — this closes that gap as a side effect of
building the feature it was always meant for.

**Failure at any step:** the job marks `lesson_artifacts.status = 'failed'`,
writes `failure_reason`, and fires `LessonArtifactFailed` — it does not
retry silently forever. The teacher sees a "failed" badge with the reason
and a manual retry action (§2.1 item 5). A later job in the chain never runs
against a `pending`/`failed` predecessor.

**Re-ingestion (a teacher replaces a lesson's video):** creates a new
`lesson_artifacts` row at `version = prior + 1`. Per S1 contract rule 3, the
previous `ready` version keeps serving every read path (doubt chat,
artifact viewer) until the new version reaches `ready` — there is no window
where a lesson has no usable artifact because a re-ingest is in flight.

### 3.2 Data flow — doubt chat

```
Student on the lesson-player page asks a question
  │
  ▼
DoubtChatService::ask(lesson, viewer, question)
  │  LessonAccess::isVisibleTo() + canStream() — same single gate as
  │  streaming and the heartbeat use (CLAUDE.md rule: one gate, never inline)
  │  abort if no ready lesson_artifacts version exists yet ("still processing")
  ▼
AiProvider::embed(question) → query vector
  ▼
pgvector cosine-similarity search over lesson_artifact_chunks
  WHERE tenant_id = current AND lesson_artifact_id = <lesson's latest-ready>
  ORDER BY embedding <=> query_vector LIMIT k
  ▼
Build RAG prompt: question + top-k chunks (with start_s/end_s)
  ▼
AiProvider::chat(messages) → answer text
  ▼
DoubtChatMessage::create([...question, answer, cited_chunk_idxs])
  ▼
Rendered with a timestamp citation that seeks the existing LessonPlayer
```

This is S1 §5.2 consumer #2 ("doubt-chat RAG") and #10 ("ask-at-timestamp",
partially — S3 answers a free-text question, not a timestamp-seeded one;
full ask-at-timestamp is a later slice per §2.2) built for real, against the
exact retrieval shape already verified on paper in S1 §5.3.

### 3.3 `AiProvider` contract

```php
interface AiProvider
{
    public function transcribe(string $diskPath): TranscriptResult; // text + word/segment timestamps
    public function chat(array $messages, ?array $responseSchema = null): ChatResult; // responseSchema drives structured JSON mode for concept/question extraction
    public function embed(string $text): array; // float[], length D
}
```

`FakeAiDriver` returns deterministic, hash-seeded output for all three
methods — a fixed fake transcript/concepts for a given input, and a fake
embedding vector that's stable for the same text (so similarity search in
tests is reproducible, not random). No API key required, same reasoning S2
gave for `FakeVideoDriver`: "no paid vendor needed yet." Real drivers
(`OpenAiDriver`, `WhisperDriver`, or whatever the owner picks per §2.4)
arrive as a fast-follow, additive, zero call-site changes — the pipeline and
doubt chat are built and fully tested against the fake now.

### 3.4 Schema

```
lesson_artifacts                                  (exactly as pinned, S1 §5)
  id · tenant_id · lesson_id
  version int · source_asset_version string
  status enum(pending, processing, ready, failed)
  transcript text
  chunks jsonb · concepts jsonb · syllabus_map jsonb
  difficulty jsonb · seed_questions jsonb
  model_route jsonb · token_cost jsonb
  failure_reason text nullable
  created_at · updated_at
  unique (lesson_id, version)

lesson_artifact_chunks                            (exactly as pinned, S1 §5)
  id · tenant_id · lesson_artifact_id
  chunk_idx · start_s · end_s · text
  embedding vector(D)                              -- D fixed per §2.4

doubt_chat_messages                                (new in S3, not in S1 §5 —
                                                      S1 pinned the artifact
                                                      tables; chat history is
                                                      S3's own addition)
  id · tenant_id · lesson_id · user_id
  question text · answer text
  cited_chunk_idxs jsonb
  created_at
```

`CREATE EXTENSION IF NOT EXISTS vector` ships as its own migration, first,
in Block 1 — the `pgvector/pgvector:pg16` Docker image already provisioned
in S1 Block 1's `docker-compose.yml` has the extension available; it's
never been enabled in a migration because nothing needed it until now.

Both `LessonArtifact`/`LessonArtifactChunk`/`DoubtChatMessage` use
`BelongsToTenant` and get added to `TenantModelRegistry::MODELS` — the
cross-tenant isolation suite generates their leak/guard tests automatically,
per the CLAUDE.md rule; no hand-written per-model isolation tests.

---

## 4. Error handling

| Failure | Handling |
|---|---|
| `AiProvider::transcribe()` throws (bad file, vendor timeout) | Job catches, sets `status = failed`, `failure_reason`, fires `LessonArtifactFailed`. Teacher sees the reason and a retry button. |
| A later pipeline step runs against a `pending`/`failed` artifact | Can't happen — each job is the next link in a Laravel job chain; a chain stops on the first failure, it doesn't skip ahead. |
| Doubt chat asked before any `ready` artifact exists | `DoubtChatService` returns a "still processing" state, not an exception — same null-safety posture as `LessonAccess::isPlayable()`, which this session's bug review just reinforced (a missing check there let watch-events log against unplayable lessons; the same class of gap here would let a chat answer come back empty/garbled instead of a clear "not ready yet"). |
| A lesson is soft-deleted (or its section/course is) mid-ingestion | `LessonAccess::isVisibleTo()` already returns false for a trashed parent (fixed in the S2 review) — doubt chat and the artifact viewer inherit that for free by going through the same gate. The ingestion job itself still finishes (or fails) independently; it doesn't need to check visibility, only that the `Lesson` row and its video path still exist. |
| Re-ingestion runs before the previous version reached `ready` | Contract rule 1 already resolves this: reads always take the highest `version` where `status = ready`. A second `pending`/`processing` row is invisible to every read path until it either completes or fails. |

---

## 5. Testing

### 5.1 The isolation suite

Three new `TenantModelRegistry` members — `LessonArtifact`,
`LessonArtifactChunk`, `DoubtChatMessage` — each gets the standard
generated cross-tenant read/update/no-context-guard tests automatically.

### 5.2 Other S3 tests

- Ingestion pipeline, end-to-end against `FakeAiDriver`: upload → job chain
  runs → `lesson_artifacts.status` reaches `ready` → chunks + embeddings
  exist → `LessonArtifactReady` fired.
- Ingestion failure path: force `FakeAiDriver` to throw mid-chain → `status
  = failed`, later steps never run, retry re-attempts from a clean state.
- Versioning: re-ingest a lesson with an existing `ready` artifact → the
  old version keeps answering doubt-chat/viewer reads until the new one
  reaches `ready`, per contract rule 3.
- `RebindsTenantTest` — the S1-flagged gap — dispatches `TranscribeLesson`
  with/without `tenant_id`, asserts `CurrentTenant` gets bound / asserts
  `$job->fail()` is called with `MissingTenantContextException`.
- Doubt chat: seeded lesson + artifact + chunks, ask a question, assert the
  retrieved chunks are scoped to that lesson's latest-ready artifact only
  (a chunk from another lesson, or an older non-ready version, must never
  surface).
- Doubt chat access: guest / wrong-tenant / lesson-not-visible-yet all get
  the same rejection `LessonAccess` already gives streaming — one shared
  gate, one shared test shape (mirrors `LessonAccessGateTest`'s pattern from
  the S2 review).
- Token-cost measurement: an ingestion run's `token_cost` rolls up into the
  per-tenant-per-day ledger, mirroring `BandwidthMeteringTest`'s shape.

---

## 6. Demo #3 — 2026-09-19, 10 minutes

1. Teacher uploads a lecture (existing S2 flow) — the lesson's ingestion
   badge shows `pending` → `processing` (pre-seeded to `ready` for demo
   reliability, same call S2 made for the video-processing badge).
2. Teacher opens the lesson in `/teach`, sees the generated transcript and
   concept list.
3. Student opens the same lesson, types a question into the doubt-chat box.
4. Answer comes back with a cited timestamp; clicking it seeks the existing
   video player to that point.

---

## 7. Definition of done for S3

- All 8 blocks in `2026-08-10-s3-ai-spine-plan.md` done and tested.
- Isolation suite green with all three new models registered.
- `RebindsTenant` gap (flagged 2026-08-09, still open going into S3) closed
  with real test coverage.
- Demo #3 script above runs end-to-end against `FakeAiDriver`.
- Nothing in S1/S2's existing 109 tests regresses.

---

## 8. Documents this spec makes stale

None. This is the first design document for S3; nothing existing claimed to
cover this ground except the S1 §5 contract, which this document builds
against unchanged, not over.
