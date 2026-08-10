# S2 Content + Video — Implementation Plan

Date: 2026-08-09
Source design: `2026-08-09-s2-content-video-design.md` (scope, architecture, error handling, testing strategy, demo script, definition of done — full detail, not repeated here)
Target demo: 2026-09-05
Status: COMPLETE — all 8 blocks (0-7) done 2026-08-09, then reviewed (8 real bugs found and fixed, see **Post-S2 review** at the end). 106/106 tests passing, uncommitted

Phases 1 to 3 of the normal spec interrogation (why, scope, architecture) are
already answered by the source design above. This document is only the
execution sequence — blocks, dependency order, and per-block Verify steps.

---

## PROGRESS — read this first, update it last

One session per block. At the end of a block: tick its row, commit, close the
session. The next session reads this table and knows exactly where to start.

| # | Block | Status | Commit | Notes |
|---|---|---|---|---|
| 0 | S1 close-out (login pages, `RebindsTenant` test, course status) | ☑ done 2026-08-09 | uncommitted | Found during S2 planning re-audit of S1. `RebindsTenantTest` lives in `tests/Feature/` not `tests/Unit/` — `tests/Pest.php` only boots the app for `Feature`/`Isolation` |
| 1 | Video schema + `VideoProvider` + FakeDriver + upload | ☑ done 2026-08-09 | uncommitted | `getClientOriginalExtension()` used instead of `extension()` in `FakeVideoDriver` — content-sniffed MIME fails for reconstructed temp-upload files in tests |
| 2 | Signed stream route + access gate | ☑ done 2026-08-09 | uncommitted | Controller reads `$request->route('lesson')` not a method-injected `$lesson` param — domain route's `{subdomain}` wildcard collides positionally with an unmatched scalar route param (Laravel splices route params into method args by leftover position, not name, when a class-typehinted param like `Request`/`VideoProvider` sits alongside a plain scalar) |
| 3 | Player UI (Alpine + native `<video>`, watermark) | ☑ done 2026-08-09 | uncommitted | Same route-param-splicing bug as Block 2, hit again on the `/lessons/{lesson}` closure route — fixed the same way (`$request->route('lesson')`). JS/visual behavior (70% volume, watermark drift, fullscreen) not checked in an actual browser — no browser tool available this session; only the backend contract (mount data, signed URL, markup presence) is test-covered |
| 4 | Watch heartbeat + resume-across-devices | ☑ done 2026-08-09 | uncommitted | `VideoWatchEvent` registered in `TenantModelRegistry` now, not deferred to Block 7 — the guard test in `CrossTenantTest` fails the moment a `BelongsToTenant` model exists unregistered, so registration can't wait once the model lands |
| 5 | Bandwidth metering (meter-only) | ☑ done 2026-08-09 | uncommitted | `BandwidthUsageDaily` also registered now (same reasoning as Block 4) — table name `bandwidth_usage_daily` needed an explicit `protected $table` since Eloquent's default pluralization turns `Daily` into `Dailies` |
| 6 | Drip scheduling + free-preview visibility | ☑ done 2026-08-09 | uncommitted | No student-facing course/lesson catalog page exists yet in the app (out of S2 scope — S3/S4 territory), so "student-facing listing" enforced at two levels instead: `Lesson::visibleToStudents()` scope (for whatever browsing UI lands later) + `StreamLessonController`'s gate now also checks `isScheduledForFuture()`. Teacher form gained `scheduled_at`/`is_free_preview` fields (previously no UI set them at all) and the relation-manager table gained badge columns |
| 7 | Tests, registry, docs | ☑ done 2026-08-09 | uncommitted | Registry (item 1) already done in Blocks 4/5, as each model landed — see those rows' notes. Tests (item 2) already written per block throughout. Remaining work this block: `CLAUDE.md` gained the `Http/Controllers/{Domain}/` + `Livewire/` shared-code entries and the route-param-splicing gotcha (both genuinely new this slice); `docs/PROJECT-STATUS.md` updated to S2-complete; fixed a pre-existing doc inaccuracy (`CrossTenantTest` never actually checked Filament visibility, despite the old wording) |

Status values: `☐ not started` · `◐ in progress` · `☑ done`

**Session opener, copy this:**

```
Block N chalu karo — docs/superpowers/specs/2026-08-09-s2-content-video-plan.md
```

**Session closer:** tick the row, write the commit hash in, one commit per block
with the message `feat(s2): block N — <name>`. Then close.

---

## Why Block 0 exists

Re-auditing S1 (per request, "S1 mein koi cheez reh gayi ho toh check kar") turned
up three real gaps, verified against the actual code, not just the plan doc's own
notes:

