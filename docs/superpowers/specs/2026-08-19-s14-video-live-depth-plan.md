# Implementation Plan: S14 Video & Live-Class Depth

Full design: `2026-08-19-s14-video-live-depth-design.md`. This is the
block-by-block build log — update the PROGRESS table as each block lands,
same as every S1-S13 plan did. 19 blocks (B0-B18), finer-grained than the
9-item real build scope since several items split into independent blocks
(concurrent-playback split in two, subtitles split in two, print/save
split in two, recording capture split across four, Zoom OAuth split in
two, live polls split in four).

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-19-s14-video-live-depth-design.md and
docs/superpowers/specs/2026-08-19-s14-video-live-depth-plan.md, then build
Block 0 of the S14 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | Concurrent-playback schema — `lesson_stream_sessions` + `content.stream_session_timeout_seconds` config (D9 pt.1) | ✅ done |
| B1 | Concurrent-playback enforcement — `ConcurrentStreamGuard` wired into `StreamLessonController`/`WatchHeartbeatController`, daily stale-session sweep (D9 pt.2) | ✅ done |
| B2 | Auto-subtitle generation — `SubtitleGenerator` (transcript chunks → SRT/VTT), `lessons.subtitle_disk_path`/`subtitle_status` (D13 pt.1) | ✅ done |
| B3 | Instructor-editable subtitles — Filament textarea on `LessonResource`, `<track>` wiring on the player (D13 pt.2) | ✅ done |
| B4 | Print/save protection — PDF lessons (toolbar-suppressed iframe + context-menu guard) (E4 pt.1) | ✅ done |
| B5 | Print/save protection — text lessons (`@media print` block + context-menu guard) + `lessons.print_disabled` per-lesson toggle (E4 pt.2) | ⬜ not started |
| B6 | Zoom OAuth connect flow — `institute_zoom_connections` schema + Admin-panel connect/disconnect page (G7 pt.1) | ⬜ not started |
| B7 | `ZoomMeetingsProvider` driver — real meeting creation via Zoom REST API, wired as a new `live_provider` option (G7 pt.2) | ⬜ not started |
| B8 | Zoom recording-completed webhook — signature verification + `live_recordings` schema (G5 pt.1 / G10 pt.1) | ⬜ not started |
| B9 | `DownloadLiveRecording` job — downloads to `video_disk_path`, fires existing `LessonVideoReady` into S3's ingestion pipeline unchanged (G5 pt.2) | ⬜ not started |
| B10 | Recording playback verification — confirm `StreamLessonController`'s existing signed-URL + `canStream()` gate already covers a captured recording end to end (G6) | ⬜ not started |
| B11 | Join/API token-expiry handling across both Zoom drivers (G10 pt.2) | ⬜ not started |
| B12 | `GoogleMeetProvider` driver — Calendar API meet-link creation, wired as a new `live_provider` option (G8) | ⬜ not started |
| B13 | Live polls schema — `live_polls`/`live_poll_options`/`live_poll_responses` (G9 pt.1) | ⬜ not started |
| B14 | Instructor "launch poll" — create/open/close a poll during a live session (G9 pt.2) | ⬜ not started |
| B15 | Student poll response — Livewire UI, gated by `LessonAccess::canJoinLive()`'s own verdict, one vote per student (G9 pt.3) | ⬜ not started |
| B16 | Live aggregation display — instructor + student result view, `wire:poll.2s` (G9 pt.4) | ⬜ not started |
| B17 | `TenantModelRegistry` sweep — all 6 new `BelongsToTenant` models confirmed registered | ⬜ not started |
| B18 | Full test coverage, review pass, `PROJECT-STATUS.md`/`HANDOVER.md`/`ROADMAP.md` update | ⬜ not started |

**Test suite:** 1206/1207 at slice start (1 pre-existing, unrelated
`CourseSlugUniquenessTest` failure carried since S1/S2, out of scope here
same as every prior slice). Design doc §5 estimates ~70-90 new tests by
B18 — a rough anchor, not a target; every prior slice's own estimate
undershot its actual by a wide margin.

---

## Block detail (expand as each block builds; stubs below are the starting checklist)

