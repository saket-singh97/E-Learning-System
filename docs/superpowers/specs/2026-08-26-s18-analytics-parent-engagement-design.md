# Design: S18 Analytics & Parent Engagement

## 1. Why this document exists

Locks scope and architecture for S18 before any block is built, same role
every S9-S17 design doc played. Companion build log:
`2026-08-26-s18-analytics-parent-engagement-plan.md`.

`docs/ROADMAP.md`'s own S18 row (proposed, not yet locked) lists M5-M8,
M14-M15, O5-O6 — 8 items as counted there. Verified before writing this
design, not assumed from the roadmap range notation:

- **M15 ("inactivity nudges") is only partially unbuilt.** The "3-day
  inactivity" nudge already shipped in S9 B13
  (`App\Domains\Comms\Jobs\SendInactivityNudges`, confirmed by reading it).
  M15's other two nudge types — missed-class-replay, weak-topic-practice —
  are real gaps.
- **M14 and O5 are the same feature counted twice** — both read "monthly
  parent report card (PDF) on WhatsApp." One real item, not two.
- **O6 (parent-teacher message thread) is cut.** P3-tier, and the generic
  `DirectMessage` model (Comms, S13) already supports any-authenticated-
  user-to-any-authenticated-user messaging within a tenant with no role
  restriction baked into the schema — a parent can already message an
  instructor today. A dedicated "thread" UI is a real but small gap, not
  worth this slice's own scope when the roadmap's own headline doesn't
  name it.

Real build scope: **5 items**.

## 2. Scope

### 2.1 In scope — 5 items