1. **No Filament panel has a login page.** `TeachPanelProvider`, `AdminPanelProvider`,
   and `SuperPanelProvider` all omit `->login()` (confirmed: `grep -n login
   app/Providers/Filament/*.php` returns nothing). Every feature test reaches
   these panels via `actingAs()`, which never exercises real browser login.
   **Today, nobody can reach `/teach`, `/admin`, or `/super` through a browser.**
   This was flagged as a known gap in Block 7 ("deferred until it can be built
   tenant-aware") — S2 is where it stops being deferrable, because S2's own demo
   requires a teacher to log into `/teach` and upload a video.
2. **`RebindsTenant` job middleware has zero test coverage** (flagged in the
   Blocks 0-4 verification pass, 2026-08-09 11:32am). No `ShouldQueue` job exists
   anywhere in `app/` yet (`grep -rl ShouldQueue app/` returns nothing), so the
   plan's own "fix later, once the first real queued job exists" condition never
   triggered — S2's video upload stays synchronous (FakeDriver, no transcode
   step), so it won't trigger it either. Closing this needs a fixture job, not a
   real business one — same throwaway-fixture pattern Blocks 2/3/6/7/8 already
   used repeatedly for infra that has no natural app-level caller yet.
3. **`courses` has no `status` column.** FEATURES.md C1 calls for
   draft/published/archived; S1's own in-scope table (§3.1 item 11) only asked
   for "Course → Section → Lesson models + Filament CRUD, structure only" and
   didn't list status explicitly, so it was never built. The gap matters now:
   without it, every course is implicitly live the instant a teacher creates it,
   including while they're still uploading lessons — and S2's video stream gate
   (Block 2) needs something to check before letting a student stream a course
   that isn't finished yet.

Not folding in (checked, not worth it): `courses.created_by`/`thumbnail`/`pricing`
still absent (pricing is S7's job, thumbnail is cosmetic, `created_by` has no
consumer yet); the original JSON `routes/auth.php` endpoints are still untested
against literal HTML-form POSTs (Block 8 built a separate Livewire login instead
of hardening that endpoint, and nothing else calls it as a browser form today).
CI has still never run on GitHub and the repo is still uncommitted with no
remote — unchanged since Block 4, not a content/video concern, not touched here.

---

## Scope decisions locked (2026-08-09 planning session)

| # | Question | Decision | Why |
|---|---|---|---|
| 1 | Attendance writer | Deferred to S4 | `attendance_records`'s 3-writer merge (design §7.2) needs S4's manual writer to exist to test against. S2 ships `video_watch_events` only. |
| 2 | Bandwidth (FEATURES D8 vs Q1/Q2 contradiction) | Meter only, no quota/billing | Q1/Q2 (plan tiers) are P2 — can't enforce a quota against tiers that don't exist. |
| 3 | Video driver | FakeDriver now, BunnyDriver later | No Bunny account exists (`SETUP.md:84`). Matches S1's own `Integrations/` pattern: interface + FakeDriver first, real driver per slice. |
| 4 | Content types | Video only | PDF/audio/text/embed/quiz rendering isn't in the demo script; deferred to keep acceptance criteria testable. |
| 5 | Access gate | Role check, no enrolment table | No `Enrolment` model exists anywhere in `app/`; that schema is S4's. Any tenant user with role in `student`/`instructor`/`institute_admin` can stream; instructor/institute_admin additionally bypass the draft-course check (Block 2) to preview their own unpublished work. |
| 6 | Heartbeat schema | Append-only `video_watch_events` ranges log | Matches FEATURES.md M1's literal wording ("not a counter"); feeds future coverage/analytics without a second migration later. |
| 7 | Signed URLs | Build now, via Laravel's built-in `URL::temporarySignedRoute` | Don't hand-roll token crypto Laravel already ships. `BunnyDriver` later just changes what the signed URL resolves to. |
| 8 | Drip + free-preview | Include both (`scheduled_at`, `is_free_preview` on `lessons`) | One column each; free-preview also sidesteps the access gate for the demo course. |

---

## Order

```
0 S1 close-out (login pages, RebindsTenant test, course status)
   └─ 1 Video schema + FakeDriver + upload
        ├─ 2 Signed stream route + access gate
        │     ├─ 3 Player UI + watermark
        │     │     └─ 4 Heartbeat + resume-across-devices
        │     └─ 5 Bandwidth metering
        └─ 6 Drip + free-preview visibility        ← independent of 2-5

7 Tests + registry + docs — after everything above
```