### B0 — Concurrent-playback schema — done

- [x] `lesson_stream_sessions` migration (`tenant_id`, `user_id`, `lesson_id`,
  `started_at`, `last_heartbeat_at`, `ended_at` nullable).
- [x] `LessonStreamSession` model, `BelongsToTenant`, registered in
  `TenantModelRegistry` (seed/update attributes included).
- [x] `config('content.stream_session_timeout_seconds')`, default 120
  (design doc decision 6 / open question).

`composer test`: 1207/1208 pass — sole failure is the pre-existing
`CourseSlugUniquenessTest`, carried since S1/S2, unrelated to this block.

### B1 — Concurrent-playback enforcement — done

- [x] `ConcurrentStreamGuard` service: "does this user already have another
  *active* stream on a different lesson session right now" — active
  means a heartbeat within the configured timeout (decision 6).
- [x] Wired into `StreamLessonController` (refuse a second concurrent stream,
  409, same `abort(409, $e->getMessage())` shape every other quota
  exception uses via `ConcurrentStreamLimitExceededException`) and
  `WatchHeartbeatController` (writes/refreshes `last_heartbeat_at` — the
  one place `lesson_stream_sessions` rows are created).
- [x] Daily sweep job (`CloseStaleLessonStreamSessions`) closes stale
  (`ended_at`) rows past the timeout (decision 7), `CurrentTenant::clear()`
  -at-top shape, scheduled alongside every other daily sweep.
- [x] Dedicated `ConcurrentStreamGuardTest`: same-lesson heartbeat repeats
  fine, a different lesson 409s both via heartbeat and via stream while
  the first is active, and succeeds once the first goes stale past the
  timeout; plus a sweep test.

`composer test`: 1212/1213 pass (5 new tests) — sole failure is the
pre-existing `CourseSlugUniquenessTest`, carried since S1/S2, unrelated.

### B2 — Auto-subtitle generation — done

- [x] `SubtitleGenerator` service (`app/Domains/Content/Services/SubtitleGenerator.php`):
  `toSrt()`/`toVtt()` read a `LessonArtifact`'s own `chunkRows()`
  (ordered by `chunk_idx`), emit SRT/VTT cue text from `start_s`/`end_s`/`text`
  (decision 8 — no new AI call).
- [x] `lessons.subtitle_disk_path`/`subtitle_status` (none|generating|ready|failed,
  default none) migration.
- [x] `GenerateLessonSubtitles` listener (`app/Domains/Content/Listeners/`,
  `ShouldQueue`, re-binds `CurrentTenant` like `StartIngestion`) registered
  on the *existing* `LessonArtifactReady` event in `AppServiceProvider` —
  same signal `StartIngestion`'s own chain already produces once
  `EmbedLessonChunks` marks an artifact `ready`, not a separate manual
  trigger. Writes the VTT file to `subtitles/{tenant_id}/{lesson_id}.vtt`
  on the `local` disk (same convention as `pdf_disk_path`), sets
  `subtitle_status` generating -> ready, or -> failed on a thrown exception.
- [x] `SubtitleGeneratorTest`: cue-formatting assertions (SRT/VTT timestamp
  math incl. an hour rollover) plus an end-to-end test firing
  `LessonVideoReady` and asserting the listener lands a `ready` VTT file.

`composer test`: 1214/1215 pass (2 new tests) — sole failure is the
pre-existing `CourseSlugUniquenessTest`, carried since S1/S2, unrelated.

### B3 — Instructor-editable subtitles — done

- [x] Filament textarea (`subtitle_text`) on `LessonResource`, visible for
  video/live_session lessons only, hydrated from the stored VTT file via
  `afterStateHydrated()` (not a model column, so default attribute
  hydration doesn't apply) — holds the raw WebVTT text (decision 8, not a
  timeline-scrubbing cue editor, flagged out of scope).
- [x] `Lesson::attachSubtitleText()` — same
  `subtitles/{tenant_id}/{lesson_id}.vtt` path convention
  `GenerateLessonSubtitles` (B2) already writes to, so an instructor edit
  overwrites the same file the player already points at. Wired through
  `EditLesson::afterSave()`, gated on `filled()` (not `!== null`) since
  the hidden field still dehydrates an empty string for non-video lesson
  types and must not overwrite/create subtitle state for them.
