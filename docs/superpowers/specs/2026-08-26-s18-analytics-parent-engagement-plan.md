# Implementation Plan: S18 Analytics & Parent Engagement

Full design: `2026-08-26-s18-analytics-parent-engagement-design.md`. This is
the block-by-block build log — update the PROGRESS table as each block
lands, same as every S1-S17 plan did. 13 blocks (B0-B12), finer-grained
than the 5-item real build scope since each analytics signal splits into
service+UI, and A4/A5 each split into their own natural sub-parts.

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-26-s18-analytics-parent-engagement-design.md and
docs/superpowers/specs/2026-08-26-s18-analytics-parent-engagement-plan.md, then build
Block 0 of the S18 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | A1 pt.1 — `EngagementMetricsService` (engagement ratio + speed profile) | ✅ done |
| B1 | A1 pt.2 — instructor/admin-facing engagement metrics view | ✅ done |
| B2 | A2 pt.1 — `RewatchHeatmapService` | ⬜ not started |
| B3 | A2 pt.2 — rewatch heatmap UI (per-lesson, instructor-facing) | ⬜ not started |
| B4 | A3 pt.1 — `DropOffService` (per-lesson drop-off point) | ⬜ not started |
| B5 | A3 pt.2 — drop-off UI (per-lesson, instructor-facing) | ⬜ not started |
| B6 | A4 pt.1 — missed-class-replay nudge | ⬜ not started |
| B7 | A4 pt.2 — weak-topic-practice nudge (reuses S17 `WeakTopicDetectionService`) | ⬜ not started |
| B8 | A5 pt.1 — `monthly_report_cards` schema + data aggregator | ⬜ not started |
| B9 | A5 pt.2 — PDF generation | ⬜ not started |
| B10 | A5 pt.3 — WhatsApp delivery (signed URL) + scheduled monthly sweep | ⬜ not started |
| B11 | `TenantModelRegistry` sweep — 1 new model registered | ⬜ not started |
| B12 | Full test coverage, review pass, `PROJECT-STATUS.md`/`HANDOVER.md`/`ROADMAP.md` update | ⬜ not started |

---

## Block detail (expand as each block builds; stubs below are the starting checklist)

### B0 — A1 pt.1: `EngagementMetricsService` — done

- `app/Domains/Analytics/Services/EngagementMetricsService.php` created —
  new `Analytics` domain, `Services/` only so far (design §3.1).
- `ratioFor(User $student, Lesson $lesson): float` — merges overlapping
  `VideoWatchEvent` intervals (same merge shape as
  `RecordVideoCoverageAttendance::mergedCoveredSeconds`, duplicated
  locally rather than cross-domain-called since that method lives on a
  Batches Job class, not a shared service), divides by
  `video_duration_seconds`, capped at 1.0.
- `speedProfileFor(User $student, Lesson $lesson): array{sustained_2x,
  fast_heartbeat_count, total_heartbeats}` — **correction found during
  build**: there is no wall-clock column to reuse from
  `RecordVideoCoverageAttendance` (it only merges video-time intervals,
  no speed logic exists there). Real signal used instead: the lesson
  player heartbeats on a fixed `setInterval(10000)` cadence
  (`lesson-player.blade.php`), so each `VideoWatchEvent`'s own
  `(end_s - start_s) / 10` is that heartbeat's implied playback speed.
  `sustained_2x` requires >= 3 heartbeats at >= 1.75x, filtering out a
  one-off forward-skip (indistinguishable from a speed change on a
  single event).
- No new schema (confirmed, design §2.2/§4 decision 2).
- Tests: `tests/Feature/Analytics/EngagementMetricsServiceTest.php`, 8
  cases — full watch ~1.0, background-tab session <0.05, rewatch capped
  at 1.0, no-duration-yet returns 0.0 not error, sustained 2x true/false,
  single fast heartbeat not flagged as sustained, cross-student isolation
  (own events only, no tenant scope needed beyond `BelongsToTenant`
  default). All 8 pass.
