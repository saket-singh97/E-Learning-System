# Implementation Plan: S22 AI-Native Content Layer

Full design: `2026-08-29-s22-ai-native-content-layer-design.md`. This is
the block-by-block build log — update the PROGRESS table as each block
lands, same as every S1-S21 plan did. 14 blocks (B0-B13): 8 AI-Native
items (U1-U8) split across 10 blocks, 2 backlog gaps found by this
slice's own S11-S21 audit (Z1-Z2), a registry sweep, and a final block.

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-29-s22-ai-native-content-layer-design.md and
docs/superpowers/specs/2026-08-29-s22-ai-native-content-layer-plan.md, then build
Block 0 of the S22 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | U1 — Auto lecture notes: transcript → structured PDF, `LessonPdfNote` model | ✅ done |
| B1 | U2 — Auto chapters/timestamps: player markers from `concepts`+`start_s` | ✅ done |
| B2 | U6 — Auto quiz right after every live class (reuses `fromSeedQuestions()`) | ✅ done |
| B3 | U3 pt.1 — DPP schema + `DailyPracticeSetGenerator` aggregation service | ✅ done |
| B4 | U3 pt.2 — DPP student-facing page + generation trigger | ✅ done |
| B5 | U4 — Ask-at-timestamp: `DoubtChatService` gains optional `$atSecond` scoping | ✅ done |
| B6 | U5 — Auto formula sheet / revision cards per chapter | ✅ done |
| B7 | U7 pt.1 — Audio-only mode: `AudioExtractionProvider` interface + Fake driver + `lesson_audio_tracks` | ✅ done |
| B8 | U7 pt.2 — Audio-only player UI toggle + bandwidth-savings check | ✅ done |
| B9 | U8 — Teacher lesson-plan assistant (`LessonPlanAssistant`, sibling to `CourseOutlineGenerator`) | ✅ done |
| B10 | Z1 — `StudentSubscription::renew()` scheduled trigger (S20 gap) | ✅ done |
| B11 | Z2 — Affiliate commission auto-confirms on `PaymentCaptured` (S20 gap) | ✅ done |
| B12 | `TenantModelRegistry` sweep — 3 new models confirmed registered | ✅ done |
| B13 | Full test coverage, lifecycle E2E, review pass, `PROJECT-STATUS.md`/`HANDOVER.md`/`ROADMAP.md` update | not started |