- [x] `watch.subtitle` signed route + `DownloadLessonSubtitleController`
  — same three-gate shape as `DownloadLessonPdfController`
  (`isPlayable`/`isVisibleTo`/`canStream`), no bandwidth-quota accounting
  (a VTT file is a few KB, not the metered bytes `BandwidthQuota` caps).
- [x] `<track kind="subtitles">` wired onto the `<video>` element in
  `lesson-player.blade.php` via `LessonPlayer::mount()`'s own
  `$subtitleUrl` (temporary signed URL, only set when
  `subtitle_status === 'ready'`), matching the existing PDF-download
  signed-route shape.
- [x] `InstructorEditableSubtitlesTest` — hydration, save-overwrites-file,
  hidden-field-doesn't-clobber-other-types, signed-route serve/404, and
  `<track>` present/absent on the player.

`composer test`: 1221/1222 pass (7 new tests) — sole failure is the
pre-existing `CourseSlugUniquenessTest`, carried since S1/S2, unrelated.

### B4 — Print/save protection — PDF lessons — done

- [x] Swapped the bare `<iframe src="{{ $pdfUrl }}">` in
  `content/watch.blade.php` for one appending `#toolbar=0` (a URL
  fragment, so it rides alongside the signed route's own query-string
  params unchanged) — browser-dependent, documented as a deterrent not a
  guarantee, same honest framing S9 B6's own video protection already
  uses — wrapped in a `<div oncontextmenu="return false">` for the
  right-click "Save As" guard (decision 9).