- `composer test` (full `php artisan test`, config cache cleared first):
  1573/1575 pass. 2 failures, both pre-existing/unrelated to this block
  — `CourseSlugUniquenessTest` (known flake, tolerated per this plan's own
  B12 note) and `BackupConfigurationTest` (zip-size assertion, untouched
  by Analytics work).

### B1 — A1 pt.2: engagement metrics view — done

- `EngagementMetricsService::classEngagementFor(Batch $batch): array{avg_ratio,
  sustained_2x_count, total_students, sample_size}` added — loops every
  (actively-enrolled student, video lesson) pair in the batch's course
  that has at least one `VideoWatchEvent`, same per-pair loop shape
  `WeakTopicDetectionService::classWideWeakTopicsFor()` already uses.
  `sample_size` (pairs with real watch data) is distinct from
  `total_students` so the widget can tell "no one enrolled" from
  "enrolled but nothing watched yet" apart.
- `app/Filament/Teach/Widgets/BatchEngagementWidget.php` created — same
  `StatsOverviewWidget` + `getFooterWidgets()` wiring as
  `BatchWeakTopicsWidget` (S17 B9), added alongside it on `EditBatch`.
- Tests: `tests/Feature/Analytics/BatchEngagementWidgetTest.php`, 6 cases
  — correct avg ratio across 2 students, sustained_2x flagged correctly,
  zeroed (not error) result for enrolled-but-no-watch-data, zero totals
  for no active enrolments, widget empty state ("No watch data yet"),
  widget shows real numbers once data exists. All 6 pass.
- `composer test` full suite: 1579/1581 pass. Same 2 pre-existing/
  unrelated failures as B0 (`CourseSlugUniquenessTest`,
  `BackupConfigurationTest`).

### B2 — A2 pt.1: `RewatchHeatmapService` — not started

- `RewatchHeatmapService::heatmapFor(Lesson $lesson): Collection` —
  buckets every `VideoWatchEvent` row for the lesson into fixed-width
  time buckets (e.g. 10s), counts distinct students whose
  `[start_s, end_s]` overlaps each bucket, returns buckets sorted by
  replay count desc.
- Tests: a lesson with a heavily-rewatched 90s window ranks that window's
  buckets highest; a lesson with no watch events returns an empty
  collection, not an error.

### B3 — A2 pt.2: rewatch heatmap UI — not started

- Instructor-facing view on the lesson's own AI-content/artifact page
  (`LessonResource`'s `ViewLessonArtifact` — confirm the closest existing
  embed point during build) showing the top-N most-replayed windows with
  timestamps.
- Tests: shows the correct top windows in the correct order.

### B4 — A3 pt.1: `DropOffService` — not started

- `DropOffService::dropOffPointFor(Lesson $lesson): ?int` — the
  video-time second where the largest share of students' own last watch
  event ends, before the lesson's own total duration (a genuine
  "stopped watching here," not just "finished the video").
- Tests: a lesson where most students stop at ~120s reports that point; a
  lesson where everyone finishes reports `null` (no meaningful drop-off).

### B5 — A3 pt.2: drop-off UI — not started

- Same embed point as B3's heatmap (confirm whether they share one view
  or are separate sections on the same page during build) — shows the
  drop-off point as a percentage of runtime + student count affected.
- Tests: shows the correct drop-off point and percentage.

### B6 — A4 pt.1: missed-class-replay nudge — not started

- New job in `Comms`, same shape `SendInactivityNudges` already
  established: find `BatchSession` rows with a `LiveRecording` (S14)
  whose enrolled students have no matching `VideoWatchEvent` for that
  session's own lesson since the recording went up. Dedupe via
  `notifications`, once-per-week cadence (design §4 decision 4/7).
- Tests: a student who watched the replay is never nudged; a student who
  hasn't is nudged once, not again within the same week.

### B7 — A4 pt.2: weak-topic-practice nudge — not started