**Sequencing rationale:** Block 0 first because nothing in S2 is browser-reachable
without a login page, and the gate logic in Block 2 needs `courses.status` to
exist. Block 1 next because every later block needs an actual stored video and
the new lesson columns. Block 2 gates everything touching the byte stream (3 and
5), so it comes right after. Block 4 needs the player (Block 3) to actually call
the heartbeat endpoint. Block 6 only needs Block 1's columns and runs
independently. Block 7 needs everything else to exist before it can test it.

---

## Block 0 — S1 close-out

**Depends on:** nothing (fixes existing S1 code).

1. Tenant-aware Filament login pages for all three panels:
   - `app/Filament/{Teach,Admin}/Pages/Auth/Login.php`, each extending
     `Filament\Pages\Auth\Login`, overriding `getCredentialsFromFormData()` to
     inject `'tenant_id' => app(CurrentTenant::class)->id()` — same
     framework-native tenant-scoped-credentials pattern Block 5 and Block 8
     already established (`Auth::attempt()` turns non-password keys into a
     `WHERE`), not a bolted-on scope.
   - `app/Filament/Super/Pages/Auth/Login.php` — no tenant injection (super
     admin's `tenant_id` is null, same as everywhere else it's handled).
   - Wire each into its panel provider via `->login(Login::class)`.
   - Reuse `LoginRequest`'s lockout constants (5 attempts / 15-minute lock) by
     calling its `authenticate()` logic from the custom page's `authenticate()`
     override, rather than reimplementing throttling — one lockout policy, not
     two.
2. `tests/Fixtures/Jobs/NoopTenantJob.php` — a `ShouldQueue` job with no real
   work, carrying `tenant_id` like any future real job would.
   `tests/Feature/Tenancy/RebindsTenantTest.php` — dispatch it with and without
   `tenant_id`: with, assert `CurrentTenant` is bound inside `handle()`;
   without, assert the job's `fail()` is called with
   `MissingTenantContextException`, never silently swallowed. Lives under
   `tests/Feature/`, not `tests/Unit/` — `tests/Pest.php` only extends
   `Tests\TestCase` (app-booting) for `Feature`/`Isolation`; a real
   `Tenant::findOrFail()` call needs that boot.
3. Migration: `courses.status` enum (`draft`, `published`, `archived`), default
   `draft`. Add to `Course::$fillable`. `CourseResource` form gets a select
   field; table gets a status badge column and a filter.

**Verify:** an institute_admin opens `/admin` in a fresh (non-test) browser
session, sees a real login form, logs in with `tenant_id` scoped credentials, and
lands on the dashboard. Same for `/teach` as an instructor and `/super` as a
super admin (no subdomain). Six wrong passwords lock the account the same way
the JSON endpoint already does. `RebindsTenantTest` passes both directions. A
new course defaults to `draft`; only `published` courses are eligible for
Block 2's stream gate.

---

## Block 1 — Video schema + `VideoProvider` + FakeDriver + upload

**Depends on:** Block 0 (needs a real login to reach `/teach` and upload
anything).

1. Migration on `lessons`: `video_disk_path` (string, nullable),
   `video_duration_seconds` (unsigned int, nullable), `video_status`
   (enum: `uploading`/`ready`/`failed`, nullable), `scheduled_at` (timestamp,
   nullable), `is_free_preview` (boolean, default `false`)
2. `app/Integrations/VideoProvider.php` — interface: `store(UploadedFile $file,
   Lesson $lesson): string` (returns disk path), `url(Lesson $lesson): string`,
   `delete(Lesson $lesson): void`
3. `app/Integrations/Drivers/FakeVideoDriver.php` — local-disk implementation,
   `storage/app/private/videos/{tenant_id}/{lesson_id}.{ext}`
4. `LessonResource` form: file-upload field shown when `type=video`, extension
   allowlist (`mp4`, `webm`) — reuses the existing
   `InvalidFileTypeException::forField()` factory from Block 8, same pattern as
   branding uploads. Upload is synchronous (no transcode step for a raw local
   file); `video_status` goes straight to `ready` on success.

**Verify:** uploading an `.mp4` to a video lesson succeeds and `video_status`
becomes `ready`. Uploading an `.exe` is rejected with a field error; nothing is
written to disk or the row.

---

## Block 2 — Signed stream route + access gate

**Depends on:** Block 1.

1. `GET /watch/{lesson}` — generated via `URL::temporarySignedRoute()`, 10-minute
   expiry, re-signed on each page load
2. Gate, in order:
   - Lesson soft-deleted, or `video_status != ready`, or parent `course.status
     != published` → 404, **unless** the requester is `instructor` or
     `institute_admin` in the same tenant (preview their own unpublished work)
   - Not `is_free_preview` and requester is a guest, or lacks
     `student`/`instructor`/`institute_admin` role → 403
   - Otherwise: stream via `FakeVideoDriver::url()`
