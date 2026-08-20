# Design: S14 Video & Live-Class Depth

## 1. Why this document exists

Locks scope and architecture for S14 before any block is built, same role
S9-S13's own design docs played. Full block-by-block build log lives in the
companion `2026-08-19-s14-video-live-depth-plan.md`.

S14 is the sixth P2-backlog slice (after S9 hardening, S10 assignments/
gradebook/certificates, S11 billing, S12 security/resilience, S13
discovery/engagement). Scope per `docs/ROADMAP.md`'s own S14 row: D9-D13,
E4, G5-G10 — 12 roadmap items as counted there, but 3 of those 12 (D10,
D11, D12) are **P3-tier**, not P2, and are deliberately cut from this
slice's build scope (§2.3). Real build scope is 9 items.

---

## 2. S14 scope

### 2.1 In scope (P2 items only)

| # | Feature | Source | Priority |
|---|---|---|---|
| D9 | Concurrent-playback limiting (anti account-sharing) | REQ-8, REQ-33 | P2 |
| D13 | Auto-subtitles from ingestion transcript (SRT/VTT), instructor-editable | REQ-22 | P2 |
| E4 | Print/save disable per lesson | REQ-50 | P2 |
| G5 | Recording capture → storage → fed into AI ingestion | REQ-10, NEW | P2 |
| G6 | Recordings via short-lived signed URLs + enrolment check | REQ-10 | P2 |
| G7 | Zoom OAuth per institute (real Meetings API, not the existing Video SDK) | REQ-10 | P2 |
| G8 | Google Meet provider | INF, FRP | P2 |
| G9 | Live polls / quick quizzes during class, real-time aggregation | REQ-54 | P2 |
| G10 | Webhook signature verification; token-expiry handling | REQ-10 | P2 |

### 2.2 Out of scope, and why (locked before any code, not discovered mid-build)

- **D10 (multi-DRM: Widevine/FairPlay/PlayReady), D11 (invisible forensic
  watermark), D12 (offline DRM for PWA downloads)** — all three are
  **P3-tier** per `docs/FEATURES.md` itself, not P2 like the rest of this
  slice's own roadmap row implies by grouping them together. `docs/ROADMAP.md`'s
  own phase-completion note is explicit: "P3 — Scale & enterprise: not
  started — by design, this comes after paying customers exist." This
  platform has zero production traffic and zero paying institutes today
  (confirmed repeatedly across S9-S13's own greenfield-verification
  passes). All three need a real commercial relationship this project
  doesn't have yet: Widevine/FairPlay/PlayReady are licensed DRM
  schemes requiring a paid key-server vendor (e.g. Axinom, Cloudinary,
  Bitmovin) or heavy in-house license-server work; a forensic watermark
  is itself a paid third-party service (e.g. NexGuard, Verimatrix); an
  offline DRM PWA license flow needs a real DRM stack to hang the license
  renewal off in the first place, so it's blocked on D10 regardless. This
  mirrors the exact "flag, don't fake" precedent `RealGeoIpResolver`
  (S12, no MaxMind account) and `ZoomVideoSdkProvider` (S5, no
  `ZOOM_SDK_KEY`/`SECRET`) already established — cut cleanly rather than
  building a stub nobody asked for. Revisit once S14+ ships and a real
  paying institute asks for it.

### 2.3 Verified current state (read before writing this, not assumed)

