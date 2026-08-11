# Implementation Plan: S5 Live Classes

Full design: `2026-08-10-s5-live-classes-design.md`. This is the
block-by-block build log — update the PROGRESS table as each block lands,
same as the S1/S2/S3/S4 plans did.

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-10-s5-live-classes-design.md and
docs/superpowers/specs/2026-08-10-s5-live-classes-plan.md, then build
Block 0 of the S5 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | Domain groundwork — `batch_sessions` additive migration, `LiveClassProvider` interface + `FakeLiveClassDriver` | ✅ done |
| B1 | `ExternalLinkProvider` + room creation (schedule → `createRoom()` → `LiveSessionRoomCreated`) | ✅ done |
| B2 | State derivation (SCHEDULED/LIVE/ENDED/RECORDED) + syllabus-tree join UI | ✅ done |
| B3 | `LessonAccess::canJoinLive()` batch-enrolment gate + join-URL resolution | ✅ done |
| B4 | `ZoomVideoSdkProvider` (JWT session signing, no live account needed to test) | ✅ done |
| B5 | Teacher bulk attendance marking (H2) — `BatchSessionsRelationManager` action | ✅ done |
| B6 | Manual recording attach — reuse `attachUploadedVideo()`; verify S3 ingestion fires unchanged | ✅ done |
| B7 | Async video-coverage attendance (H6) — `RecordVideoCoverageAttendance` scheduled job | ✅ done |
| B8 | Full test coverage, isolation registry updates, review pass, `PROJECT-STATUS.md` update | ✅ done |

**Test suite:** 283/284 passing after B8 (4 new tests, same 1 pre-existing
`CourseSlugUniquenessTest` failure, unrelated, still out of scope). **S5 is
complete — all 9 blocks done.**

---

## Block notes (fill in as each block builds — deviations, gotchas, decisions made mid-build)

### B0 — Domain groundwork — DONE (2026-08-11)

- Additive migration on `batch_sessions`: `lesson_id` (nullable FK to
  `lessons`, `nullOnDelete`), `live_provider`, `provider_meeting_ref`,
  `room_created_at`. No existing column touched — see design doc §3.1.
  File: `database/migrations/2026_08_11_000000_add_live_class_columns_to_batch_sessions_table.php`.
- `app/Integrations/LiveClassProvider.php` interface (`createRoom()`,
  `joinUrl()`, `markEnded()`) + `Drivers/FakeLiveClassDriver.php`, hash-seeded
  off the session id, mirroring `AiProvider`/`FakeAiDriver` and
  `VideoProvider`/`FakeVideoDriver`'s existing shape exactly. Bound in
  `AppServiceProvider::register()` alongside the other two fake drivers.
- `BatchSession` model deliberately left untouched (no `$fillable`/relation
  changes) — the new columns aren't mass-assigned or read anywhere yet;
  that lands in B1 with `createRoom()` wiring.
- Confirmed no S4 gaps were open before starting — `HANDOVER.md` said "none
  known" as of 2026-08-10, still true.
- `composer test`: 208/209 passing, same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated.

### B1 — `ExternalLinkProvider` + room creation — DONE (2026-08-11)

- `createRoom()` for `ExternalLinkProvider` is a passthrough over a
  teacher-supplied URL field on the scheduling form — no vendor call. It
  reads `BatchSession::provider_meeting_ref` (already set by the form) and
  returns it unchanged; throws `InvalidArgumentException` if blank.
  `joinUrl()` returns the same URL for any viewer; `markEnded()` is a no-op
  (no vendor-side state to tear down for a plain link).
- `LiveSessionRoomCreated` event (`app/Domains/Batches/Events/`) fired on
  creation; no consumer yet in S5 (S8 will listen later), matches how S4's
  `StudentEnrolled`/`SeatReleased` events were fired before every consumer
  existed.
- New `BatchSession::createLiveRoom()` orchestrates the flow (resolve
  provider → `createRoom()` → persist `provider_meeting_ref` +
  `room_created_at` → fire event) and is idempotent — a no-op once
  `room_created_at` is already set, so re-saving an already-roomed session
  (e.g. a title edit) doesn't re-mint a room or re-fire the event.
- Provider choice is **per-session**, not the app-wide `LiveClassProvider`
  container binding (that stays `FakeLiveClassDriver` for dev/test, per
  `AppServiceProvider`). `BatchSession::resolveLiveClassProvider()` reads
  `live_provider` and matches it to a concrete driver class —
  `'external_link' => ExternalLinkProvider` for now, `'zoom_video_sdk'`
  throws `InvalidArgumentException` until B4 adds it.
  `BatchSession::LIVE_PROVIDERS` currently lists only `'external_link'`;
  B4 extends it.