3. Cross-tenant: no extra scoping code needed — the lesson resolves through
   `BelongsToTenant`'s existing global scope, so a signed URL for tenant A's
   lesson opened under a tenant B session 404s the same way any cross-tenant
   `find()` already does today.

**Verify:** a student in the right tenant streams a published, non-preview
lesson; the same URL opened under a different tenant's session 404s; a guest
streams a free-preview lesson with no login; an instructor streams their own
draft-course lesson for review; a student hitting that same draft lesson gets
404.

---

## Block 3 — Player UI (Alpine + native `<video>`, watermark)

**Depends on:** Block 2.

1. One Livewire component (`app/Livewire/LessonPlayer.php` + Blade view),
   mounted with the authenticated user's name/email and the signed stream URL
2. Native `<video>` element, not `hls.js` — `FakeVideoDriver` serves progressive
   files, not an HLS manifest. The design's own stack-reversal note (§7.1)
   already scoped `hls.js` to arrive with the real driver; wrapping it around a
   driver with no `.m3u8` to give it would be dead code until `BunnyDriver`
   lands.
3. Custom controls: volume defaults to 70%, ±10s seek buttons, speed control
   (0.5×–2×)
4. Watermark: an absolutely-positioned overlay div (name + email) inside the
   **same fullscreen-target container** as the `<video>`, not a sibling outside
   it — the Fullscreen API only keeps descendants of the requested element
   visible, so a sibling overlay disappears the moment fullscreen is entered.
   Alpine `setInterval` repositions it every 30s.

**Verify:** volume starts at 70%; watermark text shows the logged-in student's
real name/email and visibly moves position at least once across a 30+ second
play session, including in fullscreen.

---

## Block 4 — Watch heartbeat + resume-across-devices

**Depends on:** Block 3.

1. Migration: `video_watch_events` (`id`, `tenant_id`, `user_id`, `lesson_id`,
   `start_s`, `end_s`, `created_at`) — `BelongsToTenant`
2. `POST /watch/{lesson}/heartbeat` every 10s from the player, body
   `{start_s, end_s}` for the just-played chunk — inserts one row, no
   server-side coalescing in this pass (fast-follow work for a future
   analytics/coverage slice, not needed for resume)
3. On player mount: `GET` last position = `MAX(end_s)` for `user_id +
   lesson_id`; player seeks there before first play

**Verify:** watch 0–10s, reload the page (or open a second browser session as
the same user), playback resumes at ~10s, read from the DB, not a cookie or
localStorage.

---

## Block 5 — Bandwidth metering (meter-only)

**Depends on:** Block 2.

1. Migration: `bandwidth_usage_daily` (`id`, `tenant_id`, `date`,
   `bytes_served`) — `BelongsToTenant`, unique `(tenant_id, date)`
2. Incremented in the streaming controller by the response's byte count, once
   per completed stream response
3. No quota check, no overage error, anywhere — confirmed by a test that
   streams past an arbitrarily large number and still gets 200 every time

**Verify:** streaming a lesson increments today's row for that tenant by
roughly the file's byte size; a second tenant's row is untouched.

---

## Block 6 — Drip scheduling + free-preview visibility

**Depends on:** Block 1.

1. Student-facing course/lesson listing excludes lessons where `scheduled_at`
   is set and in the future
2. Teacher/admin view shows all lessons regardless, with a "scheduled" badge on
   future-dated ones
3. `is_free_preview` lessons are visually marked and reachable via Block 2's
   gate without the role check

**Verify:** a lesson with `scheduled_at` tomorrow is invisible to a student
today, visible (badged) to the teacher; setting it to yesterday makes it appear
for the student.

---

## Block 7 — Tests, registry, docs

**Depends on:** everything above.

1. Register `VideoWatchEvent` and `BandwidthUsageDaily` in
   `TenantModelRegistry::MODELS`, with `seedAttributes()`/`updateAttributes()`
   entries — `tests/Isolation/CrossTenantTest.php` picks both up automatically
2. Feature tests per block (see Testing Plan below)
3. No new `SETUP.md` env vars — `FakeVideoDriver` needs no config; `BUNNY_*`
   vars stay documented-but-unused until the fast-follow driver swap
4. `CLAUDE.md` — no structural changes expected (no new domain folders), skip
   unless Block 0-6 introduce something outside the existing domain layout

**Verify:** full suite green, isolation suite green with 6 registered models,
guard test still fails if a new tenant model is added without registering it.

---

## Acceptance criteria

1. An institute_admin/instructor/super_admin can log into their respective
   Filament panel through a real browser session (not `actingAs()`); six wrong
   passwords lock the account the same way the JSON endpoint does.
