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
| B5 | Print/save protection — text lessons (`@media print` block + context-menu guard) + `lessons.print_disabled` per-lesson toggle (E4 pt.2) | ✅ done |
| B6 | Zoom OAuth connect flow — `institute_zoom_connections` schema + Admin-panel connect/disconnect page (G7 pt.1) | ✅ done |
| B7 | `ZoomMeetingsProvider` driver — real meeting creation via Zoom REST API, wired as a new `live_provider` option (G7 pt.2) | ✅ done |
| B8 | Zoom recording-completed webhook — signature verification + `live_recordings` schema (G5 pt.1 / G10 pt.1) | ✅ done |
| B9 | `DownloadLiveRecording` job — downloads to `video_disk_path`, fires existing `LessonVideoReady` into S3's ingestion pipeline unchanged (G5 pt.2) | ✅ done |
| B10 | Recording playback verification — confirm `StreamLessonController`'s existing signed-URL + `canStream()` gate already covers a captured recording end to end (G6) | ✅ done |
| B11 | Join/API token-expiry handling across both Zoom drivers (G10 pt.2) | ✅ done |
| B12 | `GoogleMeetProvider` driver — Calendar API meet-link creation, wired as a new `live_provider` option (G8) | ✅ done |
| B13 | Live polls schema — `live_polls`/`live_poll_options`/`live_poll_responses` (G9 pt.1) | ✅ done |
| B14 | Instructor "launch poll" — create/open/close a poll during a live session (G9 pt.2) | ✅ done |
| B15 | Student poll response — Livewire UI, gated by `LessonAccess::canJoinLive()`'s own verdict, one vote per student (G9 pt.3) | ✅ done |
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

### B5 — Print/save protection — text lessons — done

- [x] `oncontextmenu="return false"` guard + `@media print { display:
  none; }` CSS rule on the text lesson body in `content/watch.blade.php`
  (decision 9).
- [x] `lessons.print_disabled` boolean migration, default `false`
  (decision 10 — opt-in per lesson), added to `Lesson`'s `$fillable`
  and boolean cast, `LessonResource` `Toggle::make('print_disabled')`
  field (visible for pdf/text lessons only — video's own protection
  stays unconditional, embed has no protection to gate). A real model
  column, so unlike `subtitle_text` it needs no manual
  `afterStateHydrated()`/`EditLesson::afterSave()` wiring — Filament's
  own default attribute hydration/save handles it.