| # | Item | Source | Phase |
|---|---|---|---|
| A1 | Engagement ratio + speed profile — watch-time-vs-wall-clock and sustained-2x-speed signals, per student per lesson | M5, M6 | P2 |
| A2 | Rewatch heatmap — which seconds of a lesson the batch replays most | M7 | P2 |
| A3 | Drop-off point per lesson — where students stop watching | M8 | P2 |
| A4 | Missed-class-replay + weak-topic-practice nudges (M15's remaining two types) | M15 | P2 |
| A5 | Monthly parent report card (PDF, delivered via WhatsApp) | M14/O5 | P2 |

All 5 are P2 — a clean phase-boundary slice, same shape S17's own.

### 2.2 Out of scope, and why

- **M6 (speed profile) is not a separate build item — bundled into A1.**
  Both read the exact same `VideoWatchEvent` rows in the exact same
  per-student-per-lesson pass; a second service computing a second metric
  off identical input data would just be `EngagementMetricsService` split
  in half for no reason.
- **O6 (parent-teacher message thread) cut — P3, not named in this
  slice's own headline**, same "not named, not in scope yet" boundary
  S16/S17 both drew. The underlying capability (parent messaging an
  instructor) already exists via `DirectMessage`; a dedicated thread UI
  is a future, separately-scoped item.
- **No new aggregation/rollup table for A1-A3.** All three are pure
  query/aggregation services over the already-existing
  `video_watch_events` table (`start_s`/`end_s` per watch session,
  populated since S2) — same "no new schema, pure query service" posture
  S17's own L12 (semantic search) and L13 (weak-topic detection) already
  established. A rollup table is a future perf optimization if this
  actually gets slow at scale, not a day-one requirement.
- **A5's WhatsApp delivery sends a signed download link, not a file
  attachment.** `CommsProvider::sendTemplate()` (confirmed by reading its
  interface) only supports pre-approved text templates with placeholder
  params — no media/document-attachment method exists. Adding one is a
  new capability with its own vendor-support questions (which
  approved-template types even support media). A signed, time-limited
  PDF download URL passed as a template param needs zero new
  `CommsProvider` plumbing and reuses the exact signed-route pattern
  `DownloadInvoiceController`/`DownloadIdCardController` already use.

### 2.3 Verified before writing this design

- **`video_watch_events` (`App\Domains\Content\Models\VideoWatchEvent`)**
  already records `user_id`, `lesson_id`, `start_s`, `end_s` per watch
  session, no `updated_at` (append-only). Every signal A1-A3 need —
  watch time, playback speed (`RecordVideoCoverageAttendance`'s own
  session-boundary logic already infers speed from wall-clock-vs-video-
  time gaps, S16 B2 precedent to reuse, not reinvent), rewatch density,
  drop-off point — is derivable from this one table.
- **`SendInactivityNudges` (S9 B13) already established the "derive last
  activity from `VideoWatchEvent`/`QuizAttempt`, dedupe via the
  `notifications` table, once-per-week" pattern** — the two new nudge
  types (A4) extend this exact shape, not invent a new one.
- **`WeakTopicDetectionService::weakTopicsFor()` (S17 B7) already answers
  "which concepts is this student weak on."** The weak-topic-practice
  nudge (A4) is a thin notification wrapper around an existing call, zero
  new detection logic.
- **`LiveRecording` (S14) already tracks a recorded live session's own
  disk path**, the missed-class-replay nudge's own trigger signal
  (a `BatchSession` that finished with a recording, cross-referenced
  against the enrolled student's own `VideoWatchEvent` rows for that
  lesson — no watch event since the recording went up means "missed and
  hasn't replayed").
- **PDF generation already has a proven pattern in this codebase** —
  `CertificateService`/`IdCardGenerator`/`ExportGradebookPdf` all use
  `barryvdh/laravel-dompdf` (confirmed in `composer.json`). A5 reuses the
  same library, not a new dependency.
- **`AttendanceRecord`/`Enrolment`/`QuizAttempt` already carry everything
  a monthly report card needs to summarize** (attendance %, quiz scores,
  now also A1's engagement ratio) — no new source-of-truth tables beyond
  the report card's own tracking row.

## 3. Architecture

### 3.1 Domain ownership — one new domain

- **`Analytics`** (new domain) — A1/A2/A3 (pure query services, no new
  models) and A5's report-card generation + PDF + tracking row. Distinct
  enough from `Content` (which owns the raw `VideoWatchEvent` signal, not
  its analysis) to warrant its own domain, same reasoning `AI` got its
  own domain despite touching `Content`/`Assessments` — this is a new
  *kind* of business concern (engagement analytics), not an extension of
  an existing one.
- **`Comms`** — A4's two new nudge jobs, same domain
  `SendInactivityNudges`/`SendLowAttendanceAlert`/`SendAbsenceAlert`
  already live in. Not a new concern, just two more notification types.

### 3.2 New schema

```
-- Analytics: A5 only — A1/A2/A3 have no new schema (pure query services)
monthly_report_cards (Analytics — A5)
  id, tenant_id, student_id, period_month (date, first-of-month — e.g.
  2026-09-01 for September), status (pending|generated|sent|failed),
  pdf_disk_path (nullable), sent_at (nullable), timestamps
  -- unique (student_id, period_month): one report card per student per
  -- month, versioned by period like LessonArtifact's own
  -- versioned-not-overwritten shape — a re-run for an already-generated
  -- month is a no-op, never a silent overwrite of a report a parent may
  -- have already received.
```

### 3.3 New `BelongsToTenant` models

`MonthlyReportCard` — **1 new model**, registered in `TenantModelRegistry`
immediately on creation (S17's own precedent — the registry's guard test
runs on every `composer test`, not deferrable to a single sweep block).

## 4. Design decisions

1. **A1's two metrics (engagement ratio, speed profile) are one service,
   `EngagementMetricsService`, not two.** Same input table, same
   per-student-per-lesson pass — splitting them would just duplicate the
   query.
2. **A1-A3 are pure query services with no new schema**, same posture
   S17's L12/L13 already established — `video_watch_events` already has
   everything needed; a rollup table is a future perf call, not a day-one
   requirement.
3. **A5's WhatsApp delivery sends a signed download URL as a template
   param, not a file attachment.** `CommsProvider::sendTemplate()` has no
   media-attachment method today; adding one is new vendor-integration
   surface this slice doesn't need — a signed route (reusing
   `DownloadInvoiceController`'s own pattern) gets a parent the PDF with
   zero new `CommsProvider` plumbing.
4. **A4's two nudge types live in `Comms`, extending
   `SendInactivityNudges`'s own established shape** (derive a trigger
   signal, dedupe via `notifications`, once-per-week cadence) — not a new
   domain concern, and not merged into the existing job (two different
   trigger conditions, two different notification classes, same "one
   listener per concern" precedent `BustAggregateCacheOnAttendance`
   already set alongside `SendAbsenceAlert`/`SendLowAttendanceAlert` on
   the same `AttendanceRecorded` event).
5. **The weak-topic-practice nudge calls
   `WeakTopicDetectionService::weakTopicsFor()` directly (cross-domain
   model/service read, `Comms` → `AI`)** — same precedent
   `Quiz::isVisibleTo()` already set reading `Content`'s models directly,
   and `RevisionNotesGenerator`'s own domain sits in `AI` already; calling
   an existing read-only detection query is not the kind of
   service-to-service coupling CLAUDE.md's events-only rule targets
   (a synchronous, on-demand read, not one domain reacting to another's
   state change).
6. **`monthly_report_cards` is versioned by `period_month`, never
   overwritten in place** — same contract `LessonArtifact.version` and
   S17's `lesson_revision_notes.source_artifact_version` already use. A
   re-run for a month already `generated`/`sent` is a no-op, so a
   scheduler retry or manual re-trigger can never double-send a parent
   the same month's report.
7. **The missed-class-replay nudge fires per `BatchSession`, not per
   lesson** — a `BatchSession` is the concrete "this class happened, here
   is its recording" event (`LiveRecording`); a lesson-level nudge would
   have no single recording to point the student at when a lesson has
   multiple live occurrences across batches.
8. **Report card generation is a scheduled monthly sweep (queued job,
   `Schedule::job()` on the same `'scheduled'` Horizon queue posture
   S9 B10 established), not triggered per-enrolment.** Every active
   student gets one row per calendar month in one pass — simpler than
   tracking each student's own enrolment anniversary, and matches "one
   monthly digest" being the actual product ask (M14's own wording).
9. **O6 is cut, not silently dropped** — `DirectMessage` already covers
   the underlying capability; a dedicated parent-teacher thread UI (read
   receipts scoped to a "thread," teacher-side inbox filtering) is
   flagged as a future, separately-scoped item once its own shape is
   clearer, same "cut but named" posture S17 gave L15.

## 5. Testing strategy

- **Unit**: `EngagementMetricsService`'s ratio/speed-profile math against
  known `VideoWatchEvent` fixtures (including the "background tab"
  detection case — engagement ratio near 0 despite a long wall-clock
  session); rewatch-heatmap bucketing; drop-off point calculation.
- **Feature, per service**: each Analytics service tested against real
  `VideoWatchEvent`/`AttendanceRecord`/`QuizAttempt` rows, same
  "no fake data source, real model fixtures" posture every prior slice's
  own service tests use.
- **Cross-tenant**: `TenantModelRegistry` gains 1 entry
  (`MonthlyReportCard`); `CrossTenantTest` auto-generates isolation
  coverage.
- **Notification dedupe regression (A4, critical)**: a student already
  nudged this week for a missed-class-replay never gets a second one the
  same week, mirroring `SendInactivityNudges`'s own already-tested
  dedupe; the weak-topic-practice nudge only fires when
  `weakTopicsFor()` actually returns a non-empty result — no nudge for a
  student with nothing weak.
- **Report-card idempotency (A5, critical)**: running the monthly
  generator twice for the same `period_month` never creates a second row
  or re-sends WhatsApp to an already-`sent` student — the load-bearing
  test this block needs, mirroring L11's own versioned-not-overwritten
  regression class.
- **Security baseline**: no `DB::raw`/`whereRaw`/`DB::statement` in any
  new `app/Domains/Analytics` file — watch for the same docblock-prose
  false-positive class that's tripped every prior slice's own review pass
  when explaining *why* a raw query was avoided.
- **End-to-end (final block)**: one lifecycle test chaining a batch of
  `VideoWatchEvent` rows → `EngagementMetricsService` → correct
  ratio/drop-off numbers; one chaining a month's worth of attendance/quiz
  data → report-card generation → PDF exists → WhatsApp send recorded,
  `status = sent`.
