# Design: S2 Content + Video

Date: 2026-08-09
Status: APPROVED
Scope: Sub-project 2 of 8 in the P1 build (Institute Zero)
Window: 2026-08-22 (S1 demo) → 2026-09-05 (S2 demo)
Depends on: `2026-08-08-s1-foundation-design.md` (S1 Foundation, complete —
blocks 0-12, re-verified)

---

## 1. Why this document exists

S1's design and its implementation plan were written as two documents on
purpose: one answers *why/what/scope*, the other is *execution order*. S2's
plan (`2026-08-09-s2-content-video-plan.md`) was written first, directly,
because most of the interrogation happened live in chat (AskUserQuestion
rounds) rather than as a standalone design pass — but the answers belong here,
not buried in a block-by-block execution doc. This document is that missing
layer, written after the fact from the same decisions.

The P1-wide decomposition (all eight sub-projects, the calendar, the
dependency graph) already lives in `2026-08-08-s1-foundation-design.md` §2 and
is not repeated here — it didn't change.

---

## 2. S2 scope

### 2.1 In scope

| # | Deliverable | Source |
|---|---|---|
| 1 | Tenant-aware login page on all three Filament panels (S1 gap, see §2.3) | Block 7 deferred item |
| 2 | `courses.status` (draft/published/archived) (S1 gap, see §2.3) | FEATURES.md C1 |
| 3 | `VideoProvider` interface + `FakeVideoDriver` (local disk) | DESIGN.md D1, S1's own `Integrations/` pattern |
| 4 | Video upload on `LessonResource`, extension-allowlisted | FEATURES.md D1 |
| 5 | Signed, short-lived stream URLs (`URL::temporarySignedRoute`) | DESIGN.md line 351 ("signed URLs") |
| 6 | Access gate: role check (student/instructor/institute_admin) + free-preview bypass + draft-course block | Derived — no `Enrolment` model exists until S4 |
| 7 | Custom HLS-shaped player (native `<video>` for now): 70% default volume, ±10s seek, 0.5×–2× speed | FEATURES.md D2 |
| 8 | Dynamic watermark (name + email), repositions every 30s, survives fullscreen | FEATURES.md D4 |
| 9 | Watch heartbeat every 10s, position ranges (not a counter), resume-across-devices | FEATURES.md M1 |
| 10 | Bandwidth metering per tenant per day (measurement only) | FEATURES.md D8 (metering half) |
| 11 | Drip scheduling (`scheduled_at`) and free-preview (`is_free_preview`) on lessons | FEATURES.md C4, C5 |
| 12 | `RebindsTenant` test coverage via a fixture job (S1 gap, see §2.3) | S1 verification-pass finding, 2026-08-09 |

### 2.2 Out of scope, and where each goes

