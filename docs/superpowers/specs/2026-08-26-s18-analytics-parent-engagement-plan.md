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
| B2 | A2 pt.1 — `RewatchHeatmapService` | ✅ done |
| B3 | A2 pt.2 — rewatch heatmap UI (per-lesson, instructor-facing) | ✅ done |
| B4 | A3 pt.1 — `DropOffService` (per-lesson drop-off point) | ✅ done |
| B5 | A3 pt.2 — drop-off UI (per-lesson, instructor-facing) | ✅ done |
| B6 | A4 pt.1 — missed-class-replay nudge | ✅ done |
| B7 | A4 pt.2 — weak-topic-practice nudge (reuses S17 `WeakTopicDetectionService`) | ✅ done |
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

### B2 — A2 pt.1: `RewatchHeatmapService` — done

- `app/Domains/Analytics/Services/RewatchHeatmapService.php` created —
  `heatmapFor(Lesson $lesson): Collection` buckets every
  `VideoWatchEvent` into fixed 10s windows (`BUCKET_WIDTH_S`, same width
  as the player's own heartbeat cadence), counts distinct students whose
  `[start_s, end_s]` overlaps each bucket (`end_s` exclusive — an event
  ending exactly on a bucket boundary never spills into the next one),
  sorted by `replay_count` desc.
- Tests: `tests/Feature/Analytics/RewatchHeatmapServiceTest.php`, 5 cases
  — a 90-100s window overlapped by 3 students ranks highest over 1-student
  windows; one student rewatching the same window 5 times counts once,
  not 5; boundary-exact event never spills into the adjacent bucket;
  empty collection (not error) for no watch events; another lesson's
  events never leak in. All 5 pass.
- `composer test` full suite: 1584/1586 pass. Same 2 pre-existing/
  unrelated failures as B0/B1 (`CourseSlugUniquenessTest`,
  `BackupConfigurationTest`).

### B3 — A2 pt.2: rewatch heatmap UI — done

- `app/Filament/Teach/Widgets/LessonRewatchHeatmapWidget.php` created —
  same `StatsOverviewWidget` + footer-widget wiring as
  `BatchWeakTopicsWidget`/`BatchEngagementWidget`, embedded on
  `ViewLessonArtifact` (confirmed closest existing page — `LessonResource`
  had no other artifact/analysis view). Top-5 windows, timestamps
  formatted `m:ss-m:ss`.
- Tests: `tests/Feature/Analytics/LessonRewatchHeatmapWidgetTest.php`, 2
  cases — top windows render in correct replay-count order with correct
  formatted timestamps; empty state for no watch data. Both pass.
- `composer test` full suite: 1584/1588 pass. 4 failures, all
  pre-existing/unrelated — the same `CourseSlugUniquenessTest` and
  `BackupConfigurationTest` flakes from B0-B2, plus
  `DownloadCompletionExportControllerTest`/
  `DownloadEnrolmentExportControllerTest` (Comms export 404 checks —
  confirmed cross-test storage-state flake, both pass in isolation, no
  connection to this block's files).

### B4 — A3 pt.1: `DropOffService` — done

- `app/Domains/Analytics/Services/DropOffService.php` created —
  `dropOffPointFor(Lesson $lesson): ?int`. Per student, takes their own
  last watch event's `end_s`; a student within `NEAR_END_TOLERANCE_S`
  (5s) of the lesson's own duration counts as finished, not dropped off,
  and is excluded. Remaining "dropped off" points are bucketed to
  `RewatchHeatmapService::BUCKET_WIDTH_S` (10s, same domain/convention),
  and the bucket with the most students returned as the drop-off second.
  `null` when no `video_duration_seconds` yet, no watch events at all, or
  every student finished.
- Tests: `tests/Feature/Analytics/DropOffServiceTest.php`, 5 cases —
  correct majority bucket reported over a minority early-drop student;
  `null` when everyone finishes within tolerance; finished students
  excluded while genuine drop-offs still get reported; `null` for no
  `video_duration_seconds`; `null` for no watch events. All 5 pass.
- `composer test` full suite: 1590/1593 pass. Same 3 pre-existing/
  unrelated flakes as B3 (`CourseSlugUniquenessTest`,
  `BackupConfigurationTest`, one export-controller 404 flake — the other
  export test passed this run, confirming it's the same cross-test
  storage-state flake, not a regression).

### B5 — A3 pt.2: drop-off UI — done

- `DropOffService::detailsFor(Lesson): array{point_s, percentage_of_runtime,
  students_affected}|null` added, refactored to share
  `bucketedDropOffCounts()` with the existing `dropOffPointFor()` (B4) —
  no behavior change to the already-shipped method.
- `app/Filament/Teach/Widgets/LessonDropOffWidget.php` created — **own
  separate widget**, not folded into B3's heatmap widget (drop-off and
  rewatch density are different questions), same footer on
  `ViewLessonArtifact` alongside `LessonRewatchHeatmapWidget`.
- Tests: 2 new cases in `DropOffServiceTest.php` (correct percentage +
  affected count; `null` when everyone finishes) and
  `LessonDropOffWidgetTest.php` (2 cases — correct numbers rendered;
  no-significant-drop-off empty state). All pass.
- `composer test` full suite: 1596/1597 pass. Only the one known
  pre-existing `CourseSlugUniquenessTest` flake — the export-controller
  flake from B3/B4 didn't recur this run, confirming it's cross-test
  noise, not caused by any Analytics file.

### B6 — A4 pt.1: missed-class-replay nudge — done

- `app/Domains/Comms/Jobs/SendMissedClassReplayNudges.php` created — same
  shape `SendInactivityNudges` already established (S9 B13): unscoped
  cross-tenant sweep (`CurrentTenant` cleared up front), dedupe via
  `notifications`, once-per-week cadence. Iterates every `LiveRecording`
  with `status = 'ready'`, resolves its `BatchSession`, and for each
  actively-enrolled student on that session's batch checks for a
  `VideoWatchEvent` on the session's own `lesson_id` created since the
  recording's own `updated_at` — `LiveRecording` has no dedicated
  "went ready" column, but nothing else ever updates a `ready` row again,
  so `updated_at` is that transition timestamp. Fires per `BatchSession`,
  not per lesson (design §4 decision 7).
- `app/Notifications/MissedClassReplayNudgeDue.php` created — same
  student-facing, `[WhatsAppChannel, 'database']` dual-channel shape as
  `InactivityNudgeDue`; carries `batch_session_id`/`lesson_id`/
  `session_title` only, no URL generation (matches `LowAttendanceAlertDue`/
  `InactivityNudgeDue`'s own convention of storing IDs, not tenant-scoped
  links, since the job runs with no tenant bound).
- Scheduled in `bootstrap/app.php` — `Schedule::job()` on the `'scheduled'`
  Horizon queue, daily, alongside every other S9 B10-converted sweep.
- Tests: `tests/Feature/Comms/SendMissedClassReplayNudgesTest.php`, 7
  cases — nudges a student who never watched the replay; skips a student
  who watched it after the recording went ready; still nudges when the
  student's only watch event *predates* the recording going ready (not a
  real replay-watch); skips while the recording is still
  `downloading`/not `ready`; skips a non-actively-enrolled student;
  sends only one nudge per student per week across two `handle()` runs;
  confirms `ShouldQueue` + retry/backoff config. All 7 pass.
