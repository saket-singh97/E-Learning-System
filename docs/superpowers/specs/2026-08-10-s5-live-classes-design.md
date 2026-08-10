# Design: S5 Live Classes — AI-Native LMS

Date: 2026-08-10
Status: APPROVED
Scope: Sub-project 5 of 8 in the P1 build (Institute Zero)
Window: 2026-08-10 → 2026-10-17 (demo #5 date)
Depends on: S1 Foundation (tenancy, roles, `Lesson.type` enum), S2 Content + Video (`VideoProvider`, upload → `LessonVideoReady` pipeline), S3 AI Spine (ingestion consumes `LessonVideoReady` automatically), S4 Batches + Enrolment (`Batch`, `BatchSession`, `Enrolment`, `attendance_records` schema)
Feeds: S6 Assessments (attendance/completion may gate quiz eligibility later), S8 Comms (absence → WhatsApp notification reads `attendance_records`)

---

## 1. Why this document exists

`docs/PROJECT-STATUS.md`'s roadmap commits S5 to one demo on 2026-10-17: **"Join
a live class from the syllabus tree; recording attaches back to that topic."**
`docs/FEATURES.md` §G (Live Classes) and §H (Attendance) list 23 features
between them (G0–G10, H1–H13), and three prior design docs already pinned
pieces of S5's contract before any S5 code existed:

- S1 §7.1 (line 202): `live_session` is already in `Lesson::TYPES`.
- S1 §7.2: provider strategy decided — `ExternalLinkProvider` day one, Zoom
  Video SDK as a paid option, BigBlueButton deferred; attendance ships with
  **two** writers (manual, async video-coverage), not three, until a
  webhook-capable provider exists.
- S4 §2.4 decision 2: `attendance_records` is polymorphic
  (`attendable_type`/`attendable_id`) specifically so both a `BatchSession`
  and a `Lesson` can be the attended thing — built, unused, waiting for S5.

This document turns those fragments plus six decisions made 2026-08-10
(§2.4) into a schema, service contracts, and a block-by-block build order,
the same way the S1–S4 design docs did for their slices.

---

## 2. S5 scope

### 2.1 In scope (P1 — required for the 2026-10-17 demo)

| Feature | FEATURES.md ref | What it means concretely |
|---|---|---|
| Topic-anchored live class | G0, G0a, G0c | A `live_session` lesson is a normal node in the course tree. Its actual scheduled occurrence lives on a `BatchSession` row (§2.4 decision 2) |
| `LiveClassProvider` interface | G1 | Swappable driver contract, same shape as `VideoProvider`/`AiProvider` |
| External link provider | G4 | Teacher pastes their own Zoom/Meet/anything URL — zero setup, no vendor account needed, fully real from day one |
| Zoom Video SDK provider | (owner call, §2.4 decision 3) | Real driver class written against the SDK's JWT-session contract; exercised via `FakeLiveClassDriver` until a Zoom account exists (no network calls needed to test) |
| Auto-create room + role-scoped join links | G3 | Room/session ref created when a `BatchSession` is scheduled; teacher joins as moderator, student as participant |
| Countdown → Join → Player states | G0c, implementation shape (FEATURES.md §G) | Derived from wall-clock time vs `BatchSession.starts_at`/`duration_minutes` plus the linked `Lesson.video_status` — no stored state column |
| Batch-gated join | §2.4 decision 1 | Only students with an `active` `Enrolment` in the session's batch may join or see join details |
| Manual recording attach | S1 §2.1 (line 60) | Teacher uploads the recording through the *existing* `Lesson::attachUploadedVideo()` flow — no new upload code |
| Recording reuses existing AI ingestion | S1 §2.1, S3 B3 | `attachUploadedVideo()` already fires `LessonVideoReady` regardless of lesson type (`app/Domains/Content/Models/Lesson.php:108`) — S3's 4-job pipeline picks it up with zero new code |
| Teacher bulk attendance marking | H2 | Default-all-present, tap absentees, against a `BatchSession`'s enrolled roster |
| Async video-coverage attendance | H6 | Scheduled job: ≥80% `VideoWatchEvent` coverage on a lesson → `attendance_records` row, `attendable_type = Lesson` |
| Minimal live-session completion flag | §2.4 decision 4 | A boolean on the join/attendance path scoped to `live_session` lessons only — not a general syllabus-progress feature |
| Attendance dashboards (basic) | H8 (partial) | Per-batch-session roster view for instructors; full cross-course dashboards are P2 |

### 2.2 Out of scope, and where each goes

| Feature | FEATURES.md ref | Why deferred | Lands in |
|---|---|---|---|
| BigBlueButton self-hosted | G2 | S1 §7.2: ops burden not worth it solo, real cost ~$95–140/mo vs quoted $45–70; `LiveClassProvider` keeps it a two-way door | Fast-follow, once volume justifies it |
| Real Zoom Video SDK credentials wired | — | No Zoom account exists yet (§2.4 decision 3) — same posture S2 (Bunny) and S3 (AI vendor) shipped with | Fast-follow, once an account exists |
| Automatic recording capture → storage → AI ingestion | G5 | S1 §2.1 explicitly says recording attach is **manual** in S5. The *ingestion* half is free (reused pipeline, see §2.1 above) — only the *capture* half (pulling the file automatically off the provider) is deferred | Fast-follow, needs a provider that can produce a downloadable recording (Zoom/BBB, not plain external links) |
| Signed-URL recording playback + enrolment re-check | G6 | Recordings play through the existing S2 signed-URL video pipeline once uploaded — no new mechanism needed, but the *live-session-specific* enrolment re-check on top of it is new work not required for the demo | Fast-follow |
| Zoom OAuth per institute | G7 | Depends on real Zoom credentials existing (see above) | Fast-follow |
| Google Meet provider | G8 | P2 in FEATURES.md, no dependency on S5's critical path | Fast-follow |
| Live polls / quick quizzes during class | G9 | P2, needs real-time infra (broadcasting) not yet in this codebase | Fast-follow |
| Webhook signature verification | G10 | Moot until a webhook-capable provider (Zoom/BBB) is actually wired | Fast-follow, ships alongside G7 |
| Live-webhook attendance writer | H4, H5 | S1 §7.2: needs a webhook-capable provider; `ExternalLinkProvider` cannot fire join/leave webhooks | Fast-follow, ships alongside G7 |
| Rotating-QR check-in | H3 | Owner call (§2.4 decision 5): two writers (H2, H6) are enough for S5 | Fast-follow |
| Teacher attendance (payouts) | H7 | No payout system exists yet (S7 scope) | S7-adjacent fast-follow |
| Absence → WhatsApp notification | H9 | Needs S8's WhatsApp BSP integration | S8 |
| Low-attendance alerts, geofencing, biometric, substitute workflow | H10–H13 | P2/P3 in FEATURES.md | Fast-follow / later |
| General chapter/syllabus completion tracking | G0d (general form) | No such mechanism exists anywhere in the app yet (S2 only tracks per-lesson watch position). Building the general mechanism is out of S5's size — S5 ships a `live_session`-scoped flag only (§2.4 decision 4) | Later, unscheduled (candidate: S6 or a dedicated slice) |
| Full cross-course attendance dashboards + export | H8 (full) | Per-batch-session roster is enough for the demo; export/cross-course rollups are reporting work | Fast-follow (candidate: S8, which already builds parent/dashboard surfaces) |

### 2.3 Reviewed before writing this: what S1–S4 already promised toward S5

- **`Lesson.type` already includes `live_session`** (S1 B9 migration,
  `app/Domains/Content/Models/Lesson.php:19`) — schema pinned, zero content
  wired. Verified 2026-08-10: no live-class-specific code exists anywhere in
  `app/Domains/Content/` beyond the enum value itself.
- **`BatchSession` already exists** (S4 B2,
  `app/Domains/Batches/Models/BatchSession.php`) with `batch_id`, `starts_at`,
  `duration_minutes` (15–480 validated), `position`. Built for a generic
  "batch has scheduled sessions" concept; S5 is the first consumer that gives
  it content by linking it to a `Lesson`.
- **`attendance_records` already exists** (S4 B6,
  `database/migrations/2026_08_10_140000_create_attendance_records_table.php`)
  — polymorphic `attendable_type`/`attendable_id`, `status`, `source`,
  `marked_by`, `recorded_at`. No instructor-facing marking screen yet (S4
  §2.4 decision 2 explicitly named this as S5's job).
  Registered in `TenantModelRegistry` already — S5 adds no new isolation
  registrations for it, only for the `batch_sessions` column additions
  (already-registered model, no registry change needed there either).
- **`VideoProvider`/`Lesson::attachUploadedVideo()` already fires
  `LessonVideoReady` unconditionally** (verified,
  `app/Domains/Content/Models/Lesson.php:80-109`) — this is *type-agnostic*.
  A `live_session` lesson's manually-uploaded recording rides this exact
  path with zero changes. S3's 4-job ingestion chain (transcribe → chunk →
  concepts → embed) then runs on it automatically, same as any S2 video.
- **`LessonAccess` is the one place lesson visibility is decided**
  (CLAUDE.md, `app/Domains/Content/Services/LessonAccess.php`) — currently
  role-only (`canStream()` checks role + `is_free_preview`, no
  enrolment/batch check exists for *any* lesson type today, verified
  2026-08-10). S5 is the first slice to add a batch-enrolment gate, and it
  must add it *inside* `LessonAccess`, not inline in a controller, per
  CLAUDE.md's explicit rule.
- **Cross-domain model reads are already established precedent, cross-domain
  service calls are not.** S4's design doc (§3.0) states this explicitly:
  "reading another domain's model by FK is not the same as calling into its
  services" — `Batch belongsTo Course` already does this in the Content→
  Batches direction. S5 needs the same precedent in reverse (Content reading
  a Batches model) for the join gate; see §2.4 decision 1 and §3.2.
- **Nothing in S1–S4's contracts needs to change** beyond the two additive
  migrations in §3.1 (`batch_sessions` gains columns, no existing column
  altered; a new `live_session_completions`-style flag, see §2.4 decision 4).

### 2.4 Decided (2026-08-10, before Block 0)

Six scope/architecture questions were resolved with the owner before writing
the block plan:

1. **Joining a live class requires batch enrolment.** New cross-domain read:
   `LessonAccess` checks for an `active` `App\Domains\Batches\Models\Enrolment`
   row for the viewer in the session's batch — reading the model directly
   (established precedent, §2.3), not calling `BatchEnrolmentService`. This
   is the first lesson-visibility rule that is not role-only; every other
   lesson type is unaffected (`live_session` is the only type this check
   applies to).
2. **`BatchSession` gains a `lesson_id` FK; `Lesson` does not gain a
   `batch_id`.** A course can wrap multiple batches with different
   schedules (S4's own model). One `live_session` `Lesson` row is the topic
   identity, shared across every batch of that course; each batch's actual
   occurrence is a `BatchSession` row pointing back at it. A student's
   syllabus view resolves the lesson to *their own batch's* `BatchSession`
   for countdown/join/state — a student with no active enrolment in any
   batch offering this session sees the topic but not a join affordance.
3. **Both `ExternalLinkProvider` and a real `ZoomVideoSdkProvider` class get
   written in S5** — not deferred to a Fake-only stub the way S2/S3 deferred
   their real vendor drivers. `ExternalLinkProvider` needs no account and is
   fully real immediately. `ZoomVideoSdkProvider`'s join contract is a
   signed JWT generated from an SDK key/secret (no outbound network call to
   "create" anything server-side), so it is buildable and unit-testable
   without a live account — but no real Zoom credentials exist yet, so
   `FakeLiveClassDriver` remains the bound implementation in `.env`-unset
   environments (dev, tests, this demo), matching `AiProvider`/
   `VideoProvider`'s existing binding pattern.
4. **A live-session-scoped completion flag ships in S5; the general
   chapter/syllabus completion mechanism does not.** G0d's plain-English
   promise ("counts toward chapter completion") is real for `live_session`
   lessons specifically (a boolean set when the viewer's own attendance
   record for that session is `present`), built ahead of — and not blocked
   by — a general progress-tracking feature that doesn't exist anywhere
   else in the app yet. Revisit when a general mechanism is scoped
   (candidate: S6, or its own slice).
5. **Rotating-QR check-in (H3) is deferred**, joining the live-webhook
   writer (H4/H5) in the fast-follow bucket. S5 ships exactly the two
   writers S1 §7.2 already committed to: teacher bulk-marking (H2) and
   async video-coverage (H6).
6. **No new domain folder.** `app/Domains/LiveClasses/` was considered and
   rejected: S5 introduces no new aggregate-root concept the way `Batch`/
   `Enrolment` did for S4. It rides entirely on existing `Lesson` (Content)
   and `BatchSession` (Batches) rows plus a new `Integrations/` interface.
   New code lands inside the two existing domains it touches (§3.0).

---

## 3. Architecture

### 3.0 Where this lands in the existing domain layout

No new domain. Changes split across three places, each following an
existing pattern rather than introducing a new one:

```
app/Integrations/
  LiveClassProvider.php              interface: createRoom(), joinUrl(), markEnded()
  Drivers/
    FakeLiveClassDriver.php          deterministic, hash-seeded — same shape as FakeAiDriver/FakeVideoDriver
    ExternalLinkProvider.php         real — teacher-supplied URL passthrough, no vendor
    ZoomVideoSdkProvider.php         real — JWT session-signing, unit-testable without a live account

app/Domains/Content/
  Services/LessonAccess.php          + canJoinLive(Lesson, BatchSession, ?User) — the batch-enrolment gate
  Models/Lesson.php                  no schema change; attachUploadedVideo() already reused as-is

app/Domains/Batches/
  Models/BatchSession.php            + lesson_id, live_provider, provider_meeting_ref, live_completion columns
  Filament/Resources/Batches/
    RelationManagers/
      BatchSessionsRelationManager   + "Mark attendance" action (H2) against the session's roster
  Jobs/
    RecordVideoCoverageAttendance    scheduled job for H6, reads VideoWatchEvent, writes attendance_records
  Events/
    LiveSessionRoomCreated           fired when a BatchSession's room is created (join links can be sent by S8 later)
```

`BatchSession belongsTo Lesson` is a new Batches→Content model read — same
precedent as `Batch belongsTo Course` already established in S4. `LessonAccess`
reading `Enrolment` directly is the same precedent in reverse, Content→Batches.
Neither is a cross-domain *service* call; `Events/` remains the only channel
for one domain to *tell* another something happened (`LiveSessionRoomCreated`
is emitted for S8's future benefit, not consumed by S5 itself).

### 3.1 Schema

**`batch_sessions` — additive migration, no existing column touched:**

```sql
ALTER TABLE batch_sessions
  ADD COLUMN lesson_id BIGINT UNSIGNED NULL REFERENCES lessons(id) ON DELETE SET NULL,
  ADD COLUMN live_provider VARCHAR(255) NULL,          -- 'external_link' | 'zoom_video_sdk'
  ADD COLUMN provider_meeting_ref VARCHAR(255) NULL,    -- opaque: URL for external_link, session name for zoom_video_sdk
  ADD COLUMN room_created_at TIMESTAMP NULL;

CREATE INDEX batch_sessions_lesson_id_index ON batch_sessions (lesson_id);
```

`lesson_id` is nullable because most `BatchSession` rows are still plain
timetable entries with no live-class content (S4's original use case) — only
rows anchoring a `live_session` lesson set it.

**No new `Lesson` columns.** State derivation reads `BatchSession.starts_at`
+ `duration_minutes` (already exist) plus `Lesson.video_status`/
`video_disk_path` (already exist, reused from S2):

```
now() < starts_at                                        → SCHEDULED
starts_at <= now() <= starts_at + duration_minutes        → LIVE
now() > starts_at + duration_minutes, video_status='ready' → RECORDED
now() > starts_at + duration_minutes, video_status≠'ready' → ENDED (transitional — "recording will appear here")
```

**Attendance — two `attendable_type` shapes, per S4 §2.4 decision 2, now
actually written to:**

| Writer | `attendable_type` | `attendable_id` | `source` | Written by |
|---|---|---|---|---|
| Teacher bulk marking (H2) | `BatchSession::class` | the live session's id | `manual` | Instructor action, `BatchSessionsRelationManager` |
| Async video-coverage (H6) | `Lesson::class` | any video lesson's id | `video_coverage` | `RecordVideoCoverageAttendance` scheduled job |

**Live-session completion flag (decision 4):** no new table. Read as
`AttendanceRecord::where('attendable_type', BatchSession::class)->where('attendable_id', $session->id)->where('user_id', $viewer->id)->where('status', 'present')->exists()` —
the attendance row *is* the completion signal for a `live_session` lesson,
so no separate flag column is needed. (Written up as a decision because the
alternative — a denormalized boolean on an enrolment or progress table — was
considered and rejected as duplicate state.)

### 3.2 `LiveClassProvider` contract

```php
namespace App\Integrations;

interface LiveClassProvider
{
    /** Create (or return existing) the room for this session; returns the opaque ref to store. */
    public function createRoom(BatchSession $session): string;

    /** Resolve the URL/credentials this specific viewer uses to join right now. */
    public function joinUrl(BatchSession $session, User $viewer): string;

    /** Best-effort cleanup hook once a session's window has passed. */
    public function markEnded(BatchSession $session): void;
}
```

Same shape as `VideoProvider` (`store()`/`url()`/`delete()`) — a create step
that persists an opaque reference, a per-viewer resolve step that is never
stored, a teardown step. `ExternalLinkProvider::createRoom()` is a thin
passthrough over teacher-supplied input (the "room" *is* the pasted URL);
`ZoomVideoSdkProvider::createRoom()` mints a session name,
`joinUrl()`/its Zoom-SDK equivalent signs a fresh JWT per call so tokens are
never stored or reused past their short expiry.

### 3.3 `LessonAccess::canJoinLive()`

```php
public function canJoinLive(Lesson $lesson, BatchSession $session, ?User $viewer): bool
{
    if ($lesson->type !== 'live_session' || $session->lesson_id !== $lesson->id) {
        return false;
    }

    if ($this->isStaff($viewer)) {
        return true; // instructor/admin bypass, same posture as isVisibleTo()
    }

    return Enrolment::query()
        ->where('batch_id', $session->batch_id)
        ->where('user_id', $viewer?->id)
        ->where('status', 'active')
        ->exists();
}
```

Direct `Enrolment` query, not `BatchEnrolmentService` — matches the
established cross-domain-model-read precedent (§2.3). Sits inside
`LessonAccess` so the join button, the join-URL resolution endpoint, and any
future recording-playback re-check share one answer, per CLAUDE.md's rule
this class exists specifically to enforce.

### 3.4 Data flow — one live-class occurrence, start to finish

```
Instructor schedules a BatchSession, links lesson_id to a live_session Lesson
    → LiveClassProvider::createRoom() called, provider_meeting_ref stored
    → LiveSessionRoomCreated event fired (Batches domain)
    → Node renders SCHEDULED in the syllabus tree (countdown)

Wall clock crosses starts_at
    → Node renders LIVE; join button appears for enrolled students + staff
    → Student clicks the TOPIC (not a separate calendar) → LessonAccess::canJoinLive() gate
    → LiveClassProvider::joinUrl() resolved fresh per viewer, never stored

Class ends (wall clock crosses starts_at + duration_minutes)
    → Node renders ENDED (transitional)
    → Instructor marks attendance via BatchSessionsRelationManager (H2)
      → AttendanceRecord::attendable = BatchSession, source = manual
    → Instructor manually uploads the recording via the lesson's existing
      video-upload field → Lesson::attachUploadedVideo() (UNCHANGED, S2 code)
      → video_status = 'ready', LessonVideoReady fired (UNCHANGED, S3 picks it up)
    → Node renders RECORDED; player replaces Join button, same node

Independently, nightly:
    → RecordVideoCoverageAttendance scans active enrolments' VideoWatchEvent
      coverage per lesson, writes AttendanceRecord rows (attendable = Lesson,
      source = video_coverage) for anything crossing 80%
```

---

## 4. Testing

| Layer | What | Notes |
|---|---|---|
| Unit | `LessonAccess::canJoinLive()` — enrolled/not-enrolled/staff-bypass/wrong-session | Mirrors existing `LessonAccess` unit tests |
| Unit | `LiveClassProvider` state derivation (SCHEDULED/LIVE/ENDED/RECORDED boundaries) | `travelTo()`-based, same technique S4 used for FIFO tie-break tests |
| Unit | `FakeLiveClassDriver`, `ExternalLinkProvider`, `ZoomVideoSdkProvider` (JWT shape, no network) | Same pattern as `FakeAiDriver`/`FakeVideoDriver` tests |
| Integration | Schedule session → room created → join gate enforced → attendance marked → recording uploaded → `LessonVideoReady` fired | One flow test per actor path (instructor, enrolled student, non-enrolled student) |
| Integration | `RecordVideoCoverageAttendance` job — coverage threshold boundary, no double-write on re-run | Mirrors S4 B5's approval-expiry job test shape |
| Isolation | `BatchSession` (existing registration, new columns covered by existing seed/update attributes — extend both in `TenantModelRegistry`) | No new model to register; existing `BatchSession`/`AttendanceRecord` entries extend |
| E2E | Full demo script: schedule → countdown → join → mark attendance → upload recording → node shows player | One test, mirrors S4 B7's catalog E2E |

---

## 5. What's not real yet, on purpose

Same posture as every prior slice's honest accounting: BigBlueButton and
real Zoom credentials don't exist (§2.2); automatic recording capture is
deferred, only the *manual* attach + *automatic* ingestion half ships;
QR check-in and the live-webhook attendance writer wait for a
webhook-capable provider; general syllabus/chapter completion tracking
doesn't exist anywhere in the app yet, so S5's completion signal is
deliberately narrow (live-session attendance only, not a real progress
system).

**Demo #5 target: 2026-10-17** — a student opens the syllabus tree, sees a
topic in `LIVE` state, joins from that node (not a calendar), the instructor
marks the roster present/absent, the instructor uploads the recording after
class, and the same node now shows a player — with the recording already
queued into the existing AI ingestion pipeline.