2. `RebindsTenantTest` passes both directions (happy path binds `CurrentTenant`;
   missing-`tenant_id` path fails the job with `MissingTenantContextException`).
3. A new course defaults to `status=draft`; only `published` courses' lessons
   are streamable by students.
4. Uploading a `.mp4` to a `type=video` lesson succeeds; uploading a non-video
   extension is rejected with a field error and writes nothing.
5. A `student`-role user in the owning tenant can stream a published,
   non-preview video lesson; the same signed URL opened under a different
   tenant's session returns 404. An instructor can stream their own tenant's
   draft-course lesson; a student cannot.
6. An unauthenticated guest can stream a lesson where `is_free_preview=true`;
   the same guest gets 403 on a non-preview lesson.
7. Player defaults to 70% volume, supports ±10s seek and 0.5×–2× speed, and
   displays the viewing student's name+email, repositioning at least once per
   30 seconds, including while in fullscreen.
8. Watching to ~10s then reloading (simulating a second device) resumes
   playback within 1s of the last recorded `end_s`, read from
   `video_watch_events`.
9. A lesson with a future `scheduled_at` is absent from the student-facing
   listing and returns 403/404 if the stream URL is hit directly; it's visible
   with a badge for teacher/admin.
10. Every completed stream increments that tenant's
    `bandwidth_usage_daily.bytes_served` for today; no request is ever blocked
    or billed for exceeding any limit.
11. `tests/Isolation/CrossTenantTest.php` passes with 6 registered models;
    removing `VideoWatchEvent` or `BandwidthUsageDaily` from the registry fails
    the guard test.
12. Full `composer test` suite green. No `DB::raw`/`whereRaw` introduced
    (`SecurityBaselineTest` still passes).

---

## Testing plan

| Layer | What | Count |
|---|---|---|
| Feature | Real browser login on all 3 Filament panels + cross-tenant + lockout | +5 |
| Feature | `RebindsTenantTest` (fixture job, both directions) | +2 |
| Feature | `courses.status` default + transitions | +2 |
| Unit | `FakeVideoDriver` store/url/delete | +3 |
| Unit | Stream-gate logic (role check, draft-course block, preview bypass, instructor override, expired signature) | +5 |
| Integration | Upload → stream → heartbeat → resume round trip | +3 |
| Integration | Cross-tenant stream access blocked | +2 |
| Integration | Drip visibility (future vs. past `scheduled_at`) | +2 |
| Integration | Bandwidth counter increments correctly, per tenant | +2 |
| Isolation | `CrossTenantTest` dataset, 2 new registry entries | +2 (generated, not hand-written) |
| Feature/E2E | Real browser-style login to each of the 3 panels | +3 |
| Feature/E2E | Full watch-and-resume flow via HTTP test client | +1 |

---

## Rollback plan