- [x] Not yet gated behind `print_disabled` — that column doesn't exist
  until B5, which will retroactively wire both B4's and B5's own
  protection behind it (per B5's own checklist note); this block's
  protection is unconditional for now, same "protection ships, gating
  arrives next block" order the plan lays out.
- [x] `LessonContentTypesTest`: new "marks the pdf viewer with
  print/save-protection attributes" case asserting both `#toolbar=0` and
  `oncontextmenu="return false"` render on the watch page for a playable
  pdf lesson.

`composer test`: 1222/1223 pass (1 new test) — sole failure is the
pre-existing `CourseSlugUniquenessTest`, carried since S1/S2, unrelated.

### B5 — Print/save protection — text lessons — not started

- `oncontextmenu="return false"` guard + `@media print { display: none;
  }` CSS rule on the text lesson body (decision 9).
- `lessons.print_disabled` boolean migration, default `false`
  (decision 10 — opt-in per lesson), `LessonResource` toggle field.
- Both B4's and B5's own protection gated behind `print_disabled` being
  true for that lesson (video's own S9 B6 protection stays unconditional,
  unchanged — this is new, opt-in ground for the two newly-covered types).

### B6 — Zoom OAuth connect flow — not started

- `institute_zoom_connections` migration (`tenant_id`, `account_id`,
  `access_token`/`refresh_token` encrypted, `token_expires_at`,
  `connected_by_user_id`).
- `InstituteZoomConnection` model, registered in `TenantModelRegistry`.
- Admin-panel page: "Connect Zoom" (OAuth redirect + callback, same
  host-agnostic-callback shape `GoogleAuthController`'s own S9 B2
  precedent already established for an identical "one fixed redirect_uri"
  constraint) / "Disconnect" action.

### B7 — `ZoomMeetingsProvider` driver — not started

- New driver implementing `LiveClassProvider`, real Zoom Meetings REST
  API calls (`Http::fake()`-tested, no real account — same
  `RazorpayProvider`-test precedent), authenticating via the connected
  institute's own stored OAuth token (B6), refreshing it when expired.
- `BatchSession::resolveLiveClassProvider()` gains a `'zoom_meetings_oauth'`
  arm (decision 1 — additive, `ZoomVideoSdkProvider` untouched).

### B8 — Zoom recording-completed webhook — not started

- `RazorpayWebhookController`-shaped controller: verifies Zoom's own
  HMAC-SHA256 webhook signature (G10 pt.1, mirrors
  `PaymentProvider::verifyWebhookSignature()`'s established shape), does
  no business logic itself.
- `live_recordings` migration (`batch_session_id`,
  `provider_recording_id` nullable, `disk_path` nullable,
  `status` pending|downloading|ready|failed).
- `LiveRecording` model, registered in `TenantModelRegistry`.

### B9 — `DownloadLiveRecording` job — not started

- Real `ShouldQueue` job (decision 4): downloads the recording file from
  Zoom's own download URL, stores it on the lesson's existing
  `video_disk_path` column (no new playback column), updates
  `live_recordings.status`, fires the *existing* `LessonVideoReady`
  event unchanged.
- Idempotency: a retried webhook delivery for an already-`ready` recording
  no-ops (matches `PaymentReconciler`'s own established idempotent-webhook
  discipline).

### B10 — Recording playback verification — not started

- No new code expected (decision 5) — a dedicated test proves a captured
  recording plays back through the *existing*
  `StreamLessonController`/`LessonAccess::canStream()`/signed-URL path
  unchanged, closing G6 by verification rather than new plumbing.

### B11 — Join/API token-expiry handling — not started

- `ZoomVideoSdkProvider`'s existing 2-hour join-JWT expiry: confirmed/
  documented, no change needed (already correct per S5).
- `ZoomMeetingsProvider`'s own OAuth access-token refresh-on-expiry path
  (B7's own driver reads `token_expires_at`, refreshes via the stored
  `refresh_token` before an expired-token call would otherwise fail).

### B12 — `GoogleMeetProvider` driver — not started

- New driver implementing `LiveClassProvider`, Google Calendar API
  `conferenceData` meet-link creation (`Http::fake()`-tested).
- `BatchSession::resolveLiveClassProvider()` gains a `'google_meet'` arm.

### B13 — Live polls schema — not started

- `live_polls`/`live_poll_options`/`live_poll_responses` migrations —
  unique on `(live_poll_id, user_id)` for responses (decision 11's own
  defense-in-depth precedent, matching S13's `lesson_question_votes`).
- Models, all registered in `TenantModelRegistry`.

### B14 — Instructor "launch poll" — not started

- Create a poll (question + options) for a live `BatchSession`, open it
  (`status: open`), close it (`status: closed`) — instructor/admin only,
  same role gate `EnrolmentApprovalPolicy::DECIDER_ROLES` already uses.

### B15 — Student poll response — not started

- Livewire component, gated by `LessonAccess::canJoinLive()`'s own
  verdict (decision 12 — reused, not re-derived), one response per
  student per poll (DB unique constraint, not app-level check alone).

### B16 — Live aggregation display — not started

- Instructor + student result view, `wire:poll.2s` while a poll is
  `open` (decision 11, matches `FeeCheckout`'s own established
  polling-refresh precedent).

### B17 — TenantModelRegistry sweep — not started

- Confirm all 6 new `BelongsToTenant` models
  (`LessonStreamSession`, `InstituteZoomConnection`, `LiveRecording`,
  `LivePoll`, `LivePollOption`, `LivePollResponse`) registered with
  `seedAttributes()`/`updateAttributes()` entries; `CrossTenantTest`
  generates coverage for each with no hand-written per-model test.

### B18 — Full test coverage, review pass — not started

- Lifecycle E2E test(s) mirroring S11 B16/S12 B14/S13 B17's own posture
  — at minimum: Zoom OAuth connect → schedule a session on the new
  provider → recording-completed webhook → downloaded → ingested →
  playable, chained in one run; plus a live-poll instructor-launches →
  students-respond → aggregation round trip.
- Explicit review pass across every B0-B17 file for real bugs, not just
  a green suite — same discipline S9 B14/S10 B15/S11 B16/S12 B14/S13
  B17 each already established (each found real bugs this way).
- Update `HANDOVER.md` (S14 complete entry + any new `## Avoid` entries),
  `docs/PROJECT-STATUS.md` (new S14 section), `docs/ROADMAP.md` (S14 row
  → Done).
