# Implementation Plan: S24 WhatsApp-Native AI & Ops

Full design: `2026-08-31-s24-whatsapp-native-ai-ops-design.md`.
Block-by-block build log — update the PROGRESS table as each block lands,
same as every S1-S23 plan did. 15 blocks (B0-B14): 7 items (U19-U25) split
across 11 build blocks (schema/service + UI/delivery split where an item
has both), a registry sweep, a full-coverage pass, a review pass, and a
final block.

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-31-s24-whatsapp-native-ai-ops-design.md
and docs/superpowers/specs/2026-08-31-s24-whatsapp-native-ai-ops-plan.md,
then build Block 0 of the S24 plan. Update the PROGRESS table and check off
that block's tasks when done, run composer test before marking it complete,
then stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | U19 pt.1 — `doubt_chat_messages.channel`/`source_phone` + `whatsapp_links` schema/model | ✅ done |
| B1 | U19 pt.2 — inbound webhook, phone-link verification, `SemanticSearchService`-resolved lesson → `DoubtChatService::ask()` → reply | ⬜ not started |
| B2 | U20 — voice-note doubts: audio download + `AiProvider::transcribe()` → re-enters B1's text-doubt path | ⬜ not started |
| B3 | U21 — photo doubt over WhatsApp: image download → real `PhotoDoubtQuery` row → existing S17 L14 pipeline | ⬜ not started |
| B4 | U22 pt.1 — `AutoSchedulerService::propose()` (conflict-free slot proposals, reuses `TeacherConflictDetector`) | ⬜ not started |
| B5 | U22 pt.2 — Admin review UI feeding `BatchSessionsRelationManager`'s existing prefill-from-template accept flow | ⬜ not started |
| B6 | U23 pt.1 — `FeeAnalyticsService` (collection rate, projected revenue, defaulter ageing) | ⬜ not started |
| B7 | U23 pt.2 — Admin fee-analytics dashboard widget | ⬜ not started |
| B8 | U24 pt.1 — `PeerComparisonService` (per-concept percentile, composes `WeakTopicDetectionService` + `TestSeriesRankingService`'s own rank math) | ⬜ not started |
| B9 | U24 pt.2 — student-facing "you're in the top N%" widget (own standing only, no roster) | ⬜ not started |
| B10 | U25 — print-friendly lesson-notes export (`@media print` stylesheet + route, no new PDF generator) | ⬜ not started |
| B11 | `TenantModelRegistry` sweep — `WhatsAppLink` confirmed registered | ⬜ not started |
| B12 | Full test coverage pass — one feature test per new service/generator, edge cases per design §5 | ⬜ not started |
| B13 | Multi-angle review pass over the whole slice's diff (same discipline S13-S23 B-final each used) | ⬜ not started |
| B14 | Lifecycle E2E test + `PROJECT-STATUS.md`/`HANDOVER.md`/`ROADMAP.md` update | ⬜ not started |

**Test suite baseline:** carry forward S23's own closing count (2033+/2039+,
same documented pre-existing/unrelated flakes: `CourseSlugUniquenessTest`
since S1/S2, `CalendarTest` x3 in Planner, `BackupConfigurationTest` in
Support, a rotating export-controller order-flake sibling — confirm each in
isolation before treating as a regression, same posture every prior slice
established).

---

## Block detail (checklist — expand into a build narrative as each block actually lands, same as every prior plan file)

### B0 — U19 pt.1: WhatsApp channel schema ✅

- [x] Migration adds `channel` (`VARCHAR(20) DEFAULT 'web'`) and
      `source_phone` (nullable) to `doubt_chat_messages` — every existing
      row/query stays byte-identical (design §4 decision 2).
- [x] `whatsapp_links` migration (`tenant_id`, `user_id`, `phone_e164`
      unique platform-wide, `verified_at`) + `WhatsAppLink` model (AI
      domain), registered in `TenantModelRegistry` immediately (S17
      precedent, not deferred to B11).
- [x] Student-portal "Link WhatsApp" UI: generates a linking code, student
      texts it to the platform number to verify (design §4 decision 1).
- [x] Tests: linking a code creates a `whatsapp_links` row with
      `verified_at` set; a second student cannot link an already-linked
      phone number (unique constraint surfaced as a clean error, not a 500).

**Build notes:** the pending code lives in `Cache` (15 min TTL), not a DB
row — `whatsapp_links` schema per design §3.2 only ever holds *verified*
rows (`verified_at NOT NULL`), so there's no pending-state column to write
to before the phone is confirmed; a cache entry is the whole "state before
identity is confirmed" step, nothing to clean up once it expires. Built
`WhatsAppLinkService::generateCode()`/`verifyCode()` in the AI domain —
`verifyCode()` is the exact step B1's inbound webhook will call once a code
arrives over WhatsApp; this block exposes it as a directly callable method
so B0's own tests exercise it ahead of the real webhook. `verifyCode()`
rebinds `CurrentTenant` to the code's own tenant for the `WhatsAppLink::create()`
call and restores it after, same pattern `ParentInviteCodeService::redeem()`
already established for a platform-wide, no-subdomain entry point.
`WhatsAppLinkCode` Livewire component (`ai.whatsapp-link-code`) mounted on
the student dashboard, generation-only — verification/redemption UI is B1's
job once the webhook exists. `WhatsAppLink::class` needed an explicit
`protected $table = 'whatsapp_links'` — Eloquent's own inferred name from
the class (`Str::snake('WhatsAppLink')`) came out `whats_app_links`, not
`whatsapp_links`, caught by the new test's first run.
`composer test`: 2038/2043 passed, the 5 failures are the already-documented
baseline flakes (`CourseSlugUniquenessTest`, `CalendarTest` x3,
`BackupConfigurationTest`) — no new failures.