- [x] Both B4's and B5's own protection now gated behind `print_disabled`
  being true for that lesson — the pdf branch's B4 markup got an `@if
  ($lesson->print_disabled)` wrapper with an unprotected `@else`, the
  text branch got the equivalent new-from-scratch.
- [x] `LessonContentTypesTest`: renamed the B4 pdf-protection test to
  assert it only fires with `print_disabled` on, plus 4 new cases —
  unprotected pdf (default off), protected text, unprotected text
  (default off), and a form-save round trip toggling the flag.

`composer test`: 1226/1227 pass (4 new tests) — sole failure is the
pre-existing `CourseSlugUniquenessTest`, carried since S1/S2, unrelated.

### B6 — Zoom OAuth connect flow — done

- [x] `institute_zoom_connections` migration (`tenant_id` with a unique
  constraint — one row per tenant, decision 2 — `account_id`,
  `access_token`/`refresh_token` as `text` columns for the `encrypted`
  cast's longer ciphertext, `token_expires_at`, `connected_by_user_id`).
- [x] `InstituteZoomConnection` model (`App\Domains\Batches\Models`),
  `access_token`/`refresh_token` cast `encrypted`, registered in
  `TenantModelRegistry` (seed/update attributes included).
- [x] No Socialite driver exists for Zoom (only `laravel/socialite`'s own
  built-in providers) — `ZoomOAuthController` talks to Zoom's
  `oauth/authorize`/`oauth/token`/`v2/users/me` endpoints directly via
  `Http`, same direction B7's own `ZoomMeetingsProvider` will take rather
  than adding a new package dependency.
- [x] `redirect()` runs on the tenant's own subdomain, gated
  `auth`+`role:institute_admin` (`routes/batches.php`, new domain group in
  `routes/web.php`); `callback()` is host-agnostic (`routes/web.php`, no
  domain constraint) — same "Zoom's own redirect_uri must be one fixed,
  pre-registered URL, so the tenant id rides in the session instead of the
  host" shape `GoogleAuthController`'s own S9 B2 callback already
  established. `InstituteZoomConnection::updateOrCreate()` keyed on
  `tenant_id` so a re-connect replaces the one row rather than duplicating
  it.
- [x] `App\Filament\Admin\Pages\ZoomConnection` — shows current connection
  status, "Connect Zoom" (`->url()` to the redirect route, a full
  navigation since it has to leave the app) when disconnected,
  "Disconnect" (a real in-page action, deletes the row) when connected.
- [x] `config/services.php` gains `zoom.oauth_client_id`/
  `oauth_client_secret` (`ZOOM_OAUTH_CLIENT_ID`/`ZOOM_OAUTH_CLIENT_SECRET`
  in `.env.example`) — separate from the existing `sdk_key`/`sdk_secret`
  pair, which signs a different, unrelated Video SDK JWT (decision 1).
- [x] `ZoomOAuthConnectionTest` — redirect URL + session, non-admin 403,
  callback token exchange + storage (`Http::fake()`, no real account, same
  precedent `RazorpayProviderTest` already uses), re-connect doesn't
  duplicate, missing-session 419, page connect/disconnect visibility and
  action, and the real admin-panel route.

`composer test`: 1235/1236 pass (8 new tests, plus 1 new `CrossTenantTest`
dataset case for the newly-registered `InstituteZoomConnection`) — sole
failure is the pre-existing `CourseSlugUniquenessTest`, carried since
S1/S2, unrelated.

### B7 — `ZoomMeetingsProvider` driver — done

- [x] New driver (`app/Integrations/Drivers/ZoomMeetingsProvider.php`)
  implementing `LiveClassProvider`, real Zoom Meetings REST API calls
  (`Http::fake()`-tested, no real account — same `RazorpayProvider`-test
  precedent): `createRoom()` POSTs `v2/users/me/meetings`, `joinUrl()`
  GETs `v2/meetings/{id}` and returns `start_url` for instructor/
  institute_admin, `join_url` otherwise, `markEnded()` best-effort PUTs
  the meeting status to `end`. Authenticates via the tenant's
  `InstituteZoomConnection` (B6) access token as-is — reading
  `token_expires_at` and refreshing via `refresh_token` before an
  expired-token call is B11's own scope, not built here yet, same
  "this block ships it, the next completes it" order B4/B5 already
  established.
- [x] `BatchSession::LIVE_PROVIDERS` gains `'zoom_meetings_oauth'`;
  `resolveLiveClassProvider()` gains a `'zoom_meetings_oauth'` arm
  (decision 1 — additive, `ZoomVideoSdkProvider` untouched);
  `BatchSessionsRelationManager`'s `live_provider` Select gains the new
  option.
- [x] `ZoomMeetingsProviderTest`: no-connection throws, real meeting
  creation with the right auth header/body, host vs student join URL,
  markEnded PUTs (and no-ops with nothing sent when no meeting was ever
  created), and the `resolveLiveClassProvider()` dispatch.

`composer test`: 1241/1242 pass (7 new tests, incl. a `RoleSeeder` fix
after the first run) — sole failure is the pre-existing
`CourseSlugUniquenessTest`, carried since S1/S2, unrelated.

### B8 — Zoom recording-completed webhook — done

- [x] `live_recordings` migration (`tenant_id`, `batch_session_id`,
  `provider_recording_id` nullable, `disk_path` nullable, `status`
  pending|downloading|ready|failed). `LiveRecording` model
  (`App\Domains\Batches\Models`), registered in `TenantModelRegistry`.
- [x] `ZoomRecordingWebhookController` — `RazorpayWebhookController`-shaped,
  no business logic itself; hands off to `ZoomRecordingWebhookHandler`
  (`app/Domains/Batches/Services/`). Handler verifies Zoom's real
  HMAC-SHA256 scheme (`v0=` + hmac over `v0:{timestamp}:{body}`, mirrors
  `PaymentProvider::verifyWebhookSignature()`'s established shape) and
  also answers Zoom's own unsigned `endpoint.url_validation` handshake
  (required once, at webhook-registration time) — both real per Zoom's
  own docs, not stubbed.
- [x] `handleRecordingCompleted()` resolves the `BatchSession` by
  `provider_meeting_ref` with no `CurrentTenant` pre-bound (the tenant
  global scope skips filtering while unbound, so this genuinely searches
  every tenant), binds `CurrentTenant` to the match's own tenant, then
  creates a `pending` `LiveRecording` row. An unmatched meeting id is a
  silent no-op (a session outside this app, or a stale event). Does not
  dispatch a download job yet — that's B9's own `DownloadLiveRecording`,
  same "this block ships it, the next completes it" order B4/B5/B7
  already established.
- [x] Route: `POST /api/webhooks/zoom-recording` (`routes/api.php`, no
  `Route::domain(...)` constraint, same "one fixed URL, no tenant
  subdomain" reasoning the Razorpay webhooks above it already use).
  `config/services.php`/`.env.example` gain `zoom.webhook_secret`
  (`ZOOM_WEBHOOK_SECRET`).
- [x] `ZoomRecordingWebhookTest`: handshake response, bad-signature 401,
  matching-session row creation bound to the right tenant, unknown-meeting
  no-op.

`composer test`: 1246/1247 pass (4 new tests, plus 1 new `CrossTenantTest`
dataset case for the newly-registered `LiveRecording`) — sole failure is
the pre-existing `CourseSlugUniquenessTest`, carried since S1/S2,
unrelated. (Caught one real bug during this block's own testing:
`ZoomRecordingWebhookController::__invoke()` was type-hinted to
`Illuminate\Http\Response`, which the `endpoint.url_validation` handshake's
`response()->json()` call can't satisfy — `JsonResponse` doesn't extend
it. Widened to `Symfony\Component\HttpFoundation\Response`, matching
`DownloadLessonPdfController`'s own precedent.)

### B9 — `DownloadLiveRecording` job — done

- [x] Real `ShouldQueue` job (`app/Domains/Batches/Jobs/DownloadLiveRecording.php`,
  decision 4): lists the meeting's recording files, downloads the MP4,
  stores it on the lesson's existing `video_disk_path` column (no new
  playback column, `videos/{tenant_id}/{lesson_id}.mp4` — same convention
  a manual upload already uses), updates `live_recordings.status`
  (`downloading` → `ready`/`failed`), fires the *existing*
  `LessonVideoReady` event unchanged so S3's ingestion pipeline picks it
  up.
- [x] `ZoomRecordingWebhookHandler::handleRecordingCompleted()` now
  dispatches the job right after creating the `pending` row (decision 4 —
  the controller/handler does no download logic itself, just routes to
  the job).
- [x] Idempotency, two layers: the webhook handler no-ops a retried
  delivery whose `LiveRecording` row already moved past `pending` (no
  duplicate row, no re-dispatch); the job itself also no-ops if the row
  is already `ready` — matches `PaymentReconciler`'s own established
  idempotent-webhook discipline.
- [x] Found and avoided a latent bug while modeling this on
  `GenerateLessonSubtitles`' shape: that job calls `$this->fail($e)` but
  only uses the `Queueable` trait — `fail()` lives on
  `InteractsWithQueue`, which `CallQueuedHandler::setJobInstanceIfNecessary()`
  only wires up when a job actually uses that trait. Untested (no
  failure-path test exists for it), so it's live but unverified; flagged
  here rather than fixed, out of scope for this block.
  `DownloadLiveRecording` uses both traits so its own failure path is
  real and tested.
- [x] `ZoomRecordingWebhookTest` updated (`Queue::fake()`, asserts the
  dispatch, new retried-webhook-past-pending case);
  `DownloadLiveRecordingTest` — success path (disk write, lesson/recording
  state, event dispatched), no-MP4-file failure marks `failed`, already-
  `ready` no-op sends no HTTP at all.

`composer test`: 1250/1251 pass (6 new tests, plus one fix mid-block: the
job needed `Dispatchable`/`InteractsWithQueue` alongside `Queueable` for
`::dispatch()`/`fail()` to resolve at all) — sole failure is the
pre-existing `CourseSlugUniquenessTest`, carried since S1/S2, unrelated.

### B10 — Recording playback verification — done

- [x] No new production code (decision 5, confirmed) — `LessonAccess::isPlayable()`
  already treats `video`/`live_session` identically
  (`video_status === 'ready' && filled(video_disk_path)`), and
  `StreamLessonController` never branches on lesson type, so the recording
  `DownloadLiveRecording` (B9) lands on `video_disk_path` needs zero new
  plumbing to stream.
- [x] `RecordedLiveSessionPlaybackTest` (new file) — a `live_session`
  lesson with a recording on `video_disk_path` (same convention B9's job
  writes to) streams `assertOk()` through the real signed
  `watch.stream` route for an enrolled-role student; a `live_session`
  lesson with no recording yet (`video_disk_path` null) 404s the same
  route, proving `isPlayable()`'s gate — not just `canStream()` — covers
  the pre-recording state too.

`composer test`: 1252/1253 pass (2 new tests) — sole failure is the
pre-existing `CourseSlugUniquenessTest`, carried since S1/S2, unrelated.

### B11 — Join/API token-expiry handling — done

- [x] `ZoomVideoSdkProvider`'s existing 2-hour join-JWT expiry confirmed —
  `signSessionToken()` mints `exp: iat + 7200` fresh per call and never
  stores/reuses a token, so there is nothing to refresh; no change needed
  (already correct per S5).
- [x] `ZoomMeetingsProvider::connectionFor()` now routes through a new
  `refreshIfExpired()`: if `token_expires_at` is past, POSTs
  `grant_type=refresh_token` to `zoom.us/oauth/token` with
  `withBasicAuth(oauth_client_id, oauth_client_secret)` — same shape
  `ZoomOAuthController::callback()`'s own initial exchange already uses —
  and persists the new `access_token`/`refresh_token`/`token_expires_at`
  (Zoom rotates `refresh_token` on every use, so the new one is stored,
  not the old one reused). Every public method (`createRoom()`/
  `joinUrl()`/`markEnded()`) already routes through `connectionFor()`, so
  no call site can slip through with a stale token.
- [x] `ZoomMeetingsProviderTest`: 2 new cases — an expired connection
  refreshes before the API call and persists the new tokens; a still-valid
  connection makes no call to `oauth/token` at all.

`composer test`: 1254/1255 pass (2 new tests) — sole failure is the
pre-existing `CourseSlugUniquenessTest`, carried since S1/S2, unrelated.

### B12 — `GoogleMeetProvider` driver — done

- [x] New driver (`app/Integrations/Drivers/GoogleMeetProvider.php`)
  implementing `LiveClassProvider`. Meet has no standalone room-creation
  API — `createRoom()` POSTs a Calendar API `events.insert` with
  `conferenceData.createRequest.conferenceSolutionKey.type=hangoutsMeet`
  (`?conferenceDataVersion=1`, required by Google for conferenceData to
  actually get created) and returns the response's `hangoutLink`, stored
  as `provider_meeting_ref` same as every other driver. No
  `institute_google_connections` table (none designed for G8, unlike G7's
  per-tenant Zoom OAuth) — a single platform-wide `refresh_token`
  (`GOOGLE_CALENDAR_REFRESH_TOKEN`, reusing the existing
  `services.google.client_id`/`client_secret` Socialite-login credential
  pair) is exchanged for a fresh access_token on every call, never cached
  — a rare admin-scheduling action, not a hot path.
- [x] Meet has no host/participant URL split the way Zoom's
  start_url/join_url does — `joinUrl()` returns the same `hangoutLink` to
  everyone (`ExternalLinkProvider`'s own posture), access control is
  Google's own meeting lobby, documented as a limitation not a gap.
  `markEnded()` no-ops — Calendar API has no "end this meeting now" call
  (`ZoomVideoSdkProvider`'s own no-op precedent).
- [x] `BatchSession::LIVE_PROVIDERS` gains `'google_meet'`;
  `resolveLiveClassProvider()` gains a `'google_meet'` arm resolving
  directly to `GoogleMeetProvider` (no Fake-degrade precedent to preserve,
  same posture B7's `zoom_meetings_oauth` arm already took — a missing
  credential throws a clear `RuntimeException`, not a silent fake room);
  `BatchSessionsRelationManager`'s `live_provider` Select gains the new
  option. `config/services.php`/`.env.example` gain
  `google.calendar_refresh_token`/`calendar_id`
  (`GOOGLE_CALENDAR_REFRESH_TOKEN`/`GOOGLE_CALENDAR_ID`).
- [x] `GoogleMeetProviderTest`: missing-credential throws, real event
  creation asserting the token exchange + `conferenceDataVersion=1` +
  `hangoutsMeet` request shape and the returned link, same link for host
  vs student, `markEnded()` sends nothing, and the
  `resolveLiveClassProvider()` dispatch.

`composer test`: 1259/1260 pass (5 new tests) — sole failure is the
pre-existing `CourseSlugUniquenessTest`, carried since S1/S2, unrelated.

### B13 — Live polls schema — done

- [x] `live_polls` (`tenant_id`, `batch_session_id`, `question`, `status`
  draft|open|closed default draft, `opened_at`/`closed_at` nullable),
  `live_poll_options` (`tenant_id`, `live_poll_id`, `label`, `position`),
  `live_poll_responses` (`tenant_id`, `live_poll_id`,
  `live_poll_option_id`, `user_id`) migrations — `live_poll_responses`
  unique on `(live_poll_id, user_id)` (decision 11's own defense-in-depth
  precedent, matching S13's `lesson_question_votes`).
- [x] `LivePoll`/`LivePollOption`/`LivePollResponse` models
  (`App\Domains\Batches\Models`), all `BelongsToTenant`, all registered in
  `TenantModelRegistry` (seed/update attributes included) — schema and
  relations only, no launch/respond/aggregate logic yet (B14-B16's own
  scope, same "this block ships the shape, later blocks complete it"
  order every prior slice has used).
- [x] No hand-written per-model isolation test — `CrossTenantTest`
  auto-generated 3 more model cases from the registry (52/52 pass).

`composer test`: 1262/1263 pass (0 new dedicated tests — `CrossTenantTest`
gained 3 auto-generated cases, counted within its own suite) — sole
failure is the pre-existing `CourseSlugUniquenessTest`, carried since
S1/S2, unrelated.

### B14 — Instructor "launch poll" — done

- [x] `LivePollService` (`app/Domains/Batches/Services/`) — `create()`
  (question + `array<string>` option labels, min 2, `status: draft`),
  `open()` (`draft` -> `open`, stamps `opened_at`, only while
  `BatchSession::liveState() === STATE_LIVE` — same window
  `LessonAccess::canJoinLive()` already requires for a student to reach
  the join button, so a poll opened outside it would have no one able to
  respond anyway), `close()` (`open` -> `closed`, stamps `closed_at`).
  Service-only in this block — no Livewire/controller wiring yet, that's
  B15/B16's own scope.
- [x] Authorization reuses `EnrolmentApprovalPolicy::canDecide()` against
  the session's own batch rather than a new instructor/institute_admin
  constant (same `DECIDER_ROLES` gate `EnrolmentsRelationManager` already
  uses) — checked inside the service itself, not deferred to a UI
  boundary, since no UI exists yet in this block to check it upstream.
- [x] Two new exceptions: `LivePollAuthorizationException` (403, wrong
  actor) and `LivePollStateException` (409, invalid transition or session
  not live), both registered in `bootstrap/app.php` same one-line-per-class
  shape every prior exception already uses.
- [x] `LivePollServiceTest`: instructor creates with options, student
  refused, <2-options refused, open only while live (refused when
  scheduled), re-open refused, close from open, close-when-not-open
  refused, student refused on open/close.

`composer test`: 1271/1272 pass (9 new tests) — sole failure is the
pre-existing `CourseSlugUniquenessTest`, carried since S1/S2, unrelated.

### B15 — Student poll response — done

- [x] `App\Livewire\LivePollResponse` — mounts a `BatchSession`, `render()`
  resolves that session's own currently-`open` `LivePoll` (if any) and
  whether this viewer already responded; `submit(int $optionId)` records a
  `LivePollResponse` row. Gated by `LessonAccess::canJoinLive()`'s own
  verdict (decision 12 — reused, not re-derived, the exact gate the join
  button itself already uses), early-returning on a failed check rather
  than `abort()` — same posture `LessonQuestions::toggleUpvote()` already
  takes for a Livewire action, not an HTTP endpoint.
- [x] One response per student per poll: an app-level existence check
  first (so a normal click never surfaces a DB error), the
  `live_poll_responses` unique constraint (B13) as defense-in-depth
  against a genuine race between two near-simultaneous submits, caught
  and silently dropped — same "app check plus unique constraint" shape
  `LessonQuestionVote`'s own toggle already established.
- [x] Embedded on the syllabus tree's own live_session row
  (`resources/views/livewire/syllabus-tree.blade.php`), next to the Join
  link, gated behind the same `canJoin` the link itself already checks —
  students leave the app once they click Join (design doc §3.3), so the
  poll UI lives here rather than on a page reachable only mid-meeting.
- [x] `LivePollResponseTest`: enrolled live-window student submits and is
  recorded against the right option, a second submit from the same
  student is dropped (still 1 row), a non-enrolled student's submit
  records nothing, a submit against a closed/no poll records nothing, and
  the confirmation message renders once responded.

`composer test`: 1276/1277 pass (5 new tests) — sole failure is the
pre-existing `CourseSlugUniquenessTest`, carried since S1/S2, unrelated.

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