Every migration has a `down()`. Nothing here touches existing S1 tables except
adding nullable columns to `lessons` and one enum column to `courses` (both
additive, safe on existing rows — existing courses backfill to `draft`, which
only matters once Block 2's gate exists to check it). `FakeVideoDriver` files
live under `storage/app/private/`, never committed. Filament login pages are new
files plus one `->login()` call per panel provider — revert the provider line to
fall back to no-login (the current, if broken, state). If a block misbehaves,
revert its commit(s) — same per-block git discipline S1 used. No external
service, no data migration of existing rows, nothing irreversible.

---

## Effort estimate

| Block | Estimate |
|---|---|
| 0 — S1 close-out (login pages, `RebindsTenant` test, course status) | ~3h |
| 1 — Video schema + FakeDriver + upload | ~3h |
| 2 — Signed route + access gate | ~2.5h |
| 3 — Player UI + watermark | ~4h |
| 4 — Heartbeat + resume | ~3h |
| 5 — Bandwidth metering | ~2h |
| 6 — Drip + free-preview | ~1.5h |
| 7 — Tests + registry + docs | ~3h |
| **Total** | **~22h** |

---

## Files reference

| File | Change |
|---|---|
| `app/Filament/{Teach,Admin,Super}/Auth/Login.php` | New: tenant-aware login pages |
| `app/Providers/Filament/{Teach,Admin,Super}PanelProvider.php` | Add `->login(Login::class)` |
| `app/Domains/Tenancy/Services/TenantAuthenticator.php` | New: shared lockout + tenant-scoped `Auth::attempt`, used by `LoginRequest` and all 3 panel logins |
| `tests/Fixtures/Jobs/NoopTenantJob.php` | New fixture job |
| `tests/Feature/Tenancy/RebindsTenantTest.php` | New |
| `database/migrations/*_add_status_to_courses_table.php` | New: `status` enum, default `draft` |
| `app/Domains/Content/Models/Course.php` | Add `status` to `$fillable` |
| `app/Domains/Content/Filament/Resources/Courses/CourseResource.php` | Status field + badge + filter |
| `database/migrations/*_add_video_fields_to_lessons_table.php` | New: `video_disk_path`, `video_duration_seconds`, `video_status`, `scheduled_at`, `is_free_preview` |
| `database/migrations/*_create_video_watch_events_table.php` | New table |
| `database/migrations/*_create_bandwidth_usage_daily_table.php` | New table |
| `app/Integrations/VideoProvider.php` | New interface |
| `app/Integrations/Drivers/FakeVideoDriver.php` | New driver |
| `app/Domains/Content/Models/Lesson.php` | Add video/drip/preview columns to `$fillable` |
| `app/Domains/Content/Models/VideoWatchEvent.php` | New model, `BelongsToTenant` |
| `app/Domains/Content/Models/BandwidthUsageDaily.php` | New model, `BelongsToTenant` |
| `app/Domains/Content/Filament/Resources/Lessons/LessonResource.php` | Conditional video upload field |
| `routes/web.php` (or new `routes/content.php`) | `GET /watch/{lesson}`, `POST /watch/{lesson}/heartbeat` |
| `app/Http/Controllers/Content/StreamLessonController.php` | New: gate + stream |
| `app/Http/Controllers/Content/WatchHeartbeatController.php` | New |
| `app/Livewire/LessonPlayer.php` + Blade view | New player component |
| `app/Support/Tenancy/TenantModelRegistry.php` | Register 2 new models |

---

## Out of scope

- `attendance_records` and its 3-writer merge — S4
- Bandwidth quota enforcement, overage billing, plan tiers (Q1/Q2) — P2, blocked
  on tiers not existing
- Real `BunnyDriver`, actual HLS ladder, adaptive bitrate, 480p/720p transcoding
  — fast-follow once a Bunny account exists
- PDF, audio, text/markdown, embed, quiz lesson rendering — later slice
- Real `Enrolment`/batches-based access control — S4
- DRM, invisible forensic watermark (D11), screen-recording block (W1) — P3/P2
- Audio-only revision mode (U7), per-tenant feature flags (V10) — P2/P3
- `courses.created_by`, `thumbnail`, `pricing` — no consumer yet, pricing is S7's
- Full `DESIGN.md`/`FEATURES.md`/`VALIDATION.md` reconciliation (design §11) —
  separate documentation task
- Hardening the original JSON `routes/auth.php` endpoints for literal HTML-form
  POSTs — no browser client calls them as a form today
- `git init`, CI actually running on GitHub, pushing anywhere — unchanged from
  Block 4, not touched by this slice

---

## Definition of done

- [x] Real browser login works on all 3 Filament panels, tenant-scoped
- [x] `RebindsTenant` has test coverage, both directions
- [x] Courses have a `status`; only `published` streams for students (gate itself lands Block 2)
- [x] Teacher uploads a video to a lesson; non-video files rejected
- [x] Student streams a lesson via signed, short-lived URL; cross-tenant access
      blocked; instructor can preview their own draft
- [x] Free-preview lessons playable without login; others require the role gate
- [ ] Player: 70% default volume, ±10s seek, 0.5×–2× speed, watermark visible +
      repositioning every 30s, survives fullscreen
- [x] Resume-across-devices verified end to end (server-side: heartbeat write + `MAX(end_s)` read; client-side seek-on-load not visually checked, see Block 3 note)
- [x] Drip-scheduled lessons hidden from students until due, visible (badged)
      to teachers
- [x] Bandwidth metered per tenant per day, zero enforcement
- [x] Isolation suite green with both new models registered
- [x] Full test suite green
- [ ] Demo #2 deliverable on 2026-09-05: student opens a lesson, watches ~10s,
      reloads, resumes

---

## Post-S2 review (2026-08-09)

Full review of blocks 0-7 after Block 7 closed, hunting real bugs rather than
style. Method: probe the paths the per-block tests never touched — real HTTP
requests instead of in-process component calls, unhappy inputs, and the
surfaces each block added but never exercised. Eight real defects, all fixed
and covered by tests; four more items reviewed and deliberately left alone.

Suite went 89 → 106 passing.

### Round 1 — access gates and wiring

1. **Fixed (critical) — every Livewire action ran with no tenant context.**
   Livewire registers its own update endpoint, and `php artisan route:list
   --json` showed it as `livewire-<hash>/update` with `domain: NULL` and
   `middleware: ["web","RequireLivewireHeaders"]`. No `resolve.tenant`. Every
   Livewire interaction in the app posts there: all three Filament panel
   logins, the student portal, the lesson player. Two consequences, both real:
   `Login::authenticate()` (all three panels) calls
   `app(CurrentTenant::class)->id()`, which throws `MissingTenantContextException`
   on an unbound tenant — that exception has no `render()` mapping in
   `bootstrap/app.php`, so it surfaces as a 500. And `BelongsToTenant`'s global
   scope only filters `if (... ->isBound())`, so with no tenant bound it
   silently stops filtering while Livewire re-hydrates model properties —
   the same "scope silently omits the filter" failure family as the S1
   round-1 `/super` leak, reached through a different door.
   **Why the whole suite missed it:** `Livewire::test()` runs the component
   in-process with the tenant already bound by the test's own `beforeEach`.
   It never issues the HTTP request that would have exposed the gap, so
   Block 0's "logs in through a real login form, not actingAs" tests were
   testing the component, not the route. The isolation suite could not catch
   it either — it drives models directly, never Livewire.
   **Fix:** `ResolveTenant` gained an `optional` mode (bind when the host maps
   to a tenant, stay silent when it doesn't, still reject suspended tenants),
   appended to the `web` middleware group in `bootstrap/app.php`. Appended
   rather than prepended so it runs after `StartSession` — the suspended-tenant
   path flushes the session. The bare platform host (super panel, `tenant_id`
   null by design) legitimately resolves to no tenant, which is why the mode
   must not 404.
   **New tests:** `tests/Feature/Tenancy/LivewireTenantContextTest.php` — the
   update route stays in the `web` group, a `web` route on a tenant subdomain
   binds `CurrentTenant`, the bare host leaves it unbound instead of 404ing,
   and a suspended tenant is still rejected.

2. **Fixed (high) — the player page had no gate at all, leaking draft lesson
   titles to anonymous visitors.** Block 3 added `GET /lessons/{lesson}` as a
   bare closure that did `Lesson::findOrFail()` and rendered the view. Block 2's
   gate lived inline inside `StreamLessonController` and was never re-applied
   here. Probe: an unauthenticated request for a lesson in a `draft` course
   returned 200 with the lesson title in the body. The video bytes were still
   safe (the stream route gates separately), but the titles of unpublished and
   unreleased lessons were readable by anyone with the URL.
   **Fix:** extracted the gate into `app/Domains/Content/Services/LessonAccess.php`
   and gave the page a real controller (`WatchLessonController`). Stream, page,
   and heartbeat now all call the same class, so they cannot drift apart again.

3. **Fixed (medium) — a lesson with no stored file 500'd instead of 404ing.**
   The gate checked `video_status === 'ready'` but never checked
   `video_disk_path`. A `failed` upload leaves the status set and the path
   null, and staff bypass the status check to preview their own work, so an
   instructor opening such a lesson reached
   `Storage::disk('local')->path(null)` — the bare disk root — and got a
   `TypeError` 500. Probe confirmed: status=500, `TypeError`.
   **Fix:** `LessonAccess::isPlayable()` requires both `video_status ===
   'ready'` and a non-empty `video_disk_path`, and it now applies to staff too.

4. **Fixed (medium) — `/teach/lessons/create` 500'd on submit.** `LessonResource`
   carried a create page from S1's Block 9, and S2's Block 1 added a video
   upload field to that same form, making it look usable. The form has no
   `course_section_id` field, so submitting it violated the NOT NULL
   constraint. Probe: `SQLSTATE[23502] null value in column "course_section_id"`.
   **Fix:** removed the create page and its `CreateLesson` class. Lessons are
   created through `LessonsRelationManager`, which supplies the parent section.
   The edit page stays (per `CLAUDE.md`'s child-resource rule).

5. **Fixed (medium) — saving a video from the lesson edit page 500'd.**
   `EditLesson::afterSave()` read `$this->data['video_upload']`. That is raw
   Livewire component state, where a `FileUpload` is a uuid-keyed array, not
   the dehydrated disk-path string the relation manager's action receives.
   Probe: `TypeError: attachUploadedVideo(): Argument #1 ($tempDiskPath) must
   be of type string, array given`. Block 1's own test only covered the
   relation-manager path, which is why this shipped green.
   **Fix:** capture the path in `mutateFormDataBeforeSave()` (dehydrated data)
   and use it in `afterSave()`.

6. **Fixed (low, data integrity) — the heartbeat endpoint had no gate.** Any
   authenticated tenant user could POST watch progress for any lesson in the
   tenant, including draft-course and future-scheduled ones they cannot watch.
   **Fix:** `WatchHeartbeatController` now runs the same `LessonAccess` checks
   as the stream before writing a row.

### Round 2 — re-review after the round 1 fixes

7. **Fixed (medium) — bandwidth was over-counted on every Range request.**
   `response()->file()` returns a `BinaryFileResponse`, which honours HTTP
   Range, and a `<video>` element seeking through a lesson issues many partial
   requests. The meter charged `filesize($path)` on each one, so a single
   watch billed the tenant for the whole file many times over. Measured: a
   `bytes=0-99` request against a 1000-byte file metered 1000 bytes.
   **Fix:** `StreamLessonController::bytesServed()` parses the Range header
   (including the `bytes=-N` suffix form) and meters the slice actually sent.
   **New test:** partial-range request meters exactly 100 bytes.

8. **Fixed (medium) — soft-deleting a section or course 500'd its lessons.**
   `LessonAccess::isLive()` walked `$lesson->section->course`. Sections and
   courses soft-delete while their lessons keep their own rows, so both
   relations read null and the walk raised `Attempt to read property "course"
   on null`. A teacher deleting a course handed every student holding a lesson
   URL a 500 instead of a 404. Probe confirmed both cases.
   **Fix:** null-safe traversal plus an explicit rule in `isVisibleTo()` — a
   trashed parent takes its lessons down for staff as well, rather than
   leaving them half-alive behind a stale URL.

### Reviewed, not changed

- **`BandwidthUsageDaily::record()` looked like a lost-update race.**
  `firstOrCreate()` under two concurrent streams could collide on the
  `(tenant_id, date)` unique index. Checked the framework rather than
  guessing: `Illuminate\Database\Eloquent\Builder::firstOrCreate()` delegates
  to `createOrFirst()`, which catches the unique-constraint violation and
  re-reads. Not a bug.
- **The player's Alpine code looked unreachable.** `content/watch.blade.php`
  has no `@vite` and `resources/js/app.js` is empty, so `x-data` looked dead.
  Checked `vendor/livewire/livewire/dist/livewire.js`: Alpine is bundled, so
  `@livewireScripts` is sufficient. No change needed.
- **Tenant resolution now runs twice per tenant-routed request** (once from
  the `web` group in `optional` mode, once from the route group's strict
  `resolve.tenant`). The obvious fix — an early return when `CurrentTenant`
  is already bound — was rejected: `CurrentTenant` is a singleton that tests
  bind directly in `beforeEach`, so an early return would let a cross-tenant
  HTTP test keep the wrong tenant bound and quietly pass a test that exists
  to catch leaks. One extra indexed lookup per request is the cheaper trade.
- **Two dead ends from Block 1, left as known gaps, not fixed.**
  `lessons.video_duration_seconds` is in `$fillable` and nothing ever writes
  it (populating it needs ffprobe, which is out of S2's scope), and
  `VideoProvider::delete()` has no caller, so a deleted lesson leaves its file
  on disk. The second is arguably correct while deletes are soft and
  restorable; both belong with the real `BunnyDriver` fast-follow.

### Still not verified

The player's browser-only behaviour — volume defaulting to 70%, the watermark
actually repositioning, fullscreen keeping it visible — remains implemented to
spec but never eyeballed in a real browser, unchanged from Block 3's note. Every
server-side contract around it is now tested; the visual behaviour is not.

### Round 3 (2026-08-10) — pre-S3 pass, alongside the S1 review above

Run together with the S1 plan's own Round 3 (see that file for the
S1-scoped findings — the cross-tenant `EnsureRole` gap, the
`CourseSectionResource` create-page crash, and the student portal lockout).
This round's S2-scoped finding:

1. **Fixed (minor) — the watch heartbeat didn't actually use "the same gate
   as the stream itself," despite the comment saying so.**
   `WatchHeartbeatController.php:22-25` called `isVisibleTo()` and
   `canStream()` but not `isPlayable()`, which `StreamLessonController.php:27`
   does apply. A lesson with a failed or still-processing upload
   (`video_status` not `ready`, or no `video_disk_path`) could still collect
   watch-progress events even though no video was ever served for it —
   silently diverging from the single-gate design `LessonAccess` exists to
   enforce.
   **Fix:** added the missing `abort_unless($access->isPlayable($lesson),
   404)` call, matching the stream controller exactly.
   **Test fix:** `WatchHeartbeatTest`'s `heartbeatLesson()` helper set
   `video_status: 'ready'` but never wrote an actual file or
   `video_disk_path` — passed before this fix only because the check didn't
   exist yet. Updated to fake-store a video file first, same pattern
   `LessonPlayerTest` already uses.

Full suite after round 3 (S1 + S2 findings combined): 109/109 passing.
