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
| B1 | U19 pt.2 — inbound webhook, phone-link verification, `SemanticSearchService`-resolved lesson → `DoubtChatService::ask()` → reply | ✅ done |
| B2 | U20 — voice-note doubts: audio download + `AiProvider::transcribe()` → re-enters B1's text-doubt path | ✅ done |
| B3 | U21 — photo doubt over WhatsApp: image download → real `PhotoDoubtQuery` row → existing S17 L14 pipeline | ✅ done |
| B4 | U22 pt.1 — `AutoSchedulerService::propose()` (conflict-free slot proposals, reuses `TeacherConflictDetector`) | ✅ done |
| B5 | U22 pt.2 — Admin review UI feeding `BatchSessionsRelationManager`'s existing prefill-from-template accept flow | ✅ done |
| B6 | U23 pt.1 — `FeeAnalyticsService` (collection rate, projected revenue, defaulter ageing) | ✅ done |
| B7 | U23 pt.2 — Admin fee-analytics dashboard widget | ✅ done |
| B8 | U24 pt.1 — `PeerComparisonService` (per-concept percentile, composes `WeakTopicDetectionService` + `TestSeriesRankingService`'s own rank math) | ✅ done |
| B9 | U24 pt.2 — student-facing "you're in the top N%" widget (own standing only, no roster) | ✅ done |
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

### B1 — U19 pt.2: inbound webhook + doubt routing ✅

- [x] Inbound WhatsApp webhook controller (platform-wide route, no tenant
      subdomain to resolve from — same `ResolveTenant`-bypass reasoning
      Livewire's own `/livewire/update` endpoint already established,
      design §2.2). Verifies the vendor's own webhook signature.
- [x] Unlinked phone number → one templated reply directing them to link
      first (design §4 decision 1) — never guessed against.
- [x] Linked phone + text message → `SemanticSearchService::search()`
      resolves the nearest matching lesson (scoped to the linked student's
      own enrolled courses) → `DoubtChatService::ask($resolvedLesson, ...)`
      unchanged → reply sent via `CommsProvider` (freeform session reply,
      not `sendTemplate()` — confirm/build whichever driver method the
      24-hour session window actually needs).
- [x] `DoubtChatMessage` row written with `channel = 'whatsapp'` and
      `source_phone` set.
- [x] Tests: linked student's text doubt resolves to the correct lesson and
      gets answered; unlinked number gets the link-prompt reply, no
      `DoubtChatMessage` row created; a question with no confident semantic
      match handled the same way the in-app fallback already is (design
      §2.2's own "falls back to whole-lesson search" precedent).

**Build notes:** route lives in `routes/api.php` (`POST /webhooks/whatsapp`),
same host-agnostic convention every other vendor webhook there already
uses (Razorpay/Zoom/Device) — no `Route::domain(...)` constraint.
`WhatsAppWebhookController` stays thin (verify + delegate + `response('ok')`),
same shape as `RazorpayWebhookController`; all real logic lives in the new
`WhatsAppInboundMessageHandler` (AI domain). Signature check is a plain
`hash_hmac('sha256', $payload, config('services.aisensy.webhook_secret'))`
compared with `hash_equals()` against the `X-Webhook-Signature` header —
same shape `RazorpayProvider::verifyWebhookSignature()` already uses, kept
local to the handler rather than added to `CommsProvider` since it's a
webhook-only concern, not part of the send-side contract. Reused the
already-generic `InvalidWebhookSignatureException` (renders 400) rather
than adding a WhatsApp-specific one. `CommsProvider` gained
`sendSessionMessage()` (freeform reply, no template) — implemented on
`AiSensyProvider`, `FakeWhatsAppDriver`, and the
`CircuitBreakerGuardedCommsDriver` decorator; `CommsProvider` is still
bound to the Fake by default (AiSensy stays unbound/real-driver-ready but
inactive, same as before this block). `DoubtChatService::ask()` itself
stays byte-identical (design §4 decision 2) — `channel`/`source_phone` are
set with one follow-up `->update()` on the row it returns, not a new
`ask()` param. An inbound "LINK <code>" text message routes to
`WhatsAppLinkService::verifyCode()` (built in B0) rather than duplicating
that logic — this is the "phone-link verification" the PROGRESS row names,
wired here since B0 only exposed the method, not an entry point that calls
it. A message with no confident semantic match (empty
`SemanticSearchService::search()` result) gets a clear "couldn't find a
matching lesson" reply and writes no `DoubtChatMessage` row, same
never-silently-drop posture the unlinked-number path already takes.
`composer test`: 2042/2048 passed; the 6 failures are all pre-existing
baseline flakes (`CourseSlugUniquenessTest`, `CalendarTest` x3,
`BackupConfigurationTest`, and this run's own rotating
`DownloadEnrolmentExportControllerTest` order-flake — confirmed passing
in isolation, matching the plan's own documented flake list above) — no
new failures.

### B2 — U20: voice-note doubts ✅

- [x] Inbound webhook handler branches on message type: an audio message
      downloads to disk, `AiProvider::transcribe()` (the same method
      lecture ingestion already calls), transcript text re-enters B1's
      exact text-doubt path.
- [x] Tests: a voice note produces the same `DoubtChatMessage` shape a text
      doubt would, `channel = 'whatsapp'`; a transcription failure replies
      with a "couldn't understand that, try typing" message rather than
      silently dropping the doubt.

**Build notes:** `WhatsAppInboundMessageHandler::handle()` gained an
`audio` branch alongside `text` — downloads the media URL via `Http::get()`
(same fetch-then-`Storage::put()` shape `DownloadLiveRecording` already
uses for Zoom), disk path keyed by phone number
(`whatsapp-audio/{phone}/{uniqid}.ogg`) since the tenant isn't known until
`handleDoubt()` resolves the `WhatsAppLink` — same reasoning the text path
already has for deferring tenant rebind. `AiProvider::transcribe()` runs
against that path; the transcript then calls `handleDoubt()` directly, so
every downstream step (link lookup, semantic search, `DoubtChatService::ask()`,
`channel`/`source_phone` tagging, reply) is the exact same code path a
text doubt already takes — no duplicated logic. A download or transcription
failure (caught as `Throwable`, same generic-catch posture
`TranscribeLesson`'s own ingestion job already takes) replies "couldn't
understand that... try typing" and returns before anything is written, same
never-silently-drop posture the rest of this handler already established.
`composer test`: 2043/2050 passed; the 7 failures are all pre-existing
baseline flakes (`CourseSlugUniquenessTest`, `CalendarTest` x3,
`BackupConfigurationTest`, and this run's own rotating export-controller
order-flake — 2 of its siblings this time, both confirmed passing in
isolation) — no new failures.

### B3 — U21: photo doubt over WhatsApp ✅

- [x] Inbound webhook handler branches on an image message: downloads to
      the same disk convention `PhotoDoubtQuery::image_disk_path` already
      uses, creates a real `PhotoDoubtQuery` row (`channel`/`source_phone`
      set, same fields B0 added to `doubt_chat_messages` — confirm whether
      `PhotoDoubtQuery` needs its own migration for these 2 columns or
      shares a trait/scope with `DoubtChatMessage`), the *existing* S17
      L14 async job processes it unchanged.
- [x] Reply sent once the job completes (poll or listener on the job's own
      completion — confirm existing job's completion signal).
- [x] Tests: a WhatsApp-sourced photo doubt produces an identical
      `PhotoDoubtQuery` row/answer shape to an in-app upload, distinguished
      only by `channel`.

**Build notes:** `PhotoDoubtQuery` needed its own migration for
`channel`/`source_phone` — confirmed it does **not** share a trait/scope
with `DoubtChatMessage` (each has its own fillable/migration; both now
carry the same 2 columns independently, same shape, no shared trait
introduced for 2 columns on 2 models). `WhatsAppInboundMessageHandler`
gained a `handleImage()` branch: resolves the `WhatsAppLink` first (needed
to rebind tenant before validating/storing, unlike audio which is
tenant-agnostic), then re-validates against `PhotoDoubtService::ALLOWED_EXTENSIONS`/
`MAX_SIZE_KB` and stores to the exact same `photo-doubts/{tenant}/{student}/{uniqid}.ext`
convention `PhotoDoubtService::submit()` uses — creates a real
`PhotoDoubtQuery` row and dispatches the *existing*, unmodified
`ProcessPhotoDoubtQuery` job. Confirmed the job's completion signal: there
is no event/broadcast anywhere in this codebase for a finished
`PhotoDoubtQuery` (the in-app UI just `wire:poll`s and re-queries) — so the
job itself is the only place that knows the terminal state, and
`ProcessPhotoDoubtQuery::handle()` now takes `CommsProvider` and calls a
`notifyWhatsApp()` step at the end that no-ops for `channel !== 'whatsapp'`
(byte-identical behavior for every existing in-app upload). No separate
immediate "got it" ack — one reply, sent once, matching the plan's own
"reply sent once the job completes" wording and the single-reply shape the
text/audio paths already have. Extracted a small `runAsTenant()` helper in
the handler (3rd use of the same bind/restore block that was previously
duplicated inline) rather than adding a 3rd copy. Fixed 2 pre-existing
`PhotoDoubtServiceTest` call sites that invoked `ProcessPhotoDoubtQuery::handle()`
directly with only 1 arg — a real regression from the signature change,
caught by `composer test`, not a pre-existing flake.
`composer test`: 2045/2052 passed; all 7 failures are pre-existing baseline
flakes (`CourseSlugUniquenessTest`, `CalendarTest` x3,
`BackupConfigurationTest`, both rotating export-controller order-flake
siblings) — no new failures.

### B4 — U22 pt.1: auto-scheduler proposal engine ✅

- [x] `AutoSchedulerService::propose(Batch $batch, int $sessionCount):
      array` — for each of `$sessionCount` unscheduled sessions, proposes a
      `{teacher, room, day_of_week, time}` candidate, checking each against
      `TeacherConflictDetector::wouldConflict()` across every batch's real
      sessions (not persisted — design §4 decision 5, §2.3).
- [x] Room-limit constraint: confirm how "room" is currently modeled in
      this codebase (a `BatchSession` field? Not modeled at all today?) —
      real gap-check before assuming a column exists.
- [x] Tests: two batches sharing a teacher never get proposed overlapping
      slots; a batch with no room-limit configured (if rooms aren't
      currently modeled) documented as an accepted v1 gap, not silently
      wrong.

**Build notes:** confirmed rooms are genuinely unmodeled — grepping the
whole Batches domain (models + migrations) found no physical-room/location
column or model anywhere; `BatchSession`'s only "room" fields
(`room_created_at`, `createLiveRoom()`) are the virtual live-class meeting
room, an unrelated concept. Every proposed candidate carries `room => null`
always — the accepted v1 gap, documented in the service's own docblock
rather than silently dropped from the candidate shape. `propose()` cycles
through the tenant's own `TimetableTemplate` rows (the only reusable
weekly-slot shape this codebase has — the same source
`BatchSessionsRelationManager`'s "prefill from template" field already
reads), computing each template's next calendar occurrence the identical
way that relation manager's own `afterStateUpdated()` callback does
(`Carbon::now()->next($day)->setTimeFrom($time)`), advancing a template's
cursor by a week each time it's used or found conflicting — bounded to 8
attempts per template so a fully-booked teacher returns fewer than
`$sessionCount` candidates rather than looping forever. `teacher` is
always `$batch->instructor_id` (confirmed `BatchSession` has no
teacher/instructor column of its own — the single-instructor-per-batch
model this whole domain already has). Added a small in-memory overlap
check for candidates proposed within the *same* call, since
`TeacherConflictDetector::wouldConflict()` only sees persisted rows and
can't catch two of this call's own not-yet-saved proposals colliding with
each other. `composer test`: 2050/2055 passed; the 5 failures are all
pre-existing baseline flakes (`CourseSlugUniquenessTest`, `CalendarTest`
x3, `BackupConfigurationTest`) — no new failures.

### B5 — U22 pt.2: admin review + accept UI ✅

- [x] Admin-panel action on `BatchResource`/`EditBatch`: "Auto-schedule
      remaining sessions" → lists `AutoSchedulerService::propose()`'s
      candidates, one row per proposed session, each with an "Accept"
      button that submits through the *existing*
      `BatchSessionsRelationManager` create action (prefill-from-template
      shape, S15 B3-B4) — no new persistence path.
- [x] Tests: accepting a proposed slot creates a real `BatchSession` row
      identical in shape to one created through the pre-existing manual
      flow; rejecting/skipping a proposal writes nothing.

**Build notes:** built as `BatchResource::autoScheduleAction()`, a table
row action alongside the existing `geofenceAction()` (same admin-panel-only
exclusion from the `teach` panel's action array, same `fillForm`/`schema`/
`action` shape) — no dedicated `EditBatch` header action needed since this
codebase's own "small modal on the resource" precedent already lives at
this exact level. No prior precedent existed anywhere in this Filament
admin for a computed (non-Eloquent) candidate list reviewed inside a
modal, so this is the first: a `Repeater` fed `AutoSchedulerService::propose()`'s
output (mapped to plain arrays via a `proposalCandidates()` helper),
each item a per-row `Toggle` (default checked) plus the slot's own
read-only `Placeholder` display — changing the `session_count` field
(`->live()`) recomputes the repeater's candidates. `->action()` loops the
submitted candidates and creates a `BatchSession` per accepted one via the
exact same `BatchSession::create()` + `position = max('position') + 1`
shape `BatchSessionsRelationManager`'s own `CreateAction::make()->mutateFormDataUsing()`
already uses — a skipped/unchecked candidate is simply never created, no
new persistence path. The action itself is hidden entirely when the
tenant has no `TimetableTemplate` rows (nothing `propose()` could ever
return, same `AutoSchedulerService`-confirmed empty-state from B4).
`composer test`: 2050/2057 passed; the 7 failures are all pre-existing
baseline flakes (`CourseSlugUniquenessTest`, `CalendarTest` x3,
`BackupConfigurationTest`, both rotating export-controller order-flake
siblings) — no new failures.

### B6 — U23 pt.1: fee analytics service ✅

- [x] `FeeAnalyticsService` (Fees domain): `collectionRate(Tenant)` (paid /
      total_amount, current period), `projectedRevenue(Tenant)` (sum of
      unpaid invoices with a future `due_date`), `defaulterAgeing(Tenant)`
      (days-past-due buckets: 0-30/31-60/60+, same bucketing shape
      `AccountSharingFlag`'s threshold classification already uses).
- [x] Tests: collection rate matches a hand-computed value against a real
      seeded mix of paid/unpaid/partial invoices; an overdue invoice lands
      in the correct ageing bucket at the exact boundary (30/31, 60/61
      days).

**Build notes:** two of the plan's own named assumptions didn't hold,
confirmed rather than guessed: `FeeInvoice::STATUSES` is
`['pending', 'paid', 'overdue', 'cancelled']` — there is no `'partial'`
status anywhere in this codebase, and no billing-period/cycle column on
`FeeInvoice` either, so "current period" (collection rate) covers every
non-cancelled invoice for the tenant, not a narrowed period — an accepted
v1 scope, not a silently wrong filter. `AccountSharingFlag`'s own
"threshold classification" is a single binary threshold
(`distinctCount &lt; $threshold`), not a 0-30/31-60/60+ split — no bucketing
precedent existed anywhere to reuse, so `defaulterAgeing()` computes its
own three buckets directly from `due_date`, not from `FlagOverdueInvoices`'
`status = 'overdue'` flip (a `pending` row whose due date has already
passed is just as much a defaulter as an already-flagged one, and
shouldn't wait on that sweep job to have run). Followed the established
`SeatQuota`/`StorageQuota` convention for a service taking an explicit
`Tenant $tenant` argument: `withoutGlobalScope('tenant')` plus an explicit
`tenant_id` filter, never trusting ambient `CurrentTenant` to already
match the argument. Same plain-array/float return-shape convention
`PlatformRevenueAnalytics` (Billing domain) already established — no DTO
classes. `composer test`: 2054/2061 passed; the 7 failures are all
pre-existing baseline flakes (`CourseSlugUniquenessTest`, `CalendarTest`
x3, `BackupConfigurationTest`, both rotating export-controller
order-flake siblings) — no new failures.

### B7 — U23 pt.2: admin dashboard widget ✅

- [x] `FeeAnalyticsWidget` (Admin panel) surfacing all 3 `FeeAnalyticsService`
      numbers, same aggregate-only posture `InstituteOverviewWidget`/
      `BatchEngagementWidget` already establish (no per-student breakdown
      on this widget).
- [x] Tests: widget renders correct numbers for a real seeded tenant; a
      tenant with zero invoices shows 0%/empty state, not a divide-by-zero
      crash.

**Build notes:** `app/Filament/Admin/Widgets/FeeAnalyticsWidget.php` —
same directory `InstituteOverviewWidget` already lives in, so it's
auto-discovered by `AdminPanelProvider`'s `discoverWidgets()`, no explicit
`->widgets([...])` entry needed. Confirmed this codebase doesn't use
Filament's own multi-tenancy feature at all (`Filament::getTenant()` has
zero matches anywhere) — tenant access is `app(CurrentTenant::class)`,
same as every other widget/Livewire component. Unlike
`BatchEngagementWidget`'s two-tier empty-state text swap (needed because
*that* service's own ratios could divide by zero), `FeeAnalyticsService`'s
3 numbers are already divide-by-zero-safe at the service layer (B6) — a
zero-invoice tenant just renders 0%/₹0.00/0 defaulters through the exact
same `Stat::make()` calls, no special-cased branch needed in the widget
itself. `composer test`: 2056/2063 passed; the 7 failures are all
pre-existing baseline flakes (`CourseSlugUniquenessTest`, `CalendarTest`
x3, `BackupConfigurationTest`, both rotating export-controller
order-flake siblings) — no new failures. This closes out U23.

### B8 — U24 pt.1: peer comparison service ✅

- [x] `PeerComparisonService::percentileFor(User $student, Batch $batch,
      string $concept): ?float` — reuses `WeakTopicDetectionService::classWideWeakTopicsFor()`'s
      own per-concept score aggregation for the batch, then the same
      percentile-rank math `TestSeriesRankingService::rankingsFor()`
      already implements (extract as a small shared private/static method
      rather than a 3rd copy — confirm the cleanest reuse point without
      creating a cross-domain service call, design §3.1's own domain-model-
      read precedent).
- [x] Tests: a student's own percentile matches a hand-computed rank among
      real seeded classmates' concept scores; a concept with only one
      student enrolled returns a defined value (100th percentile, or null
      — pick one and test it), not an error.

**Build notes:** the plan's own named reuse target didn't quite hold, checked
rather than assumed — `WeakTopicDetectionService::classWideWeakTopicsFor()`
only returns *weak* (ratio &lt; 0.6) concepts aggregated across the whole
class, never a per-student raw score for an arbitrary concept, so it isn't
actually callable from a percentile method; `PeerComparisonService`
re-derives the same per-student per-concept ratio
`weakTopicsFor()` computes (concept normalized in PHP after fetching, same
reasoning as that method's own `normalize()`), unfiltered, reading
`QuizAnswer`/`Question` (Assessments) directly — the genuine
`RewatchHeatmapService`/`DropOffService` cross-domain-*model*-read shape,
never a call into another domain's service class. The percentile math
itself *is* the one piece that's truly shared, so that's what got
extracted: `App\Support\Analytics\PercentileRank::of()`, a new stateless
`Support/` helper (CLAUDE.md's own "shared cross-domain code lives outside
Domains/" rule) — `TestSeriesRankingService::rankingsFor()` was refactored
to call it too, replacing its own inline formula, so there's exactly one
copy of the "beat X%" math in the codebase now, not two. Chose **null**
(not 100) for "not enough peers to compare against" — covers both a
concept the student never answered and a concept only one student's data
exists for (batch of one, or a lone answerer), since a percentile is
genuinely undefined with no peer group, and 0.0 (the formula's own natural
output for "beat nobody") would misleadingly read as "you're at the
bottom" rather than "no ranking is possible yet." `composer test`:
2061/2066 passed (the `TestSeriesRankingService` refactor confirmed
non-regressive via its own full test file, plus the wider suite); the 5
failures are all pre-existing baseline flakes (`CourseSlugUniquenessTest`,
`CalendarTest` x3, `BackupConfigurationTest`) — no new failures.

### B9 — U24 pt.2: student-facing widget ✅

- [x] Widget on the student dashboard/syllabus page: "You're in the top N%
      of your batch on {concept}" for each of the student's own weak
      topics (reuses `WeakTopicDetectionService::weakTopicsFor()`'s own
      per-student list as the concept set to show percentiles for) — never
      names or ranks classmates (design §2.3).
- [x] Tests: widget shows only the viewer's own percentile, never a
      classmate's name or score (same `assertDontSee` pattern
      `TestSeriesResults`' own test already established).

**Build notes:** `PeerComparisonWidget` (`App\Domains\Analytics\Livewire`,
tag `analytics.peer-comparison-widget`) embeds on `content/syllabus.blade.php`
right below `ai.weak-topics` — the exact same course-scoped page, same
`mount(Course $course)` shape, since the concept set literally comes from
that same `weakTopicsFor()` call. `PeerComparisonService::percentileFor()`
(B8) returns "beat X%"; the widget displays the complement,
`100 - percentile`, as "top N%". No existing helper resolves "which batch
is this student active in for this course" (confirmed — every caller
inlines the `Enrolment::where('user_id',...)->whereHas('batch', fn ($q) =>
$q->where('course_id', ...))` query, same shape `LessonAccess`'s own two
gate methods already use), so the widget does the same inline lookup
rather than inventing a shared helper for a single caller. A concept
`percentileFor()` returns `null` for (no peer group, B8's own contract) is
simply omitted from the list, not shown as an error. Structurally
guaranteed to never leak a classmate's name/score, same as `WeakTopics`/
`TestSeriesResults` before it — every underlying call
(`weakTopicsFor()`/`percentileFor()`) is already scoped to the viewer, so
there's no classmate row this component could even render.
`composer test`: 2064/2068 passed; the 4 failures are all pre-existing
baseline flakes (`CourseSlugUniquenessTest`, `BackupConfigurationTest`,
both rotating export-controller order-flake siblings) — no new failures.
This closes out U24.

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