**Test suite baseline:** 1883+/1886+ passing at slice start per S21's own
final count (1 pre-existing, unrelated `CourseSlugUniquenessTest` failure
carried since S1/S2, out of scope here same as every prior slice; a
rotating pair of test-order flakes among 3 known files may also appear —
re-run in isolation before treating any of the 3 as a regression, same
posture S21's own blocks established).

---

## Block detail (starting checklist — expand into a build narrative as each block actually lands, same as every prior plan file)

### B0 — U1: auto lecture notes ✅

- [x] `lesson_pdf_notes` migration (table renamed from the design doc's
      `lesson_notes` — that table already exists, S13) + `LessonPdfNote`
      model (AI), registered in `TenantModelRegistry`.
- [x] `LessonPdfNoteGenerator` — reads `LessonArtifact::latestReadyFor()`'s
      own `transcript`, one AI call (`responseSchema: ['notes']`, same
      contract `RevisionNotesGenerator` already established) to structure
      it into notes, renders to PDF via the same `Pdf::loadView(...)->save()`
      path `CertificateService` already uses, writes through
      `Storage::disk('local')`.
- [x] `GenerateLessonPdfNotes` listener, additive to the existing
      `LessonArtifactReady` event (same shape as `GenerateLessonSubtitles`)
      — this is what makes it "auto" (design doc §2.1 "posted... within
      minutes of class ending") rather than requiring a manual trigger.
- [x] Tests (`tests/Feature/AI/LessonPdfNoteGeneratorTest.php`): generates
      from a real transcript, "still processing" placeholder row when no
      ready artifact exists (mirrors `DoubtChatService`'s own posture),
      regenerating overwrites the same row.

### B1 — U2: auto chapters/timestamps ✅

- [x] No new table — `ChapterMarkerReader` reads `LessonArtifact.concepts`
      (each `{name, confidence, chunk_idxs}`, confirmed from
      `ExtractLessonConcepts`) + `LessonArtifactChunk.start_s` directly. A
      marker's timestamp is the earliest `start_s` among its concept's own
      `chunk_idxs`.
- [x] Player-facing chapter markers: `ai.chapter-markers` Livewire
      component (gated through `LessonAccess`, same posture
      `LessonRevisionNotes` already takes), embedded in the watch view
      next to the player, dispatching the player's existing `seek-to`
      window event (same idiom `doubt-chat.blade.php` already uses) — no
      new player wiring needed.
- [x] Tests (`tests/Feature/AI/ChapterMarkerReaderTest.php`): markers match
      `concepts`/`start_s` exactly and sort by time, empty list (not an
      error) when no concepts extracted yet, empty list when no ready
      artifact exists at all.

### B2 — U6: auto quiz after every live class ✅

- [x] `GenerateQuizFromLiveClass` listener on the *existing*
      `LessonArtifactReady` event (S3) — additive to that event's current
      listener list. Confirmed `LessonVideoReady`/`StartIngestion` fires
      for every lesson type, not just `live_session` — guarded on lesson
      type inside the listener.
- [x] Calls `QuestionGenerator::fromSeedQuestions()` directly, creates a
      real `Quiz` (status `draft`, `time_limit_minutes`/`max_attempts`
      matching `QuizResource`'s own form defaults) attached to the
      lesson. Stays draft — the underlying Questions are also
      draft/`ai_generated`, same S6 review-queue gate every other
      AI-drafted question already goes through before a student can be
      handed one.
- [x] Tests (`tests/Feature/Assessments/GenerateQuizFromLiveClassTest.php`):
      a live-class lesson's ingestion creates a draft quiz with the right
      question count, a non-live-session lesson's ingestion does not.

### B3 — U3 pt.1: DPP schema + aggregation ✅

- [x] `daily_practice_sets` migration (`batch_id`, `practice_date` unique
      per batch, `question_ids` jsonb) + `DailyPracticeSet` model
      (Assessments), registered in `TenantModelRegistry`.
- [x] `DailyPracticeSetGenerator` — "taught" = a `BatchSession` for this
      batch with a non-null `lesson_id` whose `starts_at` date is on or
      before `practice_date` (a session with no lesson is a bare
      timetable slot, not content). Calls
      `QuestionGenerator::fromSeedQuestions()` per distinct lesson,
      aggregates into one set. Idempotent by construction: a second call
      for an already-generated batch+date returns the existing row
      without re-promoting a duplicate batch of draft Questions.
- [x] Tests (`tests/Feature/Assessments/DailyPracticeSetGeneratorTest.php`):
      correct question count/lesson coverage across a multi-lesson batch,
      respects the `practice_date` cutoff, idempotent re-run returns the
      same row and doesn't grow the Questions table.

### B4 — U3 pt.2: DPP UI + trigger ✅

- [x] Student-facing daily practice page: `DailyPracticeBoard` Livewire
      component (lighter standalone view, not wrapped in `Quiz` — DPP's
      draft/`ai_generated` questions would never surface through
      `QuizAttemptService`'s approved-only draw pool anyway; DPP is
      ungraded self-practice, not an attempt). New host route
      `/batches/{batch}/daily-practice` (same "add a small host route"
      shape `batch-session.check-in` already used), gated the same
      "actively-enrolled student" way `GeofenceCheckIn` already is.
- [x] Generation trigger: on-demand at first visit, not a scheduled sweep
      — `DailyPracticeSetGenerator::generateFor()` is already idempotent
      (B3), so calling it on every `mount()` is safe and never builds a
      set for a batch nobody opens that day.
- [x] Tests (`tests/Feature/Assessments/DailyPracticeBoardTest.php`): an
      enrolled student sees today's set, a second visit the same day
      doesn't create a second `DailyPracticeSet` row, a non-enrolled user
      gets a 404.

### B5 — U4: ask-at-timestamp ✅

- [x] `DoubtChatService::ask()` gains an optional `?int $atSecond = null`
      parameter. Given, a private `nearbyChunks()` sorts the artifact's
      chunks by distance from the timestamp in PHP (no `orderByRaw` —
      CLAUDE.md keeps raw SQL out of `app/Domains/`) and takes `TOP_K`;
      falls back to the existing whole-lesson semantic search only if
      that comes back empty. Omitted, the method takes the exact same
      code path as before this block.
- [x] `DoubtChat` Livewire component: `ask(?int $atSecond = null)`. The
      view tracks the player's playhead via the *existing*
      `lesson-time-update` window event (already dispatched by
      `lesson-player.blade.php`) in a plain Alpine `x-data`, and an "ask
      about this moment" checkbox routes it through `$wire.ask(...)` only
      when checked — no new player wiring, no extra network round trip
      (the deferred `wire:model="question"` rides the same request).
- [x] Tests (`tests/Feature/AI/DoubtChatTest.php`): a timestamped question
      keeps a nearby-in-time chunk's text in the AI context and drops a
      distant chunk's, regardless of which the fake embedder would rank
      closer; the full pre-existing `DoubtChatTest` suite (omitted
      `$atSecond` throughout) passes unchanged.

### B6 — U5: auto formula sheet / revision cards ✅

- [x] No new table — `ChapterFormulaSheetReader` reads
      `LessonArtifact.concepts` directly. **Confirmed gap, not silently
      worked around**: `concepts` only carries `{name, confidence,
      chunk_idxs}` (`ExtractLessonConcepts`, S3) — no formula text or
      definition field exists. This renders a per-chapter revision card
      of the distinct concept *names* taught, not a worked formula
      sheet; a real formula sheet needs `ExtractLessonConcepts`'s own AI
      schema widened with a formula/definition field, out of scope here.
- [x] In-app card view (not PDF — no storage/quota plumbing needed for a
      plain list): `ai.formula-sheet` Livewire component, new host route
      `/course-sections/{section}/formula-sheet` (same "add a small host
      route" shape B4 already used), gated via
      `LessonAccess::isCourseVisibleTo()` — chapter-level review content,
      same sensitivity as the syllabus tree it's reached from, not a
      per-lesson streaming gate.
- [x] Tests (`ChapterFormulaSheetReaderTest.php`, `FormulaSheetTest.php`):
      cards render deduplicated concept names across every lesson in the
      chapter, empty state when none extracted, a draft course's chapter
      404s a guest.

### B7 — U7 pt.1: audio-only mode, extraction ✅

- [x] `lesson_audio_tracks` migration + `LessonAudioTrack` model
      (Content), registered in `TenantModelRegistry`.
- [x] `AudioExtractionProvider` interface (`app/Integrations/`) +
      `FakeAudioExtractionDriver`, same Fake-first shape
      `AiProvider`/`VideoProvider` already established. No real driver
      bound (deliberate, §2.4) — bound directly too, no circuit-breaker
      wrapper: that layer (S12 B10) retrofitted providers that existed
      *then*, not a resilience requirement for a new Fake-only interface.
      `ExtractLessonAudio` listener, additive to the *existing*
      `LessonVideoReady` event (rides the video-ready signal, not
      `LessonArtifactReady` — audio extraction doesn't need the AI
      pipeline at all).
- [x] Tests (`tests/Feature/Content/ExtractLessonAudioTest.php`):
      extraction creates a `ready` track against the fake driver, a
      throwing driver marks the track `failed` without fatally erroring
      (same `InteractsWithQueue`/`fail()` shape `GenerateLessonSubtitles`
      already needed, S14 B18).

### B8 — U7 pt.2: audio-only player UI ✅

- [x] `LessonPlayer::mount()` sets `$audioUrl` (a signed `watch.audio`
      route) only when a `ready` `lesson_audio_tracks` row exists; the
      player's own toggle button swaps `<video src>` between it and the
      normal `$streamUrl`, reusing the existing player controls
      (play/seek/heartbeat) unchanged.
- [x] New `StreamLessonAudioController`, the exact same gate/metering
      chokepoint `StreamLessonController` already is
      (`isPlayable`/`isVisibleTo`/`canStream`/`ConcurrentStreamGuard`/
      `assertWithinBandwidthQuota`) — reuses
      `StreamLessonController::bytesServed()` (made `public static`,
      it never touched `$this`) rather than a second copy of the same
      Range-parsing logic. 404s if no `ready` track exists. Records the
      audio file's own byte count, not the video's.
- [x] Tests (`LessonAudioTrackToggleTest.php`,
      `BandwidthMeteringAudioTest.php`): toggle appears only for a
      `ready` track (not `pending`, not missing), an audio-only stream
      meters at the audio file's own size, a lesson with no ready track
      404s the audio route without recording anything.

### B9 — U8: teacher lesson-plan assistant ✅

- [x] `LessonPlanAssistant` service — same `AiProvider::chat()` +
      `responseSchema: ['outline']` contract `CourseOutlineGenerator`
      uses, and **reuses `CourseOutlineDraft` itself** (no new table,
      per design doc §3.2) rather than a second draft model. Prompt
      built from the *preceding* lesson's own
      `LessonArtifact.syllabus_map` instead of a blank topic+level.
      Returns `null` (not an exception) when nothing's been extracted
      yet — same placeholder posture `DoubtChatService::ask()` already
      takes, since the only caller is a Filament action with a
      notification for either outcome.
- [x] Teach-panel entry point: `EditLesson`'s own header actions gain
      "Draft next lesson plan" (same header-`Action` shape
      `ListCourseOutlineDrafts` already uses for
      `CourseOutlineGenerator`), redirecting into the *same*
      `CourseOutlineDraftResource` review queue. **Known scope edge,
      not fixed here**: approving one of these drafts still runs
      `CourseOutlineApprovalService`, which creates a *new* `Course`
      from the outline — correct for U8's sibling, wrong framing for
      "the next lesson in an existing course." Out of scope for this
      block (not asked for); a real fast-follow would need its own
      approval path that inserts into the existing course instead.
- [x] Tests (`tests/Feature/AI/LessonPlanAssistantTest.php`): draft's AI
      prompt is grounded in the real `syllabus_map` content, `null` when
      no ready artifact exists yet, `null` when the only artifact is
      still processing.

### B10 — Z1: subscription renewal scheduled trigger ✅

- [x] `RenewStudentSubscriptions` job — same unscoped-CurrentTenant sweep
      shape `FlagOverdueInvoices` uses for the outer clear+query, plus
      `SuspendOverdueTenantSubscriptions`'s own lock-and-recheck shape
      per row (a multi-step action, not one bulk `UPDATE`). Scheduled
      daily in `bootstrap/app.php`'s `withSchedule()`. Calls the
      *existing* `StudentSubscriptionService::renew()` (S20) unchanged —
      confirmed it only creates a checkout order (`FeeInvoice`+
      `FeePayment`), it does **not** itself advance the period (that's
      `activateFromPayment()`, on actual payment capture) — so the sweep
      advances `current_period_start`/`current_period_end` itself right
      after a successful `renew()` call (reusing
      `StudentSubscriptionService::nextPeriodEnd()`, made `public`) as
      the scheduling-layer half of "a renewal happened," not new billing
      logic. That's also what makes a second sweep run idempotent — the
      row no longer matches the due-`WHERE`. Also had to rebind
      `CurrentTenant` per row before calling `renew()`:
      `FeeInvoiceService::invoiceForMembership()` resolves the billing
      tenant through `CurrentTenant`, unlike `CertificateService`'s own
      `Tenant::findOrFail()`-from-the-row shape — a real gap the block's
      own "confirm it still does the right thing under a real sweep
      call" instruction was there to catch.
- [x] Tests (`tests/Feature/Fees/RenewStudentSubscriptionsTest.php`): a
      due subscription renews (period advances, one `FeeInvoice`
      created), one not yet due is untouched, a second sweep run on an
      already-renewed row doesn't renew it again, a `cancelled`
      subscription past its period is never renewed.

### B11 — Z2: affiliate commission auto-confirmation ✅

- [x] `ConfirmCommissionOnPaymentCaptured` listener on the *existing*
      `PaymentCaptured` event (plain listener, no `ShouldQueue` — same
      shape `FinalizeEnrolmentOnPaymentCaptured`/
      `DispatchPaymentCapturedWebhook` already use on this event),
      calling `AffiliateAttributionService::confirmCommission()`.
      Confirmed by reading `PaymentReconciler::capture()`: it dispatches
      `PaymentCaptured` only from its plain-enrolment `else` branch — a
      membership invoice calls `activateFromPayment()` directly, a
      program invoice dispatches `ProgramInvoicePaid` instead — so no
      branch guard is needed inside the listener itself. Resolves the
      referral via the invoice's own payments (not the referral's stored
      `fee_payment_id`) since a retried checkout can attach a second
      `FeePayment` to the same invoice after an earlier one failed.
- [x] Tests (`tests/Feature/Growth/ConfirmCommissionOnPaymentCapturedTest.php`):
      a captured payment with a pending referral confirms its commission
      (real percentage math verified), a captured payment with no
      referral is a no-op, a membership-linked payment's real webhook
      capture (`PaymentReconciler::reconcile()`, not just a direct
      `event()` call) never dispatches `PaymentCaptured` at all.

### B12 — `TenantModelRegistry` sweep ✅

- [x] Reverse sweep: every `app/Domains/*/Models/*.php` file whose class
      body actually declares `use BelongsToTenant` (not just imports it)
      extracted via script (101 models) and diffed against
      `TenantModelRegistry::MODELS` (101 entries) — **identical sets,
      zero gaps either direction**. `AuditLogEntry`/`CookieConsent`/
      `ImpersonationAudit` don't use the trait at all (each has its own
      docblock explaining why — a pre-tenant-resolution or
      row-derived-tenant case), so their absence from the registry is
      correct, not a gap. All 3 new S22 models (`LessonPdfNote`,
      `DailyPracticeSet`, `LessonAudioTrack`) confirmed present with
      working `seedAttributes()`/`updateAttributes()` entries — the
      generated `tests/Isolation/CrossTenantTest.php` suite (102 tests)
      passes in full, proving isolation for all 101 registered models,
      not just the 3 new ones.

### B13 — Full test coverage, review pass

- [ ] Lifecycle E2E (design doc §5): live class ends → ingestion
      completes → auto-quiz exists and that day's DPP includes its
      questions; a student asks a doubt at a specific timestamp and gets
      an answer grounded in that timestamp's own chunk; a teacher opens
      the lesson-plan assistant and gets a draft grounded in the real
      `syllabus_map`; Z1/Z2 both verified end to end (a due subscription
      renews, a captured payment confirms its commission).
- [ ] Dispatch a review pass over the whole slice's diff, same discipline
      every prior slice's final block established (S21 B15's own 2-angle
      background-agent pattern is the proven approach — reuse it).
- [ ] Update `HANDOVER.md` (S22 complete entry), `docs/PROJECT-STATUS.md`
      (new S22 section), `docs/ROADMAP.md` (S22 row → Done, shipped-count
      21→22).