- `composer test` (full `php artisan test`, config cache cleared first):
  1603/1604 pass. Only the one pre-existing, already-documented
  `CourseSlugUniquenessTest` flake — untouched by this block's files.

### B7 — A4 pt.2: weak-topic-practice nudge — done

- `app/Domains/Comms/Jobs/SendWeakTopicPracticeNudges.php` created — loops
  every active `Enrolment`, calls
  `WeakTopicDetectionService::weakTopicsFor($student, $batch->course_id)`
  (S17 B7) directly per student's own batch/course pair (design §4
  decision 5's documented Comms → AI exception). A non-empty result
  nudges; an empty result is silently skipped, no new detection logic.
  Same `notifications`-table dedupe, once-per-week cadence,
  `CurrentTenant`-cleared unscoped sweep shape as B6/`SendInactivityNudges`.
- `app/Notifications/WeakTopicPracticeNudgeDue.php` created — same
  student-facing, `[WhatsAppChannel, 'database']` dual-channel shape as
  `MissedClassReplayNudgeDue`; carries the already-computed weak-topics
  collection, `toArray()` flattens each topic to
  `concept`/`ratio`/`lesson_ids` (the linked lesson(s) design §2.1
  A4 calls for).
- Scheduled in `bootstrap/app.php` alongside B6, daily on the
  `'scheduled'` queue.
- Tests: `tests/Feature/Comms/SendWeakTopicPracticeNudgesTest.php`, 5
  cases — nudges a student with a real weak topic (correct concept on the
  notification); skips a student with no weak topics; skips a
  non-actively-enrolled student; only one nudge per student per week
  across two `handle()` runs; confirms `ShouldQueue` + retry/backoff
  config. All 5 pass.
- `composer test` (full `php artisan test`, config cache cleared first):
  1608/1609 pass. Only the one pre-existing, already-documented
  `CourseSlugUniquenessTest` flake — untouched by this block's files.

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