- New job in `Comms` calling `WeakTopicDetectionService::weakTopicsFor()`
  (S17) directly per actively-enrolled student — a non-empty weak-topics
  result triggers a nudge pointing at the linked lesson(s); an empty
  result is silently skipped, not nudged. Same dedupe/cadence shape as B6.
- Tests: a student with a real weak topic gets nudged with the correct
  lesson link; a student with none is skipped; already-nudged-this-week
  is never nudged twice.

### B8 — A5 pt.1: `monthly_report_cards` schema + data aggregator — not started

- `monthly_report_cards` migration (design §3.2, unique on
  `[student_id, period_month]`) + `MonthlyReportCard` model.
- `ReportCardDataAggregator::summarize(User $student, CarbonInterface
  $periodMonth): array` — attendance % (`AttendanceRecord`), quiz
  average (`QuizAttempt`), engagement ratio average (B0's own service) —
  all for that calendar month only.
- Tests: aggregator produces correct numbers from real fixture data
  across the three sources; a student with zero activity that month
  returns zeroed-out (not null/error) values.

### B9 — A5 pt.2: PDF generation — not started

- `ReportCardPdfGenerator::generate(MonthlyReportCard $card, array
  $summary): string` (returns disk path) — reuses `barryvdh/laravel-
  dompdf`, same library `CertificateService`/`IdCardGenerator` already
  use, not a new dependency.
- Tests: generates a real PDF file on disk containing the student's own
  numbers (parse-and-assert on rendered text, same pattern
  `CertificateService`'s own tests likely already use — confirm during
  build).

### B10 — A5 pt.3: WhatsApp delivery + scheduled sweep — not started

- Signed, time-limited download route for the PDF (same pattern
  `DownloadInvoiceController` already uses) — the URL becomes a
  `CommsProvider::sendTemplate()` param, no new `CommsProvider` method
  (design §4 decision 3).
- `GenerateMonthlyReportCards` — scheduled monthly job (`Schedule::job()`
  on the `'scheduled'` Horizon queue, same S9 B10 posture), one row per
  active student per calendar month, idempotent on
  `[student_id, period_month]` (design §4 decision 6/8) — never
  regenerates or re-sends an already-`sent` row.
- Tests: running the sweep twice for the same month never creates a
  second row or re-sends WhatsApp to an already-`sent` student — the
  load-bearing idempotency test.

### B11 — `TenantModelRegistry` sweep — not started

- Register `MonthlyReportCard` in `TenantModelRegistry::MODELS` with
  `seedAttributes()`/`updateAttributes()` entries — register immediately
  when B8 builds it, not deferred here (S17's own precedent: the
  registry's guard test runs on every `composer test`). This block is
  the reverse-check confirmation sweep, same shape S17 B12 ran.
- Grep sweep for any `BelongsToTenant` usage not yet in the registry.

### B12 — Full test coverage, review pass — not started

- Lifecycle E2E test(s): a batch of `VideoWatchEvent` rows → engagement
  ratio/rewatch heatmap/drop-off point all compute correctly from the
  same underlying data; a month's worth of attendance/quiz/engagement
  data → report-card generation → PDF exists on disk → WhatsApp send
  recorded, `status = sent`.
- Review pass across every B0-B11 file for real bugs (not just a green
  suite) — same discipline every prior slice's own final block applied.
  Particular attention to A4's dedupe logic (a nudge sent twice in one
  week is a real user-facing annoyance, same class of bug this project's
  own `SendInactivityNudges` already had to get right) and A5's
  idempotency (a re-sent report card is a real "why did I get this
  twice" support ticket).
- `composer test` full suite green (only the pre-existing unrelated
  `CourseSlugUniquenessTest` failure tolerated).
- Update `HANDOVER.md` (S18 complete entry), `docs/PROJECT-STATUS.md` (new
  S18 section), `docs/ROADMAP.md` (S18 → Done, shipped count 17→18).