| Cut | Lands in | Why deferring is safe |
|---|---|---|
| `attendance_records`, the 3-writer merge | S4 | Design §7.2 already sequences this after S4's manual writer exists to test against |
| Bandwidth quota enforcement + overage billing | Blocked on Q1/Q2 (P2) | Can't enforce a quota against plan tiers that don't exist yet — real contradiction between D8 (P1) and Q1/Q2 (P2), resolved by shipping only the half D8 doesn't depend on Q1/Q2 for |
| Real `BunnyDriver`, actual HLS ladder, adaptive bitrate, 480p/720p transcoding | Fast-follow, same slice, once a Bunny account exists | No Bunny account exists yet (`SETUP.md:84`); `VideoProvider` interface makes the swap additive, zero call-site changes |
| PDF, audio, text/markdown, embed, quiz lesson rendering | Later slice | Demo script (§6) only requires video; other types are C3's job whenever they're prioritized |
| Real `Enrolment`/batches access control | S4 | Batches + Enrolment is S4's whole subject; no schema for it exists anywhere in `app/` today |
| DRM, invisible forensic watermark (D11), screen-recording block (W1) | P3 / P2 | Visible watermark (D4) is the P1-tier anti-piracy measure; the rest is additive hardening |
| Audio-only revision mode (U7), per-tenant feature flags (V10) | P2 / P3 | Not needed for the resume-across-devices demo |
| `courses.created_by`, `thumbnail`, `pricing` | Whenever a consumer needs them (pricing is S7's) | No code path reads any of the three yet |
| Full `DESIGN.md`/`FEATURES.md`/`VALIDATION.md` reconciliation (source design §11) | Separate documentation task | Two of the nine flagged contradictions intersect S2 (attendance, bandwidth) and are resolved above; the other seven don't touch video |

### 2.3 S1 gaps folded in here, not a new S2 feature

Re-auditing S1 before writing this design turned up three real gaps, verified
against the running code:

1. **No Filament panel has a `->login()` page** — confirmed via
   `grep -n login app/Providers/Filament/*.php` (zero matches). Every S1
   feature test reaches a panel via `actingAs()`, which never exercises a real
   login. This was flagged as a known gap in Block 7 ("deferred until it can be
   built tenant-aware") and stops being deferrable once S2 needs a teacher to
   actually log into `/teach` and upload something.
2. **`RebindsTenant` job middleware has zero test coverage** (flagged in S1's
   own Blocks 0-4 verification pass). No `ShouldQueue` job exists anywhere in
   `app/` (`grep -rl ShouldQueue app/` — empty), and S2's video upload stays
   synchronous, so it still won't trigger one naturally. Closed with a fixture
   job, the same throwaway-fixture pattern already used repeatedly in this
   codebase for infra with no natural caller yet.
3. **`courses` has no `status` column** — FEATURES.md C1 calls for
   draft/published/archived; S1's own in-scope table never listed it. Every
   course is implicitly live the instant it's created. This isn't cosmetic for
   S2: the video stream gate (§4.3) needs something to check before letting a
   student stream a course its teacher is still building.

These are S1 defects, not S2 features — tracked here because S2 is what
finally needs them fixed, same reasoning S1 used when Block 5 fixed a
`bootstrap/app.php` bug it found rather than filing it separately.

---

## 3. Architecture

### 3.0 Where this lands in the existing domain layout

No new domain folders. Everything below lands inside the existing `Content`
domain (`app/Domains/Content/`) and the existing shared folders:

```
app/
├── Domains/
│   └── Content/
│       ├── Models/        + VideoWatchEvent, BandwidthUsageDaily
│       ├── Filament/      LessonResource gains video upload; CourseResource gains status
│       └── Livewire/      + LessonPlayer
├── Integrations/          + VideoProvider interface, Drivers/FakeVideoDriver  ← first real use of this folder
├── Http/Controllers/
│   └── Content/           + StreamLessonController, WatchHeartbeatController
└── Filament/{Teach,Admin,Super}/Pages/Auth/  + tenant-aware Login pages (S1 gap)
```

`Integrations/` was scaffolded empty in S1 Block 2 specifically for this
moment — "interfaces + FakeDrivers; real drivers arrive per slice" (S1 design
§4.0). S2 is the first slice that actually uses it.

### 3.1 Data flow

```
Teacher (logged into /teach)
  → uploads video file on LessonResource
  → VideoProvider::store()  (FakeVideoDriver → storage/app/private/videos/{tenant}/{lesson}.ext)
  → lessons.video_status = ready

Student (logged in, or guest if free-preview)
  → requests GET /watch/{lesson}  (signed, 10-min expiry)
  → gate: course.status=published (or previewing own draft as instructor)
          + role check or is_free_preview
  → StreamLessonController resolves VideoProvider::url() → serves file
  → bandwidth_usage_daily.bytes_served += response size

Player (Alpine + native <video>)
  → on mount: GET last position (MAX(end_s) from video_watch_events)
  → seeks to last position, starts playback
  → every 10s: POST /watch/{lesson}/heartbeat {start_s, end_s}
  → watermark overlay repositions every 30s inside the fullscreen container
```

### 3.2 Schema

```
courses                                  -- existing table, one column added
  ...
  status          enum(draft, published, archived) default 'draft'   -- NEW

lessons                                  -- existing table, five columns added
  ...
  video_disk_path         string nullable
  video_duration_seconds  unsigned int nullable
  video_status            enum(uploading, ready, failed) nullable
  scheduled_at            timestamp nullable
  is_free_preview         boolean default false

video_watch_events                       -- NEW table
  id · tenant_id · user_id · lesson_id
  start_s · end_s                        -- one row per 10s heartbeat chunk
  created_at
  -- append-only; resume position = MAX(end_s) WHERE user_id + lesson_id

bandwidth_usage_daily                    -- NEW table
  id · tenant_id · date · bytes_served
  unique (tenant_id, date)
```

Both new tables use `BelongsToTenant` and land in
`TenantModelRegistry::MODELS` (Block 7) — the isolation suite picks them up
automatically, per the registry rule in `CLAUDE.md`.

### 3.3 `VideoProvider` contract

```php
interface VideoProvider
{
    public function store(UploadedFile $file, Lesson $lesson): string; // returns disk path
    public function url(Lesson $lesson): string;                       // resolvable, driver-specific
    public function delete(Lesson $lesson): void;
}
```

`FakeVideoDriver` is the only implementation in S2. The contract is
deliberately thin — `store`/`url`/`delete` — because that's all this slice
calls. `BunnyDriver` (fast-follow) implements the same three methods against
Bunny Storage + CDN; nothing above the interface changes.

---

## 4. Error handling

No new named exception classes. Every failure in this slice is either a plain
HTTP status (403/404) via `abort()`, or the existing
`InvalidFileTypeException` (Block 8) reused as-is for video upload validation
— the same file-type-allowlist pattern branding uploads already use.

| Trigger | Response | Reasoning |
|---|---|---|
| Non-video file extension on upload | `InvalidFileTypeException` → 422, field error, nothing written | Reuses Block 8's `forField()` factory; same shape as branding upload rejection |
| Lesson soft-deleted, not `ready`, or parent course not `published` | 404 (unless instructor/institute_admin previewing own tenant's draft) | A cross-tenant-style "don't confirm existence" answer — same principle S1's design §6 used for cross-tenant reads |
| Guest or wrong role on a non-preview lesson | 403 | Distinguishes "exists but you can't watch it" from "doesn't exist" — the requester already knows the lesson exists (they have a real link), so 403 here doesn't leak anything a 404 would hide |
| Expired or tampered signed URL | 403 (Laravel's `signed` middleware, built in) | No custom code — this is exactly what `URL::temporarySignedRoute` + the `signed` middleware are for |
| Job dispatched without `tenant_id` (any future queued job, including S2's own if one is ever added) | `MissingTenantContextException`, job fails loudly | Already built in S1 Block 3; `RebindsTenantTest` (Block 0) is what finally proves it works |

**One deliberate choice, consistent with S1's own §6:** a wrong-role request
on an existing, real lesson gets 403, not 404 — this is *not* the same
situation as a cross-tenant read (where 403 would confirm the row exists in
someone else's tenant). Here the requester already has a real link to a real
lesson in their own tenant; 403 just says "not for you," which leaks nothing
new.

---

## 5. Testing

### 5.1 The isolation suite (unchanged mechanism, two new members)

`VideoWatchEvent` and `BandwidthUsageDaily` both use `BelongsToTenant` and
both go into `TenantModelRegistry::MODELS`. `tests/Isolation/CrossTenantTest.php`
picks them up with zero new test code, per the registry rule — the same
mechanism S1 built in Block 4, now exercised by its sixth and seventh members.

### 5.2 Other S2 tests

- Login: all three panels reachable via a real (non-`actingAs`) session;
  lockout still fires the same way the JSON endpoint's does.
- Upload: allowed extension succeeds, disallowed rejected and writes nothing.
- Gate: student/instructor/institute_admin/guest × published/draft/preview,
  full matrix.
- Heartbeat: write then resume, position read from the DB, not client state.
- Bandwidth: counter increments per stream, per-tenant isolation holds, never
  blocks a request regardless of total.
- Drip: future `scheduled_at` hidden from students, visible-with-badge to
  teachers.

Full breakdown with counts lives in the implementation plan's per-block Verify
steps and its Testing Plan table — this section is the strategy, not the
tally.

---

## 6. Demo #2 — 2026-09-05, 10 minutes

1. Log into `/teach` on `physics-classes.lms.test` as an instructor (this is
   the first time in the project anyone does this through a real browser, not
   a test client) — upload a short `.mp4` to an existing lesson.
2. Publish the course (`status: draft → published`).
3. Log in as a student on the same subdomain, open the lesson, watch ~15
   seconds. Point out: 70% starting volume, the name+email watermark visibly
   repositioning, fullscreen still showing it.
4. Reload the page (simulating a second device) — playback resumes at ~15s,
   not from zero.
5. Open a second tenant (`chemistry-hub.lms.test`) as a student there — the
   first tenant's video is unreachable (404) even with a guessed lesson ID.
6. Show a free-preview lesson playing with no login at all.

**Set expectations before the demo:** no AI, no fees, no live classes, no
non-video content types on 2026-09-05 — same "show what exists, including
unfinished work" rule S1's demo used (source design §9).

---

## 7. Definition of done for S2

- [ ] Real browser login works on all three Filament panels, tenant-scoped
- [ ] `RebindsTenant` has test coverage, both directions
- [ ] Courses have a `status`; only `published` streams for students
- [ ] Teacher uploads a video to a lesson; non-video files rejected
- [ ] Student streams a lesson via a signed, short-lived URL; cross-tenant
      access blocked; instructor can preview their own draft
- [ ] Free-preview lessons playable without login; others require the role gate
- [ ] Player: 70% default volume, ±10s seek, 0.5×–2× speed, watermark visible
      and repositioning every 30s, survives fullscreen
- [ ] Resume-across-devices verified end to end
- [ ] Drip-scheduled lessons hidden from students until due, badged for
      teachers
- [ ] Bandwidth metered per tenant per day, zero enforcement
- [ ] Isolation suite green with both new models registered
- [ ] Demo #2 delivered on 2026-09-05

---

## 8. Documents this spec makes stale

Two of the nine contradictions logged in the source design's §11 intersect S2
directly, and are resolved here rather than left open:

| Doc | What was wrong | Resolved as |
|---|---|---|
| `DESIGN.md`§5 vs `FEATURES.md` Part 5 (attendance required vs. absent) | Ambiguous whether Institute Zero needs attendance | S2 ships watch-tracking only (`video_watch_events`); `attendance_records` and its 3-writer merge wait for S4, matching design §7.2's own sequencing |
| `FEATURES.md` D8 vs Q1/Q2 (bandwidth quota is P1, plan tiers that define it are P2) | Can't enforce a quota against tiers that don't exist | S2 ships the metering half of D8 only; quota/overage waits for Q1/Q2 |

The remaining seven items in source design §11 don't touch video and are
unchanged by this document.
