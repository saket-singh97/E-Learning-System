# Design: S22 AI-Native Content Layer

## 1. Why this document exists

Locks scope and architecture for S22 before any block is built, same role
every prior slice's own design doc played. Companion build log:
`2026-08-29-s22-ai-native-content-layer-plan.md`.

`docs/ROADMAP.md`'s own S22 row names 5 items (auto lecture notes, auto
chapters/timestamps, Daily Practice Problems, ask-at-timestamp, auto
formula sheets) drawing from `docs/FEATURES.md`'s U1-U8. Same
"verify before building" discipline as every prior slice: **real build
scope is 8 items, not 5 headline sentences** — the roadmap row compresses
U1-U8 into 5 clauses; U6 (auto quiz), U7 (audio-only revision), and U8
(teacher lesson-plan assistant) are real, separately-buildable FEATURES.md
rows the headline never named. Two more items are added here after an
explicit audit of S11-S21's own "deferred as fast-follow" notes (see
§2.3) — neither fits this slice's own AI-Native theme, but both are real,
still-open functional gaps no later slice ever picked up.

## 2. Scope

### 2.1 In scope — 8 AI-Native items (U1-U8)

| # | Item | Source | Why now |
|---|---|---|---|
| U1 | Auto lecture notes from a live class — transcript → structured PDF notes, posted to the topic within minutes of class ending | FEATURES.md U1, P1 | `LessonArtifact.transcript` is already produced by S3's own ingestion pipeline; this is the first thing that reads it for something other than doubt-chat grounding |
| U2 | Auto chapters/timestamps on recordings — jump straight to "Carnot cycle explanation at 34:12" | U2, P1 | `LessonArtifact.concepts` + `LessonArtifactChunk.start_s` are both already extracted; nothing renders them as player markers yet |
| U3 | Daily Practice Problems (DPP) — an auto-generated daily set from topics taught so far | U3, P1 | `QuestionGenerator::fromSeedQuestions()` (S6) already promotes `LessonArtifact.seed_questions` into draft `Question` rows for free, no AI call — DPP is the aggregation layer this never had a caller for |
| U4 | Ask-at-timestamp — student pauses at 12:30 and asks; AI answers using *that* part of the lesson | U4, P2 | `DoubtChatService`'s existing per-lecture RAG (S3) already retrieves by chunk; this scopes retrieval to chunks near a given timestamp instead of the whole lesson |
| U5 | Auto formula sheet / revision cards per chapter | U5, P2 | Same already-extracted `concepts` field U2 reads, different rendering |
| U6 | Auto quiz right after every live class | U6, P1 | Named in FEATURES.md's own U-series but folded into the roadmap row's "Daily Practice Problems" clause — verified as its own deliverable: reuses `QuestionGenerator::fromSeedQuestions()` directly, triggered by ingestion-ready instead of an admin action |
| U7 | Audio-only revision mode — strips video, plays narration, cuts bandwidth ~10x | U7, P2 | Ties directly into the existing `BandwidthQuota` cost problem (S9); genuinely greenfield, no audio pipeline exists today |
| U8 | Teacher lesson-plan assistant — AI drafts a plan for the next topic from the syllabus map | U8, P2 | `CourseOutlineGenerator` (S17) already does "AI drafts a plan from a topic+level"; `LessonArtifact.syllabus_map` already exists — this reuses both instead of inventing a third AI-drafting shape |

### 2.2 In scope — 2 backlog items found by the S11-S21 audit

Per this slice's own build instruction ("check S11-S21 plan if anything
missing, drop in S22 plan"), grepped `docs/PROJECT-STATUS.md` for every
"deferred"/"fast-follow"/"still rough" note left by S1-S21 and checked
each against the code as it stands today (not assumed from the note's own
age). Most are either genuinely cosmetic (three same-day migrations that
could have been one), deliberate scope cuts already documented as
deliberate (real MaxMind GeoIP driver, real payout money-movement,
mid-cycle proration actually charged), or already closed by a later slice
(S15's own "bulk course/batch import... explicitly deferred" note was
closed by S21 B10-B11). Two are neither — genuine, still-open functional
gaps in already-shipped billing/growth features that no slice since S20
has touched:

| # | Item | Source | Why it's real, not cosmetic |
|---|---|---|---|
| Z1 | `StudentSubscription::renew()` has no scheduled trigger — a student's own subscription never actually renews on its own | S20 B3-4, confirmed still true by reading `app/Domains/Fees/Services/StudentSubscriptionService.php` and `bootstrap/app.php`'s `withSchedule()` — no `renew` job exists anywhere | A shipped "student memberships" feature (S20) that silently never renews past the first period is a real product bug hiding behind "deferred," not a nice-to-have |
| Z2 | `AffiliateCode`'s commission confirmation isn't wired to fire automatically on payment capture — every affiliate commission sits `pending` forever unless someone manually confirms it | S20 B7-9, confirmed still true by reading `app/Domains/Growth/Listeners/` — no listener calls `confirmCommission()` | Same shape: a shipped "affiliate program" feature where the payout math never actually activates without a manual step nobody's UI exposes yet |

Both are Fees/Growth domain fixes, not AI-Native content — kept as their
own two blocks (B10-B11) rather than folded into the AI blocks, same
"mixed grab-bag, not a phase-boundary slice" posture S16/S20 already took
when a real gap didn't match the slice's own headline theme.

### 2.3 Verified before writing this design (checked, not assumed)

- **`LessonArtifact.transcript`/`.concepts`/`.syllabus_map`/`.seed_questions`
  all already exist** (confirmed by reading the model) — U1/U2/U5/U8/U3/U6
  are reading already-extracted data, not adding a new AI extraction step.
- **`LessonArtifactChunk.start_s`/`.end_s` are already time-anchored**
  (confirmed by reading the model) — U2's timestamp markers and U4's
  scoped retrieval both key off this existing column, no schema change
  needed on the chunk table itself.
- **`QuestionGenerator::fromSeedQuestions()` already exists and does the
  exact "no AI call, promote seed_questions into draft Questions" step**
  (confirmed by reading the service) — U3 and U6 both call this directly;
  neither needs its own AI-generation path.
- **`CourseOutlineGenerator::generate()` already exists** (confirmed by
  reading the service) — U8 reuses its exact `AiProvider::chat()` +
  structured-response shape, scoped to "next topic from this lesson's own
  `syllabus_map`" instead of a blank topic+level.
- **No audio-extraction tooling exists anywhere in this app** (grepped for
  `ffmpeg`, zero hits) — U7 is genuinely greenfield, and follows this
  app's own established "driver interface + Fake now, real driver is a
  later fast-follow" shape (`AiProvider`/`VideoProvider`/
  `SubscriptionProvider` all already do this) rather than inventing a
  different integration pattern.
- **`StudentSubscription::renew()` (Z1) and `AffiliateCode::confirmCommission()`
  (Z2) are both real, unwired gaps**, not already fixed by a later slice —
  confirmed by reading `bootstrap/app.php`'s `withSchedule()` block (no
  renewal job) and `app/Providers/AppServiceProvider.php`'s
  `Event::listen()` block (no listener calls `confirmCommission()`).

### 2.4 Out of scope, and why (locked before any code)

