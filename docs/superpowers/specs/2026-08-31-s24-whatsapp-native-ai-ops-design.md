# Design: S24 WhatsApp-Native AI & Ops

## 1. Why this document exists

`docs/ROADMAP.md`'s own proposed S24 row pins scope: 7 items, `U19-U25`,
drawn from `docs/FEATURES.md`. The row's own prose headline names 6
("WhatsApp doubt bot, voice-note doubts, photo doubt over WhatsApp,
auto-scheduler, fee analytics, peer comparison") — same headline-undercounts-
the-range pattern S16/S17/S18/S20/S22 each hit; the "Items: 7 | Draws from
U19-U25" line is authoritative, same precedent S23's own design doc
established for its own identical mismatch. All 7 build this slice, U25
(print-friendly notes export) included, none cut.

## 2. Scope

### 2.1 In scope — 7 items (U19-U25), all of them

| Item | What | Tier |
|---|---|---|
| U19 | WhatsApp doubt bot — student texts a doubt, AI answers there, grounded in their enrolled course | P2 |
| U20 | Voice-note doubts in Hinglish — student sends a voice note, transcribed + answered | P2 |
| U21 | Photo doubt over WhatsApp — extends L14 (`PhotoDoubtQuery`, S17) to the WhatsApp channel | P2 |
| U22 | Conflict-free timetable auto-scheduler — respects teacher availability, room limits, batch clashes | P3 |
| U23 | Fee analytics — collection rate, projected revenue, defaulter ageing | P2 |
| U24 | Peer comparison — "you're in the top 20% of your batch on Thermodynamics" | P2 |
| U25 | Print-friendly notes export | P3 |

### 2.2 Verified before writing this design (checked, not assumed)

- `App\Integrations\CommsProvider::sendTemplate()` (S8) is the only outbound
  WhatsApp capability that exists today — a **pre-approved template message
  only**, matching WhatsApp Business API's own rule that a business-initiated
  message outside an active customer session must be a template. There is
  **no inbound webhook anywhere in this codebase** (`grep` for
  `whatsapp.*webhook`/`WhatsAppWebhook` returns nothing) — U19-U21 all need
  one, real new surface, not a gap this design papers over.
- `App\Integrations\AiProvider` already has every AI primitive these 3 items
  need: `transcribe()` (used today for lecture ingestion, S3 — directly
  reusable for U20), `chatWithImage()` (S17 L14, directly reusable for U21),
  `chat()` (used everywhere, including `DoubtChatService::ask()`). No new
  provider method required for U19-U21.
- `DoubtChatService::ask(Lesson $lesson, User $viewer, string $question,
  ?int $atSecond = null)` (S3, extended S22) requires a `Lesson` up front —
  fine for the in-app player where the student is already looking at one,
  wrong shape for a WhatsApp message that arrives with zero lesson context.
  `SemanticSearchService::search(string $query, User $viewer, int $tenantId)`
  (S17 L12) already resolves a freeform question to the nearest matching
  `LessonArtifactChunk`s, filtered through `LessonAccess::isVisibleTo()` —
  the exact "which lesson is this even about" step U19 needs, already built,
  never wired to an entry point that doesn't already know the lesson.
- `App\Models\User` already has a `phone` column (`Fillable` list, confirmed)
  but it is **not** unique across tenants — a student enrolled at two
  different institutes on this platform could share a phone number across
  two `tenant_id`s. Every existing WhatsApp send (`AiSensyProvider`) is a
  single, platform-wide driver binding, not a per-tenant connection the way
  `InstituteZoomConnection` (S14) is — so an inbound webhook has no
  subdomain to resolve tenant from the way `ResolveTenant` does for every
  other request. This is a **real, unresolved identity-ambiguity gap**, not
  assumed away — design decision 1 below picks the pragmatic v1 answer and
  names the limitation.
- `TimetableTemplate` + `TeacherConflictDetector::wouldConflict()` (S15)
  already do 100% of the *checking* an auto-scheduler needs (one
  session-by-session overlap check); nothing in this codebase does the
  *proposing* half (assign N unscheduled batch-sessions to teacher/room
  slots with zero conflicts). U22 is a real gap on the propose side only.
- No per-institute fee analytics exists — `PlatformRevenueAnalytics` (S20)
  is Super-panel-only and aggregates *across* institutes for the platform
  operator, the opposite audience from U23 ("the institute owner's own
  dashboard"). `FeeInvoice`/`FeePayment` (S7) already carry every field a
  collection-rate/defaulter-ageing query needs (`due_date`, `status`,
  `total_amount`) — a real gap, but a pure query service over existing
  tables, same shape `PlatformRevenueAnalytics` itself already is.
- `TestSeriesRankingService::rankingsFor()` (S16) computes rank/percentile
  but only *per test series*, not per-concept — `WeakTopicDetectionService`
  (S17 L13) already has the per-concept quiz-answer aggregation U24's
  "top 20% on Thermodynamics" needs, just never turned into a percentile.
  Genuinely composing two existing services' math, not a new algorithm.
- No print stylesheet or PDF export exists for lesson notes (`LessonNote`,
  S13) or revision notes (`LessonRevisionNote`, S17) — confirmed absent from
  every `resources/views` template touching either model.

### 2.3 Out of scope, and why

- **A second, general-purpose chatbot framework.** U19-U21 are three entry
  points into the *existing* `DoubtChatService`/`PhotoDoubtQuery` answer
  engines, not a new conversational-AI subsystem — WhatsApp is a channel,
  not a new brain.
- **Per-institute WhatsApp Business connections** (the `InstituteZoomConnection`
  shape). Design decision 1 below picks a single platform-wide number for
  v1, same posture `GoogleMeetProvider` (S14) already took for Meet's own
  single-token limitation — a real, named, deferred scale concern, not
  silently built as if it doesn't exist.
- **Auto-generated timetables that write directly to `BatchSession`.**
  U22 proposes a conflict-free assignment; the admin still accepts it
  through the *existing* `BatchSessionsRelationManager` "prefill from
  template" flow (S15 B3-B4) one session at a time — no silent bulk write,
  no new undo mechanism to build.
- **A public leaderboard.** U24's own `FEATURES.md` line explicitly frames
  peer comparison as "motivation without a public leaderboard's downside" —
  a student sees their own percentile only, never classmates' names, same
  "own standing, not the roster" posture `TestSeriesResults` (S16) already
  established.
- **Rebuilding fee collection or payment logic.** U23 is read-only analytics
  over `FeeInvoice`/`FeePayment`, exactly like `PlatformRevenueAnalytics`
  before it — no new billing code path.

## 3. Architecture

### 3.1 Domain ownership — no new domain

| Domain | Items | Why |
|---|---|---|
| `AI` | U19, U20, U21 | All three are new entry points into `DoubtChatService`/`PhotoDoubtQuery`, this domain's own existing engines |
| `Batches` | U22 | `TimetableTemplate`/`TeacherConflictDetector` already live here |
| `Fees` | U23 | Reads `FeeInvoice`/`FeePayment`, this domain's own tables |
| `Analytics` | U24 | Composes `TestSeriesRankingService` (Assessments) + `WeakTopicDetectionService` (AI) the same cross-domain-model-read way `RewatchHeatmapService`/`DropOffService` already do |
| `Content` | U25 | `LessonNote`/`LessonRevisionNote` both live here |

### 3.2 New schema

```sql
-- U19/U20/U21 WhatsApp channel — extends the existing doubt-chat table,
-- no new domain/table. A message with no channel set behaves byte-identical
-- to every pre-S24 row (design decision 2).
ALTER TABLE doubt_chat_messages ADD COLUMN channel VARCHAR(20) NOT NULL DEFAULT 'web';
ALTER TABLE doubt_chat_messages ADD COLUMN source_phone VARCHAR(20) NULL;

-- U19/U20/U21 phone -> (tenant, user) resolution table. Not reusing `users.phone`
-- directly (design decision 1's real ambiguity) — this is the confirmation
-- table a student populates once, e2.g. by texting a one-time linking code
-- shown in their student portal, same "real state before trusting an
-- unauthenticated channel" shape ParentInviteCode (S8) already established.
CREATE TABLE whatsapp_links (
    id BIGINT PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    phone_e164 VARCHAR(20) NOT NULL,
    verified_at TIMESTAMP NOT NULL,
    created_at, updated_at,
    UNIQUE (phone_e164)              -- one active tenant+user per phone platform-wide (design decision 1)
);

-- U23 fee analytics — no new table (pure query service over FeeInvoice/FeePayment).
-- U24 peer comparison — no new table (pure query service, same
-- RewatchHeatmapService/DropOffService no-schema precedent).
-- U25 print export — no new table (a view + a route, same shape the existing
-- lesson-pdf-note download already takes).

-- U22 auto-scheduler proposes only, writes nothing new (design §2.3) —
-- no schema.
```

### 3.3 New `BelongsToTenant` models

`WhatsAppLink` — the only one. Registered in `TenantModelRegistry`
immediately on creation (S17's own precedent, not deferred to the
registry-sweep block).

## 4. Design decisions

1. **One platform-wide WhatsApp number for v1, with an explicit phone-link
   step, not per-institute connections.** `AiSensyProvider` is already a
   single, unscoped driver binding — building a second, per-tenant
   connection concept (`InstituteZoomConnection`'s own shape) is real scope
   this slice doesn't need to carry to close U19-U21. Because a phone number
   isn't unique across tenants on this platform, `whatsapp_links` is the
   authoritative phone → (tenant, user) map, populated once per student via
   a linking code shown in their student portal (same "real state recorded
   before the identity fully exists in the target system" shape
   `ParentInviteCode`, S8, already established for parent portal signup).
   An inbound message from an unlinked number gets one templated reply
   directing them to link first — never silently guessed against.
   **Named limitation, not silently built around:** if the platform later
   needs true per-institute WhatsApp numbers (distinct business identity per
   coaching brand), this whole table becomes redundant infrastructure — a
   real re-architecture, flagged for whoever picks that up, same posture
   `GoogleMeetProvider`'s own single-token doc comment already takes.

2. **WhatsApp doubts extend `DoubtChatMessage`, not a new conversation
   table.** `channel`/`source_phone` are the only 2 new columns; every
   existing `DoubtChatService` caller, view, and test stays byte-identical
   since both default to the pre-S24 web shape. The one new step is lesson
   resolution: `SemanticSearchService::search()` finds the nearest matching
   lesson from the freeform question text, scoped to courses the linked
   student is actually enrolled in, before calling the *existing*
   `DoubtChatService::ask($resolvedLesson, ...)` unchanged.

3. **Voice notes transcribe through the same `AiProvider::transcribe()`
   lecture-ingestion already uses, then re-enter the U19 text-doubt path
   verbatim.** No separate voice-doubt pipeline — a transcribed voice note
   is just a `DoubtChatMessage` whose `question` text happened to arrive as
   audio. Hinglish handling is a transcription-model property (same
   provider call), not new application logic — no new interface method.

4. **Photo doubts over WhatsApp create a real `PhotoDoubtQuery` row (S17),
   not a WhatsApp-only shadow record.** The inbound webhook downloads the
   image to the same disk convention `PhotoDoubtQuery::image_disk_path`
   already uses, creates the row, and the *existing* async job
   (`ProcessPhotoDoubtQuery` or equivalent, S17 L14) does the
   `chatWithImage()` call — WhatsApp is purely an alternate creation path
   into infrastructure that already fully exists, `channel`/`source_phone`
   distinguishing it from an in-app upload the same way U19's doubts are
   distinguished.

5. **The auto-scheduler proposes, an admin accepts — no silent bulk write
   (§2.3).** `AutoSchedulerService::propose(Batch $batch, array $unscheduledSessionCount)`
   returns an in-memory list of `{teacher, room, day_of_week, time}`
   candidate slots, each individually checked against
   `TeacherConflictDetector::wouldConflict()` (reused unchanged) across
   every *other* batch's sessions, not persisted. The Admin UI is a review
   list feeding the existing `BatchSessionsRelationManager` "prefill from
   template" create action (S15) once per accepted slot — reuses a proven
   write path instead of inventing a second one.

6. **Fee analytics and peer comparison are both pure, uncached query
   services — same posture `RewatchHeatmapService`/`DropOffService`/
   `PlatformRevenueAnalytics` already established for a teacher/owner-facing
   widget loaded a handful of times a day, not a hot path.** `FeeAnalyticsService`
   computes collection rate (`sum(paid) / sum(total_amount)` scoped to
   `CurrentTenant`), projected revenue (unpaid `FeeInvoice` rows with a
   future `due_date`), and defaulter ageing (days past `due_date`, bucketed
   0-30/31-60/60+, same bucketing shape `AccountSharingFlag`'s own
   threshold-based classification already uses elsewhere). `PeerComparisonService`
   reuses `WeakTopicDetectionService::classWideWeakTopicsFor()`'s own
   per-concept score aggregation, adds the same percentile-rank math
   `TestSeriesRankingService::rankingsFor()` already implements (composed,
   not duplicated — both are plain PHP-side sorts over an already-fetched
   collection, trivially reusable as a shared private method rather than
   two copies).

7. **Print export is a `@media print` stylesheet + a dedicated printable
   route, not a new PDF generator.** Unlike `CertificateService`/
   `IdCardGenerator`/`RankCardGenerator`/`HallTicketGenerator` (all
   `dompdf`-backed, all producing a single fixed-layout document), lesson
   notes are free-form, variable-length prose — a browser's own
   print-to-PDF already handles pagination correctly for that shape; a 5th
   from-scratch dompdf template would fight the browser for the same job
   worse. `resources/views/content/lesson-notes-print.blade.php` renders
   the same `LessonNote`/`LessonRevisionNote` content behind
   `LessonAccess::isVisibleTo()`, with print-only CSS hiding chrome.

## 5. Testing strategy

Same shape every S13-S23 slice used: one feature test per new service
(happy path + the one real edge case each design decision above implies —
e.g. decision 1's "unlinked phone number" reply-with-linking-prompt path;
decision 5's "proposed slot still conflicts with a batch outside the one
being scheduled" case), a `TenantModelRegistry` reverse-sweep block, and a
closing lifecycle E2E test chaining at least: a WhatsApp text doubt →
resolved to the right lesson → answered, grounded, through the real
`DoubtChatService`; a voice note → transcribed → answered through the same
path; fee analytics numbers verified against a real seeded set of paid/
unpaid/overdue invoices; a student's own percentile on a weak topic matches
hand-computed rank among real seeded classmates.