- **No concurrent-playback tracking exists anywhere** — grepped `app/`
  for `concurrent`, zero matches. S12 B1's own device-limit
  (`DeviceSession`, config'd `security.device_limit`) and S12 B4's
  `AccountSharingFlag` (daily distinct-device-count sweep) are a
  different mechanism entirely: both are slow, batch/session-level
  heuristics, not a real-time "is this exact lesson already streaming on
  another device right now" check. D9 needs its own new mechanism.
- **`LiveClassProvider` interface + 2 drivers already exist** (S5):
  `ExternalLinkProvider` (manual link, real, bound today) and
  `FakeLiveClassDriver` (tests). A third, `ZoomVideoSdkProvider`
  (`app/Integrations/Drivers/ZoomVideoSdkProvider.php`), is real and
  tested but **not bound** — it signs a client-side HS256 JWT against
  ONE platform-wide `ZOOM_SDK_KEY`/`SECRET` pair for Zoom's **Video SDK**
  (build-your-own embedded meeting UI). This is architecturally
  different from G7's own requirement ("Zoom OAuth **per institute**") —
  that needs the classic Zoom **Meetings REST API** with each institute
  connecting its own Zoom account via OAuth, a real outbound HTTP
  integration, not a JWT-signing-only driver. G7 is new work, not
  "flip on the existing driver" (design decision 1).
- **No recording-capture pipeline exists** — a live session's recording
  today is a fully manual step: an instructor uploads the file through
  the *same* `video_upload` Filament field a regular video lesson uses
  (`LessonResource`/`LessonsRelationManager`, S5 B6's own comment: "live_session
  lessons attach their recording through this same field after class").
  No webhook, no automated download, no separate recording model. G5/G6
  build the automated path; the manual path stays as a fallback for
  `external_link`-provider sessions that were never cloud-recorded.
- **No subtitle/SRT/VTT anything exists** — grepped `app/`, zero matches.
  `LessonArtifact` (S3) already carries a `transcript` field
  (`LessonArtifact::latestReadyFor($lessonId)->transcript`, consumed
  today only by `DoubtChat`'s own grounding) — D13 generates SRT/VTT
  *from* that same transcript, no new transcription step needed.
- **Content protection exists for video only** (S9 B6): `controlsList="nodownload"`
  + `oncontextmenu="return false"` on the `<video>` element in
  `lesson-player.blade.php`, explicitly framed as "deters casual
  copying, not real DRM." PDF lessons (`content/watch.blade.php`'s own
  `@elseif ($lesson->type === 'pdf')` branch) render a bare `<iframe>`
  with **no** protection at all — a student can print/save trivially.
  Text lessons render raw sanitized HTML with no protection either. E4
  is genuinely a gap across 2 of 4 non-video lesson types, not a
  from-scratch feature.
- **No live-poll/quiz-during-class concept exists** — grepped `app/`,
  zero matches. `Quiz`/`QuizAttempt` (S6) are async, self-paced,
  lesson-attached assessments, not real-time in-session polls. G9 is
  fully greenfield.
- **Webhook signature verification exists twice already**, both in Fees/
  Billing: `PaymentProvider::verifyWebhookSignature()`
  (Razorpay Orders API, `RazorpayWebhookController`) and
  `SubscriptionProvider`'s own equivalent (`RazorpaySubscriptionWebhookController`).
  G10 mirrors this exact established shape for Zoom's own webhook
  signature scheme (HMAC-SHA256 over a documented header, per Zoom's own
  webhook docs), not a new pattern.

---

## 3. Schema

### 3.1 New tables, all in the existing `Content`/`Batches` domains

No new domain — video/live-class depth is Content's and Batches' own
business logic (the same "extends an existing domain, not a new
cross-cutting layer" reasoning S13's own design doc used for itself).

```
lesson_stream_sessions (Content — D9)
  id, tenant_id, user_id, lesson_id, started_at, last_heartbeat_at,
  ended_at (nullable), timestamps

institute_zoom_connections (Batches — G7)
  id, tenant_id, account_id (Zoom's own account identifier),
  access_token (encrypted), refresh_token (encrypted),
  token_expires_at, connected_by_user_id, timestamps

live_recordings (Batches — G5/G6)
  id, tenant_id, batch_session_id, provider_recording_id (nullable —
  external_link sessions never have one), disk_path (nullable until
  downloaded), status (pending|downloading|ready|failed), timestamps

live_polls (Batches — G9)
  id, tenant_id, batch_session_id, question, status (draft|open|closed),
  opened_at (nullable), closed_at (nullable), timestamps

live_poll_options (Batches — G9)
  id, tenant_id, live_poll_id, label, position, timestamps

live_poll_responses (Batches — G9)
  id, tenant_id, live_poll_id, live_poll_option_id, user_id, timestamps
  (unique on live_poll_id + user_id — one vote per student per poll,
  same defense-in-depth precedent S13's own lesson_question_votes used)
```

### 3.2 Modified existing tables

```
lessons
  + subtitle_disk_path (nullable) — D13
  + subtitle_status (none|generating|ready|failed, default none) — D13
  + print_disabled (boolean, default false) — E4, per-lesson toggle
    (design decision 12 has the default-on vs default-off call)

batch_sessions
  + live_provider gains 'zoom_meetings_oauth' and 'google_meet' values
    alongside the existing 'external_link'/'zoom_video_sdk' — G7/G8
```

### 3.3 New `BelongsToTenant` models (all registered in `TenantModelRegistry`)

`LessonStreamSession`, `InstituteZoomConnection`, `LiveRecording`,
`LivePoll`, `LivePollOption`, `LivePollResponse` — 6 new tenant-scoped
models.

---

## 4. Design decisions

1. **G7 is a new `ZoomMeetingsProvider` driver, not a reuse of
   `ZoomVideoSdkProvider`.** Confirmed in §2.3: the existing driver signs
   client-side JWTs against one platform-wide SDK key, unrelated to a
   per-institute OAuth connection to the real Zoom Meetings REST API.
   Both drivers coexist behind the same `LiveClassProvider` interface —
   `BatchSession::resolveLiveClassProvider()` (S5) already dispatches on
   `live_provider`, so this is an additive `match` arm, not a rewrite.
2. **Per-institute Zoom credentials are OAuth tokens, not a shared API
   key.** `institute_zoom_connections` stores one row per tenant (an
   institute admin connects their own Zoom account once); every meeting
   created for that tenant's batch sessions authenticates as that
   connection. Tokens encrypted at rest (Laravel's own `encrypted` cast,
   same posture any other stored secret in this app would need — no
   prior precedent to point to, since this is the first OAuth-token
   storage case in the codebase, but it's the obvious minimum bar).
3. **Recording capture is provider-driven, not a generic poller.**
   `ZoomMeetingsProvider` registers a `recording.completed` webhook
   subscription at connection time; `GoogleMeetProvider` uses the
   Google Drive API's own recording-ready signal (Meet recordings land
   in the host's Drive). `ExternalLinkProvider`/`ZoomVideoSdkProvider`
   sessions have no recording webhook at all — they keep the existing
   manual-upload fallback (§2.3), `live_recordings.status` simply never
   gets created for those.
4. **Recording download is a queued job, not inline in the webhook
   controller.** Mirrors `PaymentReconciler`'s own "controller does no
   business logic" shape (S7) — the webhook controller verifies the
   signature and dispatches `DownloadLiveRecording` (a real `ShouldQueue`
   job, matching S9 B10's own "every scheduled/triggered background unit
   is a real queued job" posture), which downloads the file, stores it
   on the lesson's own `video_disk_path` (reusing the exact column a
   manually-uploaded recording already uses — no new playback path
   needed), and fires the *existing* `LessonVideoReady` event so S3's
   ingestion pipeline picks it up completely unchanged (G5's own "fed
   into AI ingestion" requirement, satisfied by reuse, not new plumbing).
5. **G6's "signed URL + enrolment check" is already built — this item
   is verification, not new code.** `StreamLessonController` already
   gates on `LessonAccess::canStream()` and serves via a temporary signed
   route (`URL::temporarySignedRoute`, `LessonPlayer::mount()`). Once
   decision 4 lands a recording on the lesson's own `video_disk_path`,
   G6 requires zero new code — same block as decision 4, not a separate
   one in the plan.
6. **D9's concurrent-playback limit is per-lesson, not per-tenant.**
   `lesson_stream_sessions` tracks one open (non-`ended_at`) row per
   (user, lesson) pair; `StreamLessonController`/`WatchHeartbeatController`
   both check "does this user already have another *active* session
   video-wise" before serving — active meaning a heartbeat within the
   last `config('content.stream_session_timeout_seconds')` (default
   120s, generous enough that a genuine tab-switch or brief network
   blip doesn't false-positive a device switch). A second concurrent
   stream doesn't silently 500 — it 409s with a clear "already streaming
   elsewhere" message, matching every other quota-style exception in
   this app (`abort(409, $e->getMessage())`, same shape
   `BandwidthQuotaExceededException`/`SeatLimitExceededException`
   already use).
7. **Stale `lesson_stream_sessions` rows are swept, not left to rot.**
   A row with no heartbeat past the timeout is effectively "ended" for
   enforcement purposes (checked at read time, decision 6), but the row
   itself needs a daily sweep to `ended_at`-close it for reporting/
   cleanliness — same `CurrentTenant::clear()`-at-top, cross-tenant
   scheduled-sweep shape every other daily job in this app already uses
   (`ExpirePendingEnrolments`, `PruneAuditLogEntries`, etc.).
8. **D13's subtitle generation reuses the transcript, doesn't
   re-transcribe.** `LessonArtifact.transcript` (S3) already has
   word-level timing implicit in how the ingestion pipeline chunks it
   (`LessonArtifactChunk.start_s`/`end_s`) — SRT/VTT cues are generated
   directly from existing `LessonArtifactChunk` rows for a lesson's
   latest ready artifact, no new AI call, no new cost. Instructor-editable
   means a Filament textarea on `LessonResource` holding the raw
   SRT/VTT text, saved to `subtitle_disk_path` on save — not a
   timeline-scrubbing cue editor (that's a real, separate UI investment
   with no existing precedent in this app to build from; out of scope
   for this slice, flagged not built).
9. **E4's print/save protection matches each type's own real
   capability, not a single copy-pasted snippet.** PDF: swap the bare
   `<iframe src="{{ $pdfUrl }}">` for one with the browser PDF viewer's
   own toolbar suppressed where the given browser supports it
   (`#toolbar=0` fragment, Chrome/Firefox-only, documented as a
   deterrent not a guarantee — same honest framing S9 B6's own video
   protection already uses) plus the same `oncontextmenu="return
   false"` on the wrapping element. Text: same `oncontextmenu` guard
   plus a CSS `@media print { display: none; }` rule on the lesson body
   (defeats the single most common "just hit Ctrl+P" path, doesn't stop
   a screenshot, framed honestly same as everywhere else). Video/embed:
   unchanged, already covered.
10. **`print_disabled` defaults `false`,** an opt-in per-lesson toggle,
    not a blanket always-on — matches this app's own existing posture
    that free-preview marketing lessons and low-stakes content shouldn't
    pay a UX tax (extra right-click-blocked friction) for content an
    instructor is fine with being freely shared. An instructor flips it
    on for content they actually want to protect.
11. **G9's live polls are Livewire polling, not WebSockets** — same
    "no real-time infra exists yet" boundary S13's own design doc drew
    for direct messaging (`BROADCAST_CONNECTION` is still `log`).
    `wire:poll.2s` while a poll is `open`, matching `FeeCheckout`'s own
    established `wire:poll.3s` precedent (S9 B8) for "good enough
    without real WebSockets."
12. **A poll only accepts a response from an enrolled, currently-live
    participant** — reuses `LessonAccess::canJoinLive()`'s own verdict
    (the exact "one place answers this" gate the syllabus tree's join
    button already uses), not a new authorization check invented for
    polls.

---

## 5. Testing strategy

- **Unit/feature per service**: `ConcurrentStreamGuard`, `SubtitleGenerator`,
  `ZoomMeetingsProvider` (HTTP mocked, no real Zoom account — same
  `Http::fake()` precedent `RazorpayProvider`'s own tests already use),
  `GoogleMeetProvider`, `LivePollService` each get a dedicated test file.
- **Cross-tenant isolation**: all 6 new `BelongsToTenant` models register
  in `TenantModelRegistry`, auto-generating `CrossTenantTest` coverage —
  no hand-written per-model isolation tests.
- **Concurrent-playback**: a dedicated test proves a second stream
  attempt from a different session is refused (409) while the first is
  still active, and succeeds once the first's heartbeat goes stale past
  the configured timeout — not just that the guard exists in isolation.
- **Recording pipeline E2E**: webhook → signature verified → job queued
  → file "downloaded" (faked HTTP) → lesson's `video_disk_path` updated
  → `LessonVideoReady` dispatched → existing `StartIngestion` listener
  picks it up, asserting the *existing* S3 pipeline genuinely fires, not
  mocked away.
- **Live polls**: instructor opens a poll → 2 students respond → a
  third (non-enrolled) student is refused → instructor closes it →
  aggregation numbers match exactly, one test proving the full instructor
  → student → instructor round trip, not just the model relationships.
- Design doc §5 estimate (a rough anchor, not a target, same posture
  every prior slice's own estimate takes): ~70-90 new tests across
  ~19 blocks.

---

## 6. Open questions, locked with a default rather than left blocking

- **Stream-session heartbeat interval/timeout** — defaulted to a 120s
  staleness window (`config('content.stream_session_timeout_seconds')`),
  tunable without a redeploy, same posture every other threshold in this
  app already takes.
- **What happens to an in-progress stream when the concurrent limit is
  hit** — defaulted to refusing the *new* request (409), never killing
  an already-playing session server-side (no mechanism exists to push a
  "stop" to an already-open `<video>` tag without real-time infra, and
  forcibly interrupting a paying student's active lesson is a worse UX
  than blocking the second attempt).
- **Zoom OAuth scope** — defaulted to the minimum Meetings API scopes
  needed to create/manage meetings and read cloud-recording metadata
  (`meeting:write`, `cloud_recording:read`), not requesting anything
  broader (user info, chat, etc.) an institute admin would have to
  justify approving.