### B1 — U19 pt.2: inbound webhook + doubt routing

- [ ] Inbound WhatsApp webhook controller (platform-wide route, no tenant
      subdomain to resolve from — same `ResolveTenant`-bypass reasoning
      Livewire's own `/livewire/update` endpoint already established,
      design §2.2). Verifies the vendor's own webhook signature.
- [ ] Unlinked phone number → one templated reply directing them to link
      first (design §4 decision 1) — never guessed against.
- [ ] Linked phone + text message → `SemanticSearchService::search()`
      resolves the nearest matching lesson (scoped to the linked student's
      own enrolled courses) → `DoubtChatService::ask($resolvedLesson, ...)`
      unchanged → reply sent via `CommsProvider` (freeform session reply,
      not `sendTemplate()` — confirm/build whichever driver method the
      24-hour session window actually needs).
- [ ] `DoubtChatMessage` row written with `channel = 'whatsapp'` and
      `source_phone` set.
- [ ] Tests: linked student's text doubt resolves to the correct lesson and
      gets answered; unlinked number gets the link-prompt reply, no
      `DoubtChatMessage` row created; a question with no confident semantic
      match handled the same way the in-app fallback already is (design
      §2.2's own "falls back to whole-lesson search" precedent).

### B2 — U20: voice-note doubts

- [ ] Inbound webhook handler branches on message type: an audio message
      downloads to disk, `AiProvider::transcribe()` (the same method
      lecture ingestion already calls), transcript text re-enters B1's
      exact text-doubt path.
- [ ] Tests: a voice note produces the same `DoubtChatMessage` shape a text
      doubt would, `channel = 'whatsapp'`; a transcription failure replies
      with a "couldn't understand that, try typing" message rather than
      silently dropping the doubt.

### B3 — U21: photo doubt over WhatsApp

- [ ] Inbound webhook handler branches on an image message: downloads to
      the same disk convention `PhotoDoubtQuery::image_disk_path` already
      uses, creates a real `PhotoDoubtQuery` row (`channel`/`source_phone`
      set, same fields B0 added to `doubt_chat_messages` — confirm whether
      `PhotoDoubtQuery` needs its own migration for these 2 columns or
      shares a trait/scope with `DoubtChatMessage`), the *existing* S17
      L14 async job processes it unchanged.
- [ ] Reply sent once the job completes (poll or listener on the job's own
      completion — confirm existing job's completion signal).
- [ ] Tests: a WhatsApp-sourced photo doubt produces an identical
      `PhotoDoubtQuery` row/answer shape to an in-app upload, distinguished
      only by `channel`.

### B4 — U22 pt.1: auto-scheduler proposal engine

- [ ] `AutoSchedulerService::propose(Batch $batch, int $sessionCount):
      array` — for each of `$sessionCount` unscheduled sessions, proposes a
      `{teacher, room, day_of_week, time}` candidate, checking each against
      `TeacherConflictDetector::wouldConflict()` across every batch's real
      sessions (not persisted — design §4 decision 5, §2.3).
- [ ] Room-limit constraint: confirm how "room" is currently modeled in
      this codebase (a `BatchSession` field? Not modeled at all today?) —
      real gap-check before assuming a column exists.
- [ ] Tests: two batches sharing a teacher never get proposed overlapping
      slots; a batch with no room-limit configured (if rooms aren't
      currently modeled) documented as an accepted v1 gap, not silently
      wrong.

### B5 — U22 pt.2: admin review + accept UI

- [ ] Admin-panel action on `BatchResource`/`EditBatch`: "Auto-schedule
      remaining sessions" → lists `AutoSchedulerService::propose()`'s
      candidates, one row per proposed session, each with an "Accept"
      button that submits through the *existing*
      `BatchSessionsRelationManager` create action (prefill-from-template
      shape, S15 B3-B4) — no new persistence path.
- [ ] Tests: accepting a proposed slot creates a real `BatchSession` row
      identical in shape to one created through the pre-existing manual
      flow; rejecting/skipping a proposal writes nothing.

### B6 — U23 pt.1: fee analytics service

- [ ] `FeeAnalyticsService` (Fees domain): `collectionRate(Tenant)` (paid /
      total_amount, current period), `projectedRevenue(Tenant)` (sum of
      unpaid invoices with a future `due_date`), `defaulterAgeing(Tenant)`
      (days-past-due buckets: 0-30/31-60/60+, same bucketing shape
      `AccountSharingFlag`'s threshold classification already uses).
- [ ] Tests: collection rate matches a hand-computed value against a real
      seeded mix of paid/unpaid/partial invoices; an overdue invoice lands
      in the correct ageing bucket at the exact boundary (30/31, 60/61
      days).

### B7 — U23 pt.2: admin dashboard widget

- [ ] `FeeAnalyticsWidget` (Admin panel) surfacing all 3 `FeeAnalyticsService`
      numbers, same aggregate-only posture `InstituteOverviewWidget`/
      `BatchEngagementWidget` already establish (no per-student breakdown
      on this widget).
- [ ] Tests: widget renders correct numbers for a real seeded tenant; a
      tenant with zero invoices shows 0%/empty state, not a divide-by-zero
      crash.

### B8 — U24 pt.1: peer comparison service

- [ ] `PeerComparisonService::percentileFor(User $student, Batch $batch,
      string $concept): ?float` — reuses `WeakTopicDetectionService::classWideWeakTopicsFor()`'s
      own per-concept score aggregation for the batch, then the same
      percentile-rank math `TestSeriesRankingService::rankingsFor()`
      already implements (extract as a small shared private/static method
      rather than a 3rd copy — confirm the cleanest reuse point without
      creating a cross-domain service call, design §3.1's own domain-model-
      read precedent).
- [ ] Tests: a student's own percentile matches a hand-computed rank among
      real seeded classmates' concept scores; a concept with only one
      student enrolled returns a defined value (100th percentile, or null
      — pick one and test it), not an error.

### B9 — U24 pt.2: student-facing widget

- [ ] Widget on the student dashboard/syllabus page: "You're in the top N%
      of your batch on {concept}" for each of the student's own weak
      topics (reuses `WeakTopicDetectionService::weakTopicsFor()`'s own
      per-student list as the concept set to show percentiles for) — never
      names or ranks classmates (design §2.3).
- [ ] Tests: widget shows only the viewer's own percentile, never a
      classmate's name or score (same `assertDontSee` pattern
      `TestSeriesResults`' own test already established).

### B10 — U25: print-friendly notes export

- [ ] `resources/views/content/lesson-notes-print.blade.php` — renders a
      lesson's `LessonNote`/`LessonRevisionNote` content behind
      `LessonAccess::isVisibleTo()`, `@media print` CSS hides all
      navigation/chrome, browser's own print-to-PDF handles pagination.
- [ ] Route + a "Print notes" link on the existing lesson view.
- [ ] Tests: a viewer without stream access is denied the same way any
      other lesson-scoped page already is; the printable view renders the
      real note content for an authorized viewer.

### B11 — TenantModelRegistry sweep

- [ ] Confirm `WhatsAppLink` (the only new model this slice adds) is
      registered with `seedAttributes()`/`updateAttributes()`.
- [ ] Reverse grep every real `BelongsToTenant` usage under
      `app/Domains/*/Models/*.php` against the registry — same exact-count
      discipline every prior slice's own sweep block ran (S23's own sweep
      found the naive-grep under-count trap; reuse the corrected
      `grep -rlP "^\s+use\s+BelongsToTenant\b"` pattern directly).
- [ ] `tests/Isolation/CrossTenantTest.php` passes for the new model.

### B12 — Full test coverage pass

- [ ] One feature test per new service/generator not already covered
      inline in its own block, plus the specific edge cases design §5
      names (unlinked-phone reply path; a proposed schedule slot
      conflicting with a batch outside the one being scheduled).
- [ ] `composer test` green, baseline flakes only.

### B13 — Multi-angle review pass

- [ ] Same discipline S13-S23's own closing review block each ran:
      correctness/convention pass + security/tenant-isolation pass over
      the whole slice's diff, independently (2 background agents, same
      S20-S23 pattern). Pay particular attention to the inbound webhook's
      own signature verification and the phone→tenant identity resolution
      (design §4 decision 1's own named ambiguity) — the exact class of
      gap S23's own B20/B21 review passes found real bugs in for an
      analogous unauthenticated-actor surface.
- [ ] Fix any real bugs found; document anything confirmed-clean-not-new
      the same way every prior slice's plan file did.

### B14 — Lifecycle E2E + docs

- [ ] New `S24WhatsAppNativeAiOpsE2ETest` chaining at minimum: a linked
      student's WhatsApp text doubt → resolved to the real enrolled lesson
      → answered through the real `DoubtChatService`; a voice note →
      transcribed → answered through the same path; fee analytics numbers
      verified against a real seeded set of paid/unpaid/overdue invoices;
      a student's own percentile on a weak topic matches hand-computed
      rank among real seeded classmates.
- [ ] Update `docs/PROJECT-STATUS.md` (new S24 section), `HANDOVER.md`
      ("Right now" entry), `docs/ROADMAP.md` (S24 row → ✅ Done, shipped
      count 23→24) — same close-out shape every prior slice used.