- `BatchSession` gained a `lesson()` `belongsTo(Lesson::class)` — the new
  Batches→Content model read design doc §3.0 called for, same precedent as
  `Batch::course()`.
- `BatchSessionsRelationManager`'s form gained a `lesson_id` Select (options
  scoped to `live_session`-type lessons in the batch's own course) and a
  `provider_meeting_ref` "Meeting URL" input, visible/required only once a
  lesson is picked. `live_provider` is set programmatically to
  `'external_link'` on submit (via `mutateFormDataUsing`) rather than shown
  as a single-option dropdown — B4 turns it into a real `Select` once
  `'zoom_video_sdk'` is a second real choice. Room creation runs in both
  `CreateAction` and `EditAction`'s `->after()` hook, mirroring
  `LessonsRelationManager`'s `attachUploadedVideo()` pattern exactly.
- Deliberately not built in B1 (later blocks' scope): no lesson_id ->
  type=live_session server-side validation rule (Select's `options()`
  already constrains the UI list, and no other options()-driven Select in
  the codebase adds a matching `exists()` rule either — consistent, not a
  gap introduced here); no unlink/reset-room handling if `lesson_id` is
  cleared after a room exists; state derivation and the join gate are
  B2/B3.
- `composer test`: 217/218 passing (9 new — `ExternalLinkProviderTest`,
  `BatchSessionLiveClassTest`), same 1 pre-existing `CourseSlugUniquenessTest`
  failure, unrelated.

### B2 — State derivation + syllabus-tree join UI — DONE (2026-08-11)

- `BatchSession::liveState()` — pure function reading `starts_at` +
  `duration_minutes` + linked `Lesson.video_status`, no stored state column,
  exactly design doc §3.1's boundary table (`STATE_SCHEDULED`/`STATE_LIVE`/
  `STATE_ENDED`/`STATE_RECORDED` constants). A session with no linked lesson
  (a plain S4-style timetable entry) resolves to `ENDED` once its window
  passes, never `RECORDED` (`lesson?->video_status` is null-safe).