- **U9-U25 (S23-S24's own items) stay cut.** Confusion-signal alerting,
  syllabus tracker, PYQ bank, WhatsApp doubt bot, and the rest are each
  their own already-scoped future slice (`docs/ROADMAP.md`'s S23/S24
  rows) — pulling any forward here would blur this slice's own AI-Native
  theme the same way S16/S20 warn against doing without a strong reason.
- **A real audio-extraction driver (ffmpeg/Bunny audio API) is deferred.**
  U7 ships with `FakeAudioExtractionDriver` bound, same "Fake driver now,
  real one once a vendor/tool is actually chosen" posture `AiProvider`
  and `VideoProvider` both shipped with originally. Binding a real driver
  is a fast-follow, not blocking this slice.
- **Z1/Z2's own root-cause refactor (the 3x-duplicated row-lock-verify-status
  shape across `RefundApprovalService`/`AffiliatePayoutService`/
  `InstructorPayoutService`, flagged by S20's own B14 review) stays cut.**
  That's a code-quality/DRY concern, not a functional gap — extracting a
  shared base class is a separate, non-urgent refactor, not part of
  closing Z1/Z2's actual missing behavior.

## 3. Architecture

### 3.1 Domain ownership

- **`AI`** (extended) — U1 (`LessonNotesGenerator`), U2 (chapter-marker
  extraction from existing `concepts`/`start_s`), U5 (formula-sheet
  generator), U8 (`LessonPlanAssistant`, sibling to `CourseOutlineGenerator`).
- **`Assessments`** (extended) — U3 (`daily_practice_sets` +
  `DailyPracticeSetGenerator`, calling `QuestionGenerator::fromSeedQuestions()`),
  U6 (`GenerateQuizFromLiveClass` listener, same call).
- **`Content`** (extended) — U4 (`DoubtChat`'s existing timestamp-aware
  variant — actually already accepts a lesson, adds an optional
  `$atSecond` scoping param, no new domain), U7 (`lesson_audio_tracks` +
  extraction job + audio-only player toggle).
- **`Fees`** (extended) — Z1 (`RenewStudentSubscriptions` scheduled job).
- **`Growth`** (extended) — Z2 (`ConfirmCommissionOnPaymentCaptured` listener).
- **`Integrations`** (extended) — new `AudioExtractionProvider` interface +
  `FakeAudioExtractionDriver`, same shape every other vendor integration
  in this app already uses.

### 3.2 New schema

```
-- AI: U1
lesson_notes (AI — U1)
  id, tenant_id, lesson_id, disk_path (the generated PDF), status
  (pending|ready|failed), generated_at (nullable), timestamps
  -- one row per lesson; regenerating overwrites the same row's disk_path,
  -- same "latest wins" posture LessonArtifact's own versioning avoids
  -- needing here since there's no "stale download in flight" risk a PDF
  -- link has that a video stream doesn't.

-- Assessments: U3
daily_practice_sets (Assessments — U3)
  id, tenant_id, batch_id, practice_date (date), question_ids (json,
  array of Question ids drawn from fromSeedQuestions() across the batch's
  own lessons taught up to practice_date), timestamps
  -- unique (batch_id, practice_date)

-- Content: U7
lesson_audio_tracks (Content — U7)
  id, tenant_id, lesson_id, disk_path, status (pending|ready|failed),
  timestamps
  -- one row per lesson, same "latest wins, no versioning" shape as
  -- lesson_notes — a re-extraction overwrites the row, there's no
  -- multi-version consumer contract the way LessonArtifact's own S1 §5.1
  -- rule exists for.

-- No new table: U2, U4, U5, U8 all read existing LessonArtifact/
-- LessonArtifactChunk columns (concepts, syllabus_map, start_s/end_s) —
-- U2/U5 render already-extracted data, U4 scopes an existing retrieval
-- call, U8 calls an existing generator with a different prompt.

-- No new table: Z1/Z2 are both behavior fixes on existing tables
-- (student_subscriptions, affiliate_referrals) — no schema change.
```

### 3.3 New `BelongsToTenant` models (all registered in `TenantModelRegistry`)

`LessonNote` — **name collision check done first**: `App\Domains\Content\Models\LessonNote`
already exists (S13's own student-authored video-timestamp notes) — this
slice's U1 output is named `LessonPdfNote` instead, avoiding the collision
rather than overloading the existing model with a second, AI-authored
meaning. `DailyPracticeSet`, `LessonAudioTrack` — **3 new models total**.

## 4. Design decisions

1. **U1/U2/U5 are three thin readers of the same already-extracted
   `LessonArtifact` columns, not one shared "content deriver" abstraction.**
   Same "don't build one shared abstraction for divergent output shapes"
   reasoning S21 §4 decision 7 already used for its own three CSV
   importers — a PDF, a set of player-overlay timestamps, and a formula
   card each render the same source data completely differently.
2. **U3/U6 both call `QuestionGenerator::fromSeedQuestions()` directly,
   never duplicate its logic.** DPP aggregates across a batch's own
   lessons taught up to a date; the live-class auto-quiz targets exactly
   one lesson — different callers, same one generator underneath, matching
   the "one place already does this" posture this codebase's every prior
   slice has used for a shared operation.
3. **U6 triggers off the *existing* ingestion-ready signal, not a new
   event.** `LessonArtifactReady` (S3) already fires once a live
   recording's own transcript/seed_questions finish processing — U6's own
   listener is additive to that event's existing listener list
   (`GenerateLessonSubtitles` already listens here), not a new trigger
   point.
4. **U4 extends `DoubtChatService::ask()` with an optional
   `?int $atSecond` parameter, not a new service.** When given, retrieval
   scopes to chunks whose `start_s`/`end_s` bracket (or are nearest to)
   that timestamp before falling back to the existing whole-lesson
   semantic search — same "optional parameter, byte-identical when
   omitted" shape S21's own Card EMI (`createOrder()`) already
   established for extending an existing call without a new interface
   method.
5. **U7 ships with a Fake driver bound, same as every other external
   integration in this app at first ship.** `AudioExtractionProvider`
   interface (`extract(Lesson): array{disk_path: string}`), real
   ffmpeg/Bunny-audio driver deferred — this slice proves the schema,
   job, and player toggle end-to-end against the fake, same shape
   `AiProvider`/`VideoProvider` shipped in S2/S3 before a real vendor
   existed.
6. **U8 reuses `CourseOutlineGenerator`'s exact AI-call shape** (same
   `AiProvider::chat()` + structured-response contract) rather than a
   second AI-drafting service — the only difference is the prompt reads
   the *preceding* lesson's own `syllabus_map` instead of taking a blank
   topic+level from the caller.
7. **Z1 (`RenewStudentSubscriptions`) is a daily scheduled sweep, same
   unscoped-CurrentTenant shape every other `Schedule::job()` entry in
   `bootstrap/app.php` already uses** (`FlagOverdueInvoices`,
   `SuspendOverdueTenantSubscriptions`) — finds every subscription whose
   `current_period_end` has passed, calls the *existing*
   `StudentSubscriptionService::renew()` (S20 already wrote this method;
   it was simply never scheduled), no new billing logic.
8. **Z2 (`ConfirmCommissionOnPaymentCaptured`) is an event listener on the
   *existing* `PaymentCaptured` event**, calling the *existing*
   `AffiliateAttributionService`'s own confirmation step — same
   one-way-ignorance shape every other `PaymentCaptured` listener already
   uses (`FinalizeEnrolmentOnPaymentCaptured`, S21's own
   `DispatchPaymentCapturedWebhook`). Explicitly does **not** fire for a
   program/membership-linked payment (those dispatch a different event
   entirely per S13/S20's own branching in `PaymentReconciler::capture()`),
   so no guard against double-firing is needed — the event itself never
   reaches this listener for those cases.

## 5. Testing strategy

- **Unit/feature per service**: `LessonPdfNoteGenerator` (renders from a
  real transcript, skips when no ready artifact exists — same "still
  processing" posture `DoubtChatService` already takes), chapter-marker
  extraction (timestamps match `concepts`/`start_s` exactly), DPP
  aggregation (correct question count across N lessons, respects
  `practice_date` cutoff), U6's listener (fires only on a live-class
  lesson, not every ingestion), `DoubtChatService`'s `$atSecond` scoping
  (nearby chunks preferred, falls back correctly when omitted), formula
  sheet rendering, audio-extraction job (Fake driver, byte-identical
  lesson otherwise), `LessonPlanAssistant` (reads the preceding lesson's
  `syllabus_map`, not a blank prompt) — each gets its own test file, same
  per-service discipline every prior slice used.
- **Cross-tenant isolation**: all 3 new `BelongsToTenant` models register
  in `TenantModelRegistry`, auto-generating `CrossTenantTest` coverage.
- **Z1/Z2 regression tests**: a subscription past `current_period_end`
  renews on the next scheduled sweep run and not before; a captured
  payment confirms its affiliate commission automatically, a
  program/membership-linked payment does not fire the listener at all.
- **Lifecycle E2E** (final block, same as every prior slice): a live
  class ends → ingestion completes → auto-quiz exists and DPP for that
  day includes its questions; a student asks a doubt at a specific
  timestamp and gets an answer grounded in that timestamp's own chunk,
  not the whole lesson; a teacher opens the lesson-plan assistant for the
  next topic and gets a draft grounded in the real `syllabus_map`.