- `LessonAccess::myLiveBatchSession(Lesson, ?User)` — new Content→Batches
  read (same precedent as `isVisibleTo()`'s reverse direction), resolves
  *which* `BatchSession` is this viewer's own scheduled occurrence: the row
  with this `lesson_id` whose `batch_id` the viewer holds an `active`
  `Enrolment` in. Returns null for a non-`live_session` lesson, a guest, or
  a viewer with no active enrolment in any batch offering the session —
  exactly design doc §2.4 decision 2's "sees the topic but not a join
  affordance." This is resolution only, not the formal boolean gate —
  `LessonAccess::canJoinLive()` (B3) is the authoritative check a future
  join endpoint re-verifies against; this method doesn't staff-bypass the
  way `canJoinLive()` will, since "which of possibly several batches'
  sessions is mine" isn't well-defined for an instructor teaching multiple
  batches of the same course — instructors keep using the Filament admin
  surface (`BatchSessionsRelationManager`) to manage sessions, not this
  student-facing tree.
- Built the **syllabus tree from scratch** — nothing existed under this
  name before B2 (confirmed by search: `app/Domains/Content/Livewire/` and
  `Views/` held only `.gitkeep`). New: `App\Livewire\SyllabusTree`
  (`app/Livewire/SyllabusTree.php`, placed alongside `LessonPlayer`/
  `StudentPortal` per CLAUDE.md's `app/Livewire/` convention, not
  `app/Domains/Content/Livewire/`, to match established precedent) +
  `resources/views/livewire/syllabus-tree.blade.php`, reached via a new
  `ShowSyllabusController` (`app/Http/Controllers/Content/`) at
  `GET /courses/{course}` (`courses.show`), page shell
  `resources/views/content/syllabus.blade.php` mirroring
  `student/batches.blade.php`'s branding-shell pattern. Renders
  `Course → sections → lessons`, gating every row through the existing
  `LessonAccess::isVisibleTo()`/`canStream()` (no new visibility rule
  needed for non-live lesson types) and, for `live_session` lessons,
  `myLiveBatchSession()` + `liveState()` to show: not-offered / scheduled
  (human-readable time) / live + Join button / recorded (links to the
  existing `lessons.watch` page) / ended.
- The **Join button is fully functional**, not a B3 placeholder —
  `SyllabusTree::joinLive(int $lessonId)` re-resolves the lesson (scoped to
  this component's own `$course`, so a tampered lesson id from another
  course 404s), re-resolves `myLiveBatchSession()` fresh (so a
  since-cancelled enrolment can't be joined via a stale rendered button),
  re-checks `liveState() === STATE_LIVE` server-side, then redirects to
  `$session->resolveLiveClassProvider()->joinUrl($session, $viewer)` — all
  B1 infrastructure, no B3 dependency. Matches `BatchCatalog::join()`'s
  existing idiom of a Livewire action re-validating everything itself
  rather than trusting the last render. B3 still lands
  `LessonAccess::canJoinLive()` as the named formal gate other future
  call-sites (a shareable/signed join link, a recording-playback re-check)
  will use; this button can be pointed at it too at that point, but nothing
  here blocks on that landing first.
- Livewire testing gotcha hit mid-build: `abort()`/`abort_unless()` inside
  a Livewire action does **not** throw a catchable exception through
  `Livewire::test(...)->call(...)` the way a plain thrown exception does
  (contrast `BatchCatalog::join()`'s tests, which `toThrow(RuntimeException)`
  for a service-layer throw). Livewire's `Testable::update()` runs the
  action through its own request-handling path, which converts the
  `HttpException` into a captured HTTP response instead of letting it
  propagate to PHPUnit — `Testable::__call()` forwards unknown methods to
  that response (`vendor/livewire/livewire/src/Features/SupportTesting/
  Testable.php:411`), so `assertForbidden()`/`assertNotFound()` on the test
  chain are the right assertion, not `expect(fn () => ...)->toThrow(...)`.
- Deliberately not built in B2 (later blocks'/out-of-scope): no ticking
  client-side countdown JS (server-rendered "starts in X" via
  `diffForHumans()`, refreshed on next Livewire request only); no
  type-specific rendering for pdf/embed/quiz lesson rows beyond a generic
  title + `Watch`/`Enrol to access` link (S5 doesn't own general content
  rendering); no eager-loading of `lesson->section->course` to avoid the
  per-row N+1 in `isVisibleTo()` — correctness over a micro-optimisation
  CLAUDE.md doesn't ask for at this scale; course-level publish gating
  relies entirely on `LessonAccess` already folding `course.status` into
  `isLive()`, so `ShowSyllabusController` itself does no extra check
  (an unpublished course's page loads but renders an empty lesson list for
  non-staff).
- `composer test`: 238/239 passing (21 new —
  `BatchSessionLiveStateTest`, `LessonAccessLiveSessionTest`,
  `SyllabusTreeTest`), same 1 pre-existing `CourseSlugUniquenessTest`
  failure, unrelated.

### B3 — `LessonAccess::canJoinLive()` + join-URL resolution — DONE (2026-08-11)

- `LessonAccess::canJoinLive(Lesson, BatchSession, ?User)` added per design
  doc §3.3: type/session match, staff bypass, else a direct `Enrolment`
  query (Batches model, not `BatchEnrolmentService` — cross-domain model
  read, not a service call).
- **Deviation from the design doc's literal §3.3 code**: added a
  `$session->liveState() === BatchSession::STATE_LIVE` check that the
  sample code didn't have, applying to staff too. Without it, an enrolled
  viewer (or any staff member) could hit the join endpoint directly at any
  time — before the session starts, or long after it ends — and still get
  handed the meeting URL, since nothing else in the method looks at the
  clock. Caught by two new tests that hit the real HTTP endpoint outside
  the live window and got a 302 instead of the expected 403. A method
  literally named "can join *live*" should mean both halves.
- New `JoinLiveClassController` (`app/Http/Controllers/Content/`),
  `GET /live-sessions/{session}/join` (`live.join`) — same shape as
  `StreamLessonController`/`WatchLessonController`: pulls the session id off
  `$request->route('session')` rather than type-hinting `BatchSession
  $session` (the domain-route splicing gotcha), resolves `$session->lesson`,
  404s if the session has no linked lesson (a plain S4 timetable entry),
  gates via `canJoinLive()`, then `redirect()->away($session->
  resolveLiveClassProvider()->joinUrl($session, $viewer))`. Not behind
  `signed` middleware (unlike `watch.stream`) — `canJoinLive()`'s own
  enrolment/staff check is the real gate; signing was S2's answer to a
  different problem (hotlinking a byte-served CDN-backed file), not
  applicable to a small external redirect.
- **Refactored B2's `SyllabusTree`**: its `joinLive()` Livewire action (an
  ad-hoc "resolve + check `liveState()`" check, written before this formal
  gate existed — see B2 notes) is now dead code and removed. The tree's
  Join affordance is a plain `<a href>` to `live.join` instead of a
  `wire:click` button, shown only when `canJoinLive($lesson, $session,
  $viewer)` is true. Keeping both B2's inline check and B3's formal gate
  side by side would have recreated exactly the drift `LessonAccess`'s own
  class docblock warns about (two surfaces answering the same question
  differently) — consolidating onto one call was the point of this block,
  not just adding a second opinion next to the first.
- `myLiveBatchSession()` (B2) still does the *resolution* ("which session is
  mine") and stays un-time-gated on purpose; `canJoinLive()` (B3) is the
  *authorization* on top of a specific resolved session. `SyllabusTree`
  calls both: resolve, then gate — so the rendered Join link and the
  controller it points at can never disagree.
- `composer test`: 249/250 passing (11 new —
  `JoinLiveClassControllerTest`, extended `LessonAccessLiveSessionTest`,
  updated `SyllabusTreeTest`), same 1 pre-existing `CourseSlugUniquenessTest`
  failure, unrelated.

### B4 — `ZoomVideoSdkProvider` — DONE (2026-08-11)

- `app/Integrations/Drivers/ZoomVideoSdkProvider.php` — real class, hand-rolled
  HS256 JWT (no `firebase/php-jwt` dependency; HS256 is the entire spec Zoom's
  Video SDK session auth asks for, ~15 lines: base64url-encode header+payload,
  `hash_hmac('sha256', ..., $secret, true)`, base64url-encode the signature).
  `createRoom()` mints a stable `"lms-session-{$session->id}"` topic name with
  no outbound call (matches design doc §2.4 decision 3 — the session itself
  is created client-side once a JWT authorises the join). `joinUrl()` signs
  `{app_key, tpc, role_type, version, iat, exp}` fresh per call (2-hour
  expiry, well under Zoom's 48h cap) — `role_type` is `1` for
  `instructor`/`institute_admin`, `0` otherwise, giving the "teacher joins as
  moderator" behaviour design doc §2.1/G3 describes. Reads
  `ZOOM_SDK_KEY`/`ZOOM_SDK_SECRET` via new `config('services.zoom.*')`
  entries (added to `config/services.php` + `.env.example`, both blank by
  default, same shape as the existing `AWS_*`/`SLACK_*` entries) and throws
  `RuntimeException` if either is blank — deliberate: silently signing with
  an empty secret would be a forgeable token, not a safe fallback.
- **Not wired into `AppServiceProvider`'s container binding** —
  `LiveClassProvider::class` still resolves to `FakeLiveClassDriver`, exactly
  as decision 3 specifies ("no real Zoom credentials exist yet"). Swapping
  the binding once credentials land is a one-line `AppServiceProvider`
  change; no call site (this class included) needs touching.
- **`BatchSession::resolveLiveClassProvider()`'s `'zoom_video_sdk'` case
  resolves through `app(LiveClassProvider::class)` (the swappable container
  binding), not `app(ZoomVideoSdkProvider::class)` directly.** This was the
  key design call this block had to make: `resolveLiveClassProvider()` is a
  *per-session* choice (`live_provider` column, `'external_link'` needs no
  account and is always the real class; `'zoom_video_sdk'` needs credentials
  that don't exist yet), while the app-wide container binding is a *single*
  swappable default (same shape as `AiProvider`/`VideoProvider`). Routing
  `'zoom_video_sdk'` through the container binding means picking Zoom in the
  scheduling form today safely degrades to `FakeLiveClassDriver`'s
  deterministic behaviour instead of throwing `RuntimeException` on every
  room-creation attempt — the same non-broken-by-default posture every other
  fake-bound integration in this codebase has.
  `BatchSession::LIVE_PROVIDERS` now lists both values.
- `BatchSessionsRelationManager`'s `live_provider` is now a real 2-option
  `Select` (`external_link` / `zoom_video_sdk`, defaulting to
  `external_link`), replacing B1's hardcoded `mutateFormDataUsing` value.
  `provider_meeting_ref` ("Meeting URL") is now visible/required only when
  `live_provider === 'external_link'` — a Zoom-provider session gets its
  `provider_meeting_ref` populated by `createLiveRoom()` after save (via
  whatever `resolveLiveClassProvider()` returns), never typed in by hand.
  Verified against B1's existing relation-manager tests without any test
  changes needed: Filament's `Select::default('external_link')` populates
  form state at `mountTableAction()` time regardless of the field's
  `visible()` closure, so old test payloads that never mention
  `live_provider` still land on `external_link` exactly as before.
- Deliberately not built in B4: no real Zoom room/JWT is ever produced by
  any request-serving code path in this app yet (only reachable by
  instantiating `ZoomVideoSdkProvider` directly, which nothing but its own
  tests does) — that's the honest state until `AppServiceProvider`'s binding
  flips, which needs a real Zoom account first (§2.2, fast-follow); no
  client-side Zoom Video SDK web-client page either — `joinUrl()`'s JWT has
  nowhere to be handed to yet, since `JoinLiveClassController` (B3)
  `redirect()->away()`s to whatever `joinUrl()` returns, which only makes
  sense for `ExternalLinkProvider`'s browser-navigable URLs today.
- `composer test`: 257/258 passing (8 new — `ZoomVideoSdkProviderTest`, +2
  tests in `BatchSessionLiveClassTest`), same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated.

### B5 — Teacher bulk attendance marking (H2) — DONE (2026-08-11)

- New `markAttendance` `Action` on `BatchSessionsRelationManager`'s
  `recordActions`, visible only when `lesson_id !== null` (a live-class
  occurrence, not a plain S4 timetable entry — same guard `scheduleLiveRoom()`
  uses). Opens a `CheckboxList` of the session's roster (every `active`
  `Enrolment` in the session's own `batch_id`), all checked by default —
  H2's "default-all-present, tap absentees" is literally the checkbox
  default: teacher unchecks whoever was absent, doesn't check anyone in.
- `->schema()` used instead of the deprecated `->form()` alias
  (`Filament\Actions\Concerns\HasSchema::form()` is `@deprecated Use
  schema() instead` in this Filament version) — no other custom `Action` in
  this codebase carries a form yet, so there was no existing call site to
  match; picked the non-deprecated one.
- Writes one `AttendanceRecord` per enrolled student via `updateOrCreate()`
  keyed on `[attendable_type, attendable_id, user_id]` — idempotent by
  construction, so re-running the action (e.g. correcting a mis-tap) updates
  the existing rows instead of duplicating them; no DB unique constraint
  needed for this, `updateOrCreate()`'s own select-then-write is enough at
  this scale. `attendable_type` is the full `BatchSession::class` FQCN
  string, matching design doc §3.1's table literally — note this is *not*
  what `tests/Feature/Content/AttendanceRecordTest.php`'s S4 smoke test used
  (`'lesson'`, a lowercase placeholder with no real writer behind it yet at
  the time); no `Relation::morphMap()` is registered anywhere in this app,
  so `attendable_type` is a bare string column, not Eloquent's `morphTo()`
  convention — S5 is the first slice to actually decide what goes in it,
  and follows the design doc over the earlier placeholder.
- `AttendanceRecord` gained `STATUSES = ['present', 'absent']` and
  `SOURCES = ['manual', 'video_coverage']` constants (previously had none) —
  matches every other constrained-string-column model's convention in this
  codebase (`Enrolment::STATUSES`, `Batch::STATUSES`, `Lesson::TYPES`, …).
  `SOURCES` already includes `'video_coverage'` ahead of B7 writing it,
  since design doc §3.1 names both S5 writers together.
- Roster resolution (`enrolledUserIds()`) only counts `status = 'active'`
  enrolments — a `waitlisted`/`pending_approval` student is invisible to
  this action and gets no attendance row, matching H2's own "against a
  BatchSession's enrolled roster" wording.
- Deliberately not built in B5: no attendance-summary column on the
  relation manager's table (out of H2's literal scope); no time-window
  restriction on when the action is available (a teacher can mark
  attendance before, during, or after the session — design doc doesn't ask
  for one, and being unable to backfill a forgotten mark would be a real
  annoyance); the live-session completion flag design doc §2.4 decision 4
  describes (`AttendanceRecord::where(...)->where('status','present')->
  exists()`) is still unconsumed by any UI — nothing in B0-B5 wires it into
  the syllabus tree, same "built, not yet read" posture `LiveSessionRoomCreated`
  has had since B1.
- `composer test`: 263/264 passing (6 new —
  `BatchSessionAttendanceTest`), same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated.

### B6 — Manual recording attach — DONE (2026-08-11)

- **The "verify, don't assume" instruction caught a real bug**: the
  video-upload `FileUpload` field in both `LessonsRelationManager` and
  `LessonResource`'s forms was gated `->visible(fn (Get $get) => $get('type')
  === 'video')` — meaning a `live_session` lesson (`type !== 'video'`) never
  showed the upload field at all. A teacher had no way to attach a recording
  through the existing UI, contradicting this block's own premise ("the
  existing video-upload field calls `attachUploadedVideo()` unchanged").
  Confirmed empirically before fixing anything: a throwaway probe test
  submitted `type => 'live_session'` + `video_upload` through
  `LessonsRelationManager`'s `CreateAction` and the lesson came back with
  `video_status`/`video_disk_path` still null — proving Filament's
  `visible()` gating genuinely excludes a hidden field's value from
  dehydration (not just from rendering), so `callTableAction(...,
  data: [...])` in tests faithfully reproduces what a real browser would
  do. Fixed by widening both visibility closures to
  `in_array($get('type'), ['video', 'live_session'], true)` — the two
  smallest possible edits, no other changes needed since
  `attachUploadedVideo()`/`VideoProvider`/`LessonVideoReady` were already
  correctly type-agnostic (this part of the block's premise held).
- Verified (not assumed) S3's ingestion pipeline end to end against a
  `live_session` lesson for the first time: new
  `tests/Feature/AI/LiveSessionIngestionTest.php` mirrors
  `IngestionPipelineTest.php`'s own `ingestibleLesson()` fixture and
  assertions exactly, `type` swapped to `live_session` — transcript, chunks,
  concepts, and embeddings all come back exactly as they do for a `video`
  lesson. Code-read confirmation ahead of the test: grepped all of
  `app/Domains/AI/` for `type`/`'video'` — zero matches in any of the 4
  jobs, the 2 listeners, or `LessonArtifact`/`LessonArtifactChunk`; the
  chain only ever reads `video_disk_path` (to transcribe) and touches
  nothing else lesson-shaped. Every *existing* AI test (`IngestionPipelineTest`,
  `IngestionStatusUiTest`, `DoubtChatTest`) hardcoded `type => 'video'`, so
  none of them had actually exercised this path before — the pipeline being
  correct by construction and being *tested* against a non-video type were
  two different claims until this block closed the gap between them.
- Not a gap, but noted in passing: `FakeVideoDriver::store()` validates the
  uploaded extension against `ALLOWED_EXTENSIONS = ['mp4', 'webm']`
  regardless of lesson type — a live-session recording upload goes through
  the exact same extension gate a video lesson's upload does, no
  live-session-specific carve-out needed or added.
- `composer test`: 265/266 passing (4 new — `LessonVideoUploadTest`,
  `LiveSessionIngestionTest`), same 1 pre-existing `CourseSlugUniquenessTest`
  failure, unrelated.

### B7 — Async video-coverage attendance (H6) — DONE (2026-08-11)

- **Another "verify, don't assume" gap found before the job could even be
  meaningful**: `Lesson.video_duration_seconds` — the denominator the whole
  ≥80% coverage formula divides by — was never written anywhere in the app.
  It's `$fillable` and has been since S2, but grepped confirmed zero write
  sites; `WatchHeartbeatController` only ever recorded `start_s`/`end_s`,
  never a duration. Shipping the job as originally scoped (read-only
  consumer of an already-populated field) would have made it permanently
  inert — every lesson's coverage check skipped forever, not a working
  feature. Fixed by extending the *existing* heartbeat contract rather than
  building a new endpoint: the player already POSTs every 10s
  (`resources/views/livewire/lesson-player.blade.php`), so it now also
  sends the `<video>` element's own `duration` on each beat;
  `WatchHeartbeatController` persists it to `Lesson.video_duration_seconds`
  the first time a valid one arrives (`blank($lesson->video_duration_seconds)`
  guard — it's a fixed property of the file, not something later heartbeats
  should keep re-writing). `Lesson::attachUploadedVideo()` now resets it to
  `null` on every (re-)upload, since a replaced file invalidates whatever
  duration the old one reported (mp4 → webm swap, or just a different cut) —
  same "stale derived data must be invalidated" reasoning as the existing
  old-file-deletion logic right next to it.
- Confirmed (per the block's own instruction) no segment-merging helper
  existed anywhere before this — grepped the whole app for `merge`/
  `interval`/`coverage`, only false positives (`array_merge`, this file's
  own prose). S2's resume-position logic (`LessonPlayer::mount()`) only
  ever does `max('end_s')`, not a real interval union — insufficient for
  coverage, since a viewer who skips around would have overlapping segments
  double-counted (or under-counted) rather than measured as actual unique
  seconds watched. Wrote `RecordVideoCoverageAttendance::mergedCoveredSeconds()`
  (sort by `start_s`, walk once, extend-or-start a run) — `public`
  specifically so it's unit-testable against hand-built, unpersisted
  `VideoWatchEvent` instances without a full job run + DB round-trip per
  boundary case. Regression-tested with a case naive summation gets wrong:
  two segments (0-45, 40-75) sum to 80 (exactly the threshold) if you don't
  merge, but the real union is 0-75 = 75 seconds, which must *not* cross
  80%.
- `app/Domains/Batches/Jobs/RecordVideoCoverageAttendance.php` — file
  placement matches design doc §3.0's tree literally, even though every
  model it touches (`Lesson`, `VideoWatchEvent`, `AttendanceRecord`) is
  Content-domain, not Batches; H2's sibling writer is the only
  Batches-flavoured attendance concern, H6 isn't, but the design doc
  commits to this location for both and there was no strong reason to
  diverge from it. Scheduled the same way as `ExpirePendingEnrolments`
  (`$schedule->job(new RecordVideoCoverageAttendance)->daily()` in
  `bootstrap/app.php`) — runs with no `CurrentTenant` bound, so
  `BelongsToTenant`'s global scope no-ops and it scans every tenant's
  `VideoWatchEvent` rows in one daily pass.
- **New wrinkle `ExpirePendingEnrolments` never hit**: that job only ever
  `update()`s existing rows, but this one has to `AttendanceRecord::create()`
  brand new rows — and `BelongsToTenant`'s `creating()` hook calls
  `CurrentTenant::id()` *unconditionally*, which throws
  `MissingTenantContextException` with nothing bound. Every create runs
  inside `AttendanceRecord::withoutEvents(fn () => ...)` instead, with
  `tenant_id` set explicitly from `$lesson->tenant_id` (Eloquent's built-in
  event-suppression escape hatch — no `CurrentTenant::set()`/`clear()`
  juggling needed around the loop). Regression-tested directly: one test
  explicitly `CurrentTenant::clear()`s before calling `handle()` (matching
  the real scheduler context exactly, not just trusting the docblock claim
  the way `ExpirePendingEnrolmentsTest` does), another proves a *single*
  pass correctly writes attendance for two separate tenants' lessons.
- Idempotent via a plain existence check (`status = 'present'` for that
  `[attendable_type, attendable_id, user_id]` already exists → skip),
  matching H2's `updateOrCreate` idempotency shape in spirit — simpler here
  since this writer only ever produces `'present'`, never `'absent'` (no
  watch-time simply means no row, unlike H2's explicit tap-off-to-absent).
  `recorded_at` is intentionally never refreshed on a later run — it's "when
  the threshold was first crossed," not "last time this job checked."
- Deliberately not built in B7: no per-`Enrolment` gating (H6 is a general
  video-engagement signal on any lesson, not batch-scoped like H2 — design
  doc's own schema table doesn't ask for one); no query-level pre-filter
  excluding `(lesson, user)` pairs that already have a `present` row before
  the loop starts (the existence check happens per-pair instead) — fine at
  demo scale, a straightforward follow-up if this job's runtime ever
  matters.
- `composer test`: 279/280 passing (14 new —
  `RecordVideoCoverageAttendanceTest` + 3 in `WatchHeartbeatTest` + 1 in
  `LessonVideoUploadTest`), same 1 pre-existing `CourseSlugUniquenessTest`
  failure, unrelated.

### B8 — Full test coverage, isolation, review pass — DONE (2026-08-11)

- Extended `TenantModelRegistry::seedAttributes()`/`updateAttributes()` for
  `BatchSession`'s new columns: the seed row now links a real `live_session`
  `Lesson` (`lesson_id`) and sets `live_provider`/`provider_meeting_ref`;
  `updateAttributes()`'s tamper target swapped from `duration_minutes` (an
  S4 column, already isolation-tested before S5) to `provider_meeting_ref`
  (a new S5 column), so the isolation guard's `update()` assertion
  specifically exercises new surface, not just re-confirm old surface.
  `CrossTenantTest` (14/14) green with the extended seed.
- Full read-through review pass (see Post-S5 review section below) — found
  and fixed 1 real bug, plus a minor defensive hardening item with no
  demonstrated failure.
- `docs/PROJECT-STATUS.md` rewritten from "PLANNED" to "COMPLETE (all 9
  blocks)", matching S1–S4's own post-completion format exactly (block
  table, bug-hunting section, "what's not real yet", demo target).
  `HANDOVER.md` updated to close out S5.
- `composer test`: 283/284 passing (4 new — 3 in `SyllabusTreeTest`, 1 in
  `RecordVideoCoverageAttendanceTest`), same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated.

---

## Post-S5 review

Full re-read of every file touched or added across B0–B7, same practice as
S1 (×2 rounds), S2 (×1), S3 (×2), S4 (×2) — do not trust green tests alone.
This is in addition to the two gaps B6 and B7 each caught mid-block via
their own "verify, don't assume" instruction (documented in those blocks'
own notes above) — this pass looked at everything else.

### Found and fixed: 1 real bug

**A draft course's title and section names leaked to any guest who knew or
guessed its slug.** `ShowSyllabusController` (B2) looked up the course by
slug and rendered the syllabus page unconditionally — no check that the
course was actually published. The lesson *rows* inside the tree were
correctly gated all along (`SyllabusTree` filters every row through
`LessonAccess::isVisibleTo()`), but the page wrapping them, and the tree's
own `<h1>{{ $course->title }}</h1>` plus every `<h2>` section heading,
were not. A guest who requested `/courses/{draft-course-slug}` directly saw
the real title and every section name of a course that was never supposed
to be public — an empty lesson list, but a fully-named one.

This is the exact category of bug `LessonAccess`'s own class docblock
already warns about from S2's history (a gate applied at one layer,
forgotten at the layer wrapping it) — just one level up: course instead of
lesson. B2's own notes recorded the empty-lesson-list behavior as
intentional but never checked whether the *course itself* needed the same
gate its lessons already had.

Fixed with a new `LessonAccess::isCourseVisibleTo(Course, ?User): bool` —
`$course->status === 'published' || $this->isStaff($viewer)`, the same
staff-bypass posture every other method on that class already uses —
checked in `ShowSyllabusController` before the view ever renders. Three
regression tests added to `SyllabusTreeTest.php`: a guest gets a 404 (not
the leaked title) for a draft course, a student gets a 404 too, and an
instructor can still preview their own draft course exactly as staff can
preview a draft lesson elsewhere in the app.

### Checked and deliberately left alone

- **`canJoinLive()` doesn't re-check the owning course is still
  published** — only that the viewer's batch enrolment is active and the
  session's live window is current. An already-enrolled student could in
  principle retain a working join link via a stale bookmark if a course
  were unpublished sometime after they enrolled. Narrow (needs a rare admin
  action *and* a pre-existing bookmark) and not a new disclosure to
  strangers the way the course-title leak was — left as a documented
  limitation rather than built out for a demo that doesn't exercise it.
- **`LessonAccess::myLiveBatchSession()`'s `->first()` has no explicit
  ordering** if a student somehow holds active enrolments in two different
  batches that both offer the same course's live session (S4 doesn't
  prevent multi-batch enrolment in one course). Which of the two sessions
  "wins" is DB-order-dependent. Realistic-but-rare, not a security
  question (either session the student picks is one they're legitimately
  enrolled in), and no block's scope ever asked for tie-break behavior
  here the way S4 B8 had to add one for waitlist FIFO — left alone.
- **`Lesson.video_duration_seconds` had no explicit `integer` cast**,
  unlike every other numeric column added or touched in S5
  (`BatchSession.duration_minutes`, `.position`). Checked against actual
  test behavior rather than assumed dangerous: `WatchHeartbeatTest`'s
  strict (`===`)-comparing assertions against `$lesson->fresh()-
  >video_duration_seconds` already passed *before* this cast existed, so
  this environment's Postgres/PDO already returns native ints for this
  column — no demonstrated failure, not the same category as the course-
  title bug above. Added the cast anyway for consistency with every other
  numeric column in this slice and as a hedge against a driver/environment
  where that isn't true; call it hardening, not a fix.
- **`BatchSession`'s unlink-lesson gap** (clearing `lesson_id` on an
  already-roomed session leaves its stale `provider_meeting_ref`/
  `live_provider`/`room_created_at` untouched) — re-confirmed as the same
  gap B1's own notes already recorded as deliberately deferred, not a new
  finding this round.

### What's not real yet, on purpose

Unchanged from each block's own honest accounting — collected here per the
S1–S4 review sections' own convention: no real Zoom account
(`ZoomVideoSdkProvider` real and tested, not the bound driver);
BigBlueButton out of scope entirely; automatic recording capture deferred
(manual attach, automatic ingestion); QR check-in and the live-webhook
attendance writer wait on a webhook-capable provider; general
chapter/syllabus completion tracking doesn't exist anywhere in the app —
S5's `live_session`-scoped flag is read by nothing yet.

**Test suite after this review pass: 283/284** (same 1 pre-existing
`CourseSlugUniquenessTest` failure, unrelated, still out of scope).
