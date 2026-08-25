# Project Status — AI-Native LMS

Last updated: 2026-08-17. This file is a plain-English snapshot of where the
whole project stands. For the technical detail behind any line here, see
`docs/superpowers/specs/2026-08-08-s1-foundation-design.md` (the full S1
design) and `docs/superpowers/specs/2026-08-09-s1-implementation-plan.md`
(the S1 block-by-block build log, including the two bug-review rounds) for S1;
`docs/superpowers/specs/2026-08-09-s2-content-video-design.md` (the full S2
design) and `docs/superpowers/specs/2026-08-09-s2-content-video-plan.md` (the
S2 block-by-block build log) for S2;
`docs/superpowers/specs/2026-08-10-s3-ai-spine-design.md` (the full S3
design) and `docs/superpowers/specs/2026-08-10-s3-ai-spine-plan.md` (the S3
block-by-block build plan) for S3;
`docs/superpowers/specs/2026-08-10-s4-batches-enrolment-design.md` (the full
S4 design) and `docs/superpowers/specs/2026-08-10-s4-batches-enrolment-plan.md`
(the S4 block-by-block build plan) for S4;
`docs/superpowers/specs/2026-08-10-s5-live-classes-design.md` (the full S5
design) and `docs/superpowers/specs/2026-08-10-s5-live-classes-plan.md` (the
S5 block-by-block build log) for S5;
`docs/superpowers/specs/2026-08-11-s6-assessments-design.md` (the full S6
design) and `docs/superpowers/specs/2026-08-11-s6-assessments-plan.md` (the
S6 block-by-block build log) for S6;
`docs/superpowers/specs/2026-08-11-s7-fees-design.md` (the full S7 design)
and `docs/superpowers/specs/2026-08-11-s7-fees-plan.md` (the S7
block-by-block build log) for S7;
`docs/superpowers/specs/2026-08-12-s8-comms-design.md` (the full S8 design)
and `docs/superpowers/specs/2026-08-12-s8-comms-plan.md` (the S8
block-by-block build log) for S8;
`docs/superpowers/specs/2026-08-12-s9-hardening-backlog-design.md` (the
full S9 design) and `docs/superpowers/specs/2026-08-12-s9-hardening-backlog-plan.md`
(the S9 block-by-block build log) for S9 — COMPLETE (all 15 blocks), and
not part of the original 8-slice roadmap below.

---

## The roadmap: eight sub-projects (S1–S8)

The whole P1 build ("Institute Zero" — the first paying-customer milestone)
is split into eight two-week sub-projects. Each one ends in a demo. This is
the full roadmap, in order — **S9 (below, in its own section) is a later
addition, not part of this original eight:**

| # | Sub-project | What it covers | Demo date | What the demo actually shows |
|---|---|---|---|---|
| **S1** | **Foundation** | Multi-tenancy, auth, roles, branding, isolation suite | **2026-08-22** | Their own branded institute; login; course tree |
| S2 | Content + Video | HLS video player, watermarking, watch-progress tracking | 2026-09-05 | A student watching a lecture, resuming across devices |
| S3 | AI Spine | `lesson_artifacts` pipeline, lecture ingestion, doubt chat | 2026-09-19 | Upload a lecture → get a transcript, concepts, an answered doubt |
| S4 | Batches + Enrolment | Batches, seats, waitlist, timetable | 2026-10-03 | One batch, ten students enrolled |
| S5 | Live Classes | Topic-anchored live sessions, attendance | 2026-10-17 | Join a live class from the syllabus tree; recording attaches back to that topic |
| S6 | Assessments | Question bank, AI-generated paper, quiz runner, grading | 2026-10-31 | A question paper generated from the teacher's own notes |
| S7 | Fees | Razorpay, instalments, GST invoicing, defaulter tracking | 2026-11-14 | Fees collected, an instalment schedule, a reminder firing |
| S8 | Comms | WhatsApp BSP, parent portal, dashboards, reports | 2026-11-28 | A parent's phone receiving an absence message |

**Where we are right now: S1 is done. S2 is done. S3 is done (all 8 blocks). S4 is done (all 9 blocks, B0-B8). S5 is done (all 9 blocks, B0-B8). S6 is done (all 9 blocks, B0-B8). S7 is done (all 8 blocks, B0-B7). S8 is done (all 9 blocks, B0-B8) — the whole P1 "Institute Zero" roadmap is complete. S9 (Hardening & Backlog, a new slice not on the original roadmap) is also complete — all 15 blocks done, B0-B14.**

---

## S1 — Foundation: COMPLETE (all 12 blocks)

S1 was built as 12 small blocks, each one a working, tested slice. Every
block below is done, tested, and (where relevant) bug-reviewed twice.

| Block | What it built | Status |
|---|---|---|
| **B0** | Prerequisites — PHP 8.4 (native, via winget — not Docker, not 8.3), Composer, Docker Desktop for the database | ✅ Done |
| **B1** | Project scaffold — Laravel 13.24 + Filament 5.7, Postgres + Redis in Docker, `predis` instead of the Windows-incompatible `phpredis` extension | ✅ Done |
| **B2** | Domain-driven folder skeleton — code organised by domain (`app/Domains/Tenancy`, `app/Domains/Content`, …) instead of by technical layer | ✅ Done |
| **B3** | Tenancy core — the `tenants` table, `tenant_id` on every tenant-owned table, the `BelongsToTenant` trait that auto-scopes every query to the current institute | ✅ Done |
| **B4** | Cross-tenant isolation test suite — auto-generated from a model registry, so a new tenant-owned model that forgets to register itself fails the build instead of silently leaking data | ✅ Done |
| **B5** | Authentication — register, verify email, login, password reset, remember-me, rate limiting, account lockout after repeated failures | ✅ Done |
| **B6** | Roles — five roles (super admin → institute admin → instructor → student/parent), downward-only role assignment, every assignment attempt audited | ✅ Done |
| **B7** | Three admin panels wired up — `/super` (platform), `/admin` (institute), `/teach` (instructor), each gated so only the right role can enter | ✅ Done |
| **B8** | Student-facing shell — a minimal login + dashboard page, and white-label branding (logo, favicon, colours) applied consistently across all four surfaces | ✅ Done |
| **B9** | Content domain — Course → Section → Lesson structure, full CRUD in the `/teach` panel, with soft-delete and restore | ✅ Done |
| **B10** | `lesson_artifacts` contract — the database shape the AI pipeline (S3) will read from, pinned on paper now while it's free to change, with all eleven future consumer queries checked against it | ✅ Done |
| **B11** | Security baseline — CSRF protection, HTML sanitisation on rich-text fields, no raw SQL allowed in domain code (enforced by a test), explicit field allow-lists on every model | ✅ Done |
| **B12** | Documentation — `SETUP.md` (how to install and run this from scratch) and `CLAUDE.md` (how the codebase is organised) | ✅ Done |

**Test suite: 53/53 passing.**

### Two rounds of bug-hunting after the build

Once all 12 blocks were done, the whole codebase got reviewed twice for real
bugs (not style nits) — full detail with file:line references and evidence
is in the implementation plan's "Post-S1 review" section.

**Round 1 — found and fixed 2 real bugs:**
- **Cross-tenant data leak (serious):** the `/super` admin panel was showing
  *every institute's* courses to any super admin, unscoped, because a
  security check silently did nothing instead of blocking the request when
  no institute context was set. Fixed, and a test now guards against it
  coming back.
- **Wrong-panel access:** the `/admin` panel could also reach course
  management, which was only ever supposed to live in `/teach`. Fixed.

**Round 2 — went deeper into auth, migrations, branding — found and fixed 2 more:**
- **Soft-delete conflict:** deleting a course or a user and then trying to
  reuse the same slug/email failed with a database error, because the
  uniqueness rule didn't know about "deleted" rows. Fixed in both the
  database and the registration form.
- **Crash risk on a not-yet-used path:** building a password-reset/email-verify
  link for a platform-level super admin (who has no institute) would have
  silently misbehaved instead of failing with a clear error. Fixed to fail
  loudly with an explanation instead.

Nothing else turned up in either round — role hierarchy, the branding
service, and the panel access rules were checked carefully and are clean.

---

## S2 — Content + Video: COMPLETE (all 8 blocks)

Before writing S2's plan, S1 got re-audited for anything left half-finished.
Three real gaps turned up, verified against the running code, not just old
notes — all three got folded into a new "Block 0" rather than filed away for
later, since S2 needs them fixed to even be usable:

- **Nobody could log into any admin panel through a real browser.** `/teach`,
  `/admin`, and `/super` had no login page at all — every test so far reached
  them by faking a logged-in session, never a real one. Fixed with
  tenant-aware login pages on all three panels.
- **A safety check had never actually been tested.** The code that rebinds
  the correct institute when a background job runs had zero test coverage,
  because no background job existed yet to trigger it. Now covered.
- **Courses had no draft/published state.** A course was "live" for students
  the instant a teacher created it, even half-built. Courses can now be
  `draft`, `published`, or `archived`.

| Block | What it builds | Status |
|---|---|---|
| **B0** | S1 close-out — real panel logins, background-job safety test, course draft/published state | ✅ Done |
| **B1** | Video storage — a teacher uploads an `.mp4`/`.webm` to a lesson; stored locally for now behind a swappable driver (no paid video vendor needed yet) | ✅ Done |
| **B2** | Secure, time-limited video links — a signed URL expires after 10 minutes and 404s instantly if opened from a different institute | ✅ Done |
| **B3** | The actual video player — native `<video>`, custom controls (±10s seek, 0.5×–2× speed), a watermark with the viewer's name/email that drifts every 30s, survives fullscreen | ✅ Done |
| **B4** | Watch tracking — resume a lecture where you left off, on a different device, read from the database (not a cookie) | ✅ Done |
| **B5** | Bandwidth measurement per institute per day (measuring only — no cap, no billing yet) | ✅ Done |
| **B6** | Scheduled lesson release (hidden from students until the release time, badged for teachers), and free-preview lessons anyone can watch without logging in | ✅ Done |
| **B7** | Full test coverage + isolation-suite registration for everything above | ✅ Done |

**Test suite: 106/106 passing.**

### A review round after the build, like S1 got — 8 real bugs found and fixed

Once all 8 blocks were done, the whole slice was reviewed by actually poking
the paths the per-block tests never touched. Full technical detail with
evidence is in the S2 plan's "Post-S2 review" section. In plain English:

- **The most serious one: logging in was broken in a real browser, and the
  institute-isolation guard quietly switched itself off mid-request.** Every
  interactive page action in this app goes through one shared endpoint that
  Livewire (the UI framework) registers for itself. That endpoint was never
  told which institute the request belonged to. So all three admin logins
  would have errored in a real browser, and while an interactive page was
  working, the safety net that keeps one institute's data away from another
  stopped filtering. The tests missed it because they drove the page
  components directly in memory instead of over the network, where the gap
  lived. Fixed, and now guarded by tests that check the route itself.
- **Anyone with a link could read the titles of unfinished lessons.** The
  video itself was safe, but the player page had no permission check at all,
  so an unreleased or draft lesson's title was readable by a logged-out
  stranger. The permission rules now live in one place that the page, the
  video stream, and the progress tracker all share, so they can't drift apart
  again.
- **Bandwidth was being over-counted, badly.** Every time a student dragged
  the video scrubber, the institute got charged for the whole file again.
  Now only the part actually sent is counted.
- **Three separate "500 error" crashes**, each on a path a real user would
  hit: deleting a course or section left its lessons crashing instead of
  disappearing, a failed upload crashed the teacher's preview, and the
  standalone "new lesson" page crashed on save because it could not know
  which section the lesson belonged to.
- **Two smaller ones:** uploading a video from the lesson edit screen crashed,
  and watch-progress could be recorded for lessons the viewer wasn't allowed
  to watch.

Four more suspicions were checked and deliberately left alone, with reasons
recorded, rather than changed on a hunch.

**What's not real yet, on purpose:** video is served from local disk, not a
real video vendor (Bunny), and it's a plain progressive file, not adaptive
HLS — both arrive in a later fast-follow once a Bunny account exists. A
teacher can only reach the upload/scheduling controls through the `/teach`
panel's course→section→lesson tree; there's no student-facing course catalog
page yet (that's S3/S4 territory) — a student reaches a lesson today via a
direct link, which is enough for demo #2's script. The player's JS-only
behaviour (volume defaulting to 70%, the watermark actually drifting,
fullscreen) is implemented per spec but has not been eyeballed in a real
browser this session — everything server-testable is test-covered.

**Demo #2 target: 2026-09-05** — a student opens a lesson, watches a few
seconds, reloads the page (or opens it on another device), and picks up
right where they left off.

### A third review round (2026-08-10) — 5 more bugs found and fixed

Before starting S3, S1+S2 got a third full pass, focused on paths the first
two rounds and the per-block tests still didn't reach. Full detail with
file:line references is in the S1 plan's "Post-S1 review" and S2 plan's
"Post-S2 review" sections.

- **The most serious one: a logged-in user could reach a *different*
  institute's `/teach` or `/admin` panel just by opening its URL.** Roles
  (instructor, institute admin) are deliberately global to a user, not
  per-institute — and the session cookie is shared across every
  `*.lms.test` subdomain by design (so one login works across all three
  panels). Nothing ever checked that the logged-in user's *own* institute
  matched the institute implied by the subdomain they were currently on. An
  instructor at institute A who simply visited institute B's `/teach` URL
  passed the role check and landed fully scoped to institute B's courses and
  lessons. Fixed in the one shared role-gate middleware, so it can't
  reappear on a new panel later; a regression test now covers exactly this
  cross-subdomain-session case.
- **A dead-end admin form crashed the same way `LessonResource`'s did in the
  S2 review.** `CourseSectionResource` still had a standalone "create"
  page whose form couldn't supply the course it belonged to, so submitting
  it threw a database error instead of saving. Removed, matching the fix
  already applied to lessons; sections are created through the course's own
  screen, which does supply the parent.
- **The student login page had no lockout.** Every other login in the app
  (all three staff panels) goes through one shared rate-limited login
  service; the student/parent portal quietly used its own plain check
  instead, so it was the only login surface open to unlimited password
  guessing. Now routed through the same shared service.
- **The student login page didn't rotate the session on login or logout.**
  A session id from before login stayed valid after logging in, and a
  logout didn't fully invalidate it — a small window for session theft on a
  shared machine. Fixed to match every other login/logout path in the app.
- **Watch-progress could be logged for a lesson with no playable video** (a
  failed or still-processing upload) — the heartbeat endpoint's comment
  claimed it used "the same gate as the video stream" but was actually
  missing one of the three checks. Now genuinely identical to the stream's
  gate.

**Test suite: 109/109 passing** (3 new regression tests added for the
cross-institute and login-lockout fixes).

**Reviewed, not changed:** a sixth suspected bug — `BandwidthUsageDaily::record()`
racing under concurrent requests — was checked against the framework source
rather than assumed. `firstOrCreate()` already falls through to
`createOrFirst()`, which does catch the unique-constraint race, confirming
the S2 round-2 review's own note on the same line. No change needed.

---

## S3 — AI Spine: COMPLETE (all 8 blocks)

The one real decision blocking S3 (which embedding model, since the vector
column's dimension is a one-way door once the first lesson is ingested) was
delegated to the builder rather than left open: `text-embedding-3-small`,
1536 dimensions, recorded in the S3 design doc §2.4. Everything else in this
slice was buildable and testable against `FakeAiDriver` — no AI vendor API
key exists yet, same call S2 made for video (no Bunny account yet either).

| Block | What it builds | Status |
|---|---|---|
| **B0** | Pre-S3 close-out — embedding model decision, `pgvector` extension enabled, `RebindsTenant` gap flagged in the third S1/S2 review round closed for real | ✅ Done |
| **B1** | `AiProvider` interface + `FakeAiDriver` — deterministic, hash-seeded transcribe/chat/embed, same swappable-driver shape as S2's `VideoProvider` | ✅ Done |
| **B2** | `lesson_artifacts` / `lesson_artifact_chunks` schema — exactly as pinned on paper in S1, an HNSW pgvector index for similarity search | ✅ Done |
| **B3** | The ingestion pipeline — a lecture upload fires the first real cross-domain event in the codebase (`LessonVideoReady`, Content → AI), a 4-job chain transcribes, chunks, extracts concepts, and embeds it | ✅ Done |
| **B4** | `/teach` ingestion status — a badge (pending/processing/ready/failed), a transcript+concepts viewer, a manual retry for a failed run | ✅ Done |
| **B5** | Doubt chat — a student asks a question about a lecture, gets an answer grounded only in that lecture's own content, with a clickable timestamp citation | ✅ Done |
| **B6** | AI spend measurement per institute per day (measuring only — no cap, no billing yet, same posture as S2's bandwidth metering) | ✅ Done |
| **B7** | Full test coverage, isolation-suite registration, a bug-hunting review pass, this summary | ✅ Done |

**Test suite: 137/137 passing.**

### Bug-hunting pass after the build — 1 real bug found and fixed

Following the same practice as S1 (two rounds) and S2 (one round): once all
8 blocks were done, the paths the per-block tests didn't reach got a
deliberate second look rather than trusting green tests alone. Full detail
with file:line references is in the S3 plan's "Post-S3 review" section.

- **Retrying a failed lecture ingestion silently duplicated every chunk of
  that lecture.** The retry action re-runs the whole 4-job pipeline from the
  start, including the job that splits a transcript into searchable
  chunks — but that job only ever *added* rows, it never cleared the
  previous attempt's. So retrying a run that failed at, say, the
  concept-extraction step (which happens *after* chunking already
  succeeded) left two full copies of every chunk behind: doubled embedding
  work, and doubt-chat answers built from duplicate, near-identical
  passages. This was reachable through the retry button shipped in Block 4,
  not a hypothetical — fixed to clear a lecture's existing chunks before
  rebuilding them, and a regression test now asserts a retried run ends
  with the same chunk count as before, not double.

**Round 2 review (2026-08-10):** full re-read of every B0-B7 file plus a
fresh `composer test` — 137/137, zero regressions, no new bug. Three items
checked and deliberately left as-is (`TokenCostLedger` date-buckets by
artifact-creation day not completion day, doubt chat's "still processing"
placeholder wording for a permanently-failed lesson, an N+1 read in
`DoubtChat::citationsFor()`) — none are correctness or access-control bugs,
detail in the S3 plan's "Post-S3 review, round 2" section.

**What's not real yet, on purpose:** no real AI vendor (transcription or
chat) is wired up — `FakeAiDriver` is deliberately the only driver, a
fast-follow once a vendor and API key are chosen. Doubt chat only answers
from the single lesson a student is currently viewing, not across a whole
course. Nine of the eleven consumers `lesson_artifacts` was designed to
support (question papers, revision notes, weak-topic detection, WhatsApp
nudges, and others — see the S3 design doc §2.2) are S6/S8 scope, not S3's;
the schema was built to support them later without another migration.

**Demo #3 target: 2026-09-19** — upload a lecture, watch its status go
pending → ready, read the generated transcript and concepts, then ask it a
question and get an answer with a clickable timestamp citation.

---

## S4 — Batches + Enrolment: COMPLETE (all 9 blocks)

| Block | What it built | Status |
|---|---|---|
| **B0** | Domain scaffold (`app/Domains/Batches/`) + three scope decisions recorded | ✅ Done |
| **B1** | `Batch` model + CRUD in `/admin` (`BatchResource`) | ✅ Done |
| **B2** | `BatchSession` + session scheduling (F5), 15–480 minute validation | ✅ Done |
| **B3** | `Enrolment` core — `BatchEnrolmentService`, all three enrol modes (manual, self-enrol direct, self-enrol approval), seat-race protection | ✅ Done |
| **B4** | Waitlist + FIFO auto-promotion on cancellation, `StudentEnrolled`/`SeatReleased` events | ✅ Done |
| **B5** | Approval expiry — first use of Laravel's task scheduler in this codebase, first `Notification` class, 7-day auto-expiry with a "request expired" notice | ✅ Done |
| **B6** | `attendance_records` schema pin — migration + model, no marking UI yet (decision 2) | ✅ Done |
| **B7** | Student batch catalog + self-enrol UI — real `/batches` page, not a stub (decision 3) | ✅ Done |
| **B8** | Full test coverage, isolation registration, review pass | ✅ Done |

**Test suite: 208/209 passing.** Same 1 pre-existing failure carried since
S1/S2 (`CourseSlugUniquenessTest`, unrelated to S4, still out of scope).

### Bug-hunting pass after the build — 1 real bug found and fixed

Same practice as S1 (×2 rounds), S2 (×1 round), S3 (×2 rounds): once all 9
blocks were done, every file got read fresh rather than trusting green
tests alone. Full detail with file:line references is in the S4 plan's
"Post-S4 review" section.

- **Approving a pending enrolment request could overfill a batch's seat
  limit.** The seat-race guard built in Block 3 only covered students
  joining a batch directly and instantly (`self_enrol_direct`) — it never
  covered staff working through a queue of approval requests
  (`self_enrol_approval`). A batch capped at, say, 10 seats could end up
  with 15 active enrolments if staff simply approved 15 pending requests one
  after another, since nothing checked remaining capacity at approval time.
  Fixed: approving now takes the same seat-locking check joining directly
  already had, and refuses (rather than silently overfilling) once the
  batch is full.

Four other suspicions were checked against the actual code — the daily
expiry job's tenant-unscoped sweep, staff manually adding a named student
past capacity (intended, not a bug), cross-tenant access to the new admin
screens, and the catalog page's own tenant scoping — and deliberately left
alone, with reasons recorded in the plan doc.

### Second bug-hunting round — 5 more real bugs found and fixed

A second, independent pass (fresh eyes, no assumption the first round caught
everything) found five more, all fixed with regression tests. Full detail:
S4 plan doc's "Post-S4 review" section.

- **A student could self-enrol into a draft batch, or one whose course
  wasn't published, by calling the catalog's `join()` Livewire method
  directly with a forged batch ID.** `/batches` only *displays* published
  batches, but the actual enrolling method never re-checked that — `canEnrol()`
  existed to answer that question but `enrol()` never called it. Fixed:
  `enrol()` now refuses a batch that isn't open, the same rule `canEnrol()`
  already advertised.
- **Cancelling an active enrolment (and the waitlist promotion it triggers)
  ran with no row lock**, unlike every other seat-consuming path
  (`enrolDirect()`, `approve()`). Concurrent cancellations, or a cancellation
  racing a direct join for the same freed seat, could double-promote one
  waitlisted student while skipping the next, or push active count past
  `seat_limit`. Fixed: `cancel()` now takes the same `lockForUpdate()`
  transaction the other two do.
- **The daily approval-expiry sweep could clobber a same-moment approval.**
  It loads every past-due `pending_approval` row, then updates each one
  unconditionally — if staff approved a request in the gap between that load
  and this job reaching that row, the job overwrote the approval back to
  `expired`. Fixed: each row is re-checked as still `pending_approval` under
  a lock immediately before it's written.
- **Waitlist FIFO order and the position shown to students could be wrong
  under real concurrent joins.** `enrolments.created_at` turned out to be
  whole-second precision in this app's Postgres schema (no explicit column
  precision was ever set) — confirmed via `information_schema`. Two students
  joining in the same second (exactly what "ten students, one batch" can
  produce) tie on `created_at`, so both promotion and the position display
  had no deterministic order for the tie. Fixed: both now break the tie by
  `id` (insertion order), which is where the existing FIFO tests were
  already quietly relying on `travelTo(now()->addSecond())` between joins to
  dodge the same tie.
- **Manually adding a student from the relation manager's "Add student"
  action skipped `EnrolmentApprovalPolicy` entirely**, unlike approve/reject/
  cancel on the same screen. Not currently reachable by an unauthorized
  actor (the `/admin` panel itself already restricts to `institute_admin`),
  but inconsistent with "every enrolment-mutating action goes through the
  policy." Fixed for consistency and to not depend on the panel gate being
  the only thing stopping a future misuse.

`BatchSessionsRelationManager`'s create-time position tie-break, previously
recorded in the first review round as "a known gap... not re-fixed here,"
was re-checked and found **not actually a live gap**: the relation manager's
`CreateAction` already sets `position` to `max + 1` via `mutateFormDataUsing`
(same pattern `SectionsRelationManager`/`LessonsRelationManager` use), and
`BatchSessionTest`'s own B2 test already proves it (asserts positions `[1,
2]`, not `[0, 0]`). The first round's characterization was stale relative to
the code it was describing; corrected in the plan doc's review section.

**What's not real yet, on purpose:** F7 (timetable templates + teacher
conflict detection) is deferred to a fast-follow, same call S2 and S3 made
for their own P2 halves. `attendance_records` exists as a migration only —
no instructor-facing marking screen, that's S5. Real payment collection for
paid batches (`is_paid`, `price` are stored) is S7 scope.

**Demo #4 target: 2026-10-03** — one batch, ten students enrolled: an
institute admin creates a free batch capped at 10 seats, ten students join
from `/batches` and land active, an eleventh lands waitlisted, cancelling
one of the ten promotes the waitlisted student in the same request.

---

## S5 — Live Classes: COMPLETE (all 9 blocks)

Six decisions locked before the block plan: live-class join requires an
active batch enrolment (new `LessonAccess::canJoinLive()` gate — the first
non-role-only lesson-visibility rule); `BatchSession` gains a `lesson_id`
FK so one topic-anchor `Lesson` fans out into one occurrence per batch,
rather than `Lesson` itself carrying a `batch_id`; both
`ExternalLinkProvider` and a real `ZoomVideoSdkProvider` get built in S5
(Zoom via `FakeLiveClassDriver` — no real account exists yet, same posture
S2/S3 shipped with for their own vendors); a `live_session`-scoped
completion flag ships, not the general chapter/syllabus-completion feature
FEATURES.md's G0d implies (nothing tracks that anywhere else in the app
yet); rotating-QR check-in (H3) is deferred alongside the live-webhook
attendance writer; no new domain folder — the work rides entirely on the
existing Content (`Lesson`) and Batches (`BatchSession`) domains plus a new
`Integrations/LiveClassProvider` interface.

| Block | What it built | Status |
|---|---|---|
| **B0** | Domain groundwork — additive `batch_sessions` migration, `LiveClassProvider` interface + `FakeLiveClassDriver` | ✅ Done |
| **B1** | `ExternalLinkProvider` (real, zero-setup) + room creation on scheduling, `LiveSessionRoomCreated` event | ✅ Done |
| **B2** | SCHEDULED/LIVE/ENDED/RECORDED state derivation + a brand-new student-facing syllabus tree (nothing existed under that name before this block) | ✅ Done |
| **B3** | `LessonAccess::canJoinLive()` — the formal join gate — + `JoinLiveClassController` | ✅ Done |
| **B4** | `ZoomVideoSdkProvider` — real, hand-rolled JWT session signing, unit-testable with no live Zoom account | ✅ Done |
| **B5** | Teacher bulk attendance marking (H2) — default-all-present, tap absentees | ✅ Done |
| **B6** | Manual recording attach — reused S2's upload flow after fixing a form gap that blocked it entirely for `live_session` lessons | ✅ Done |
| **B7** | Async video-coverage attendance (H6) — a scheduled job, after fixing a second gap: the field its whole formula depends on was never populated anywhere | ✅ Done |
| **B8** | Full test coverage, isolation registration, review pass | ✅ Done |

**Test suite: 283/284 passing.** Same 1 pre-existing failure carried since
S1/S2 (`CourseSlugUniquenessTest`, unrelated to S5, still out of scope).

### Two real gaps caught mid-build by "verify, don't assume" — before the review pass even started

Blocks 6 and 7 were each scoped as "wire up something that should already
work end to end," and in both cases that premise turned out to be false in
a way that would have shipped a dead feature if taken on faith:

- **B6:** a teacher had no way to attach a recording to a `live_session`
  lesson at all. The upload field's visibility check only ever showed for
  `type === 'video'`; the underlying storage code was correctly
  type-agnostic, but the form never let a `live_session` lesson reach it.
  Fixed with a two-word widening of the visibility check, then verified
  (not assumed) that the AI ingestion pipeline behind it also runs clean
  against a non-video lesson type for the first time ever.
- **B7:** the ≥80% video-coverage attendance job's whole formula divides by
  `Lesson.video_duration_seconds` — a column that had been `fillable` since
  S2 but that nothing, anywhere in the app, had ever actually written to.
  Shipped as originally scoped, the job would have silently skipped every
  lesson forever. Fixed by having the existing watch-progress heartbeat
  also report the video's own duration the first time it's seen.

### Bug-hunting pass after the build — 1 real bug found and fixed

Same practice as S1 (×2 rounds), S2 (×1 round), S3 (×2 rounds), S4 (×2
rounds): once all 8 build blocks were done, every file got read fresh
rather than trusting green tests alone. Full detail with file:line
references is in the S5 plan's "Post-S5 review" section.

- **A draft course's title and section names were readable by any guest who
  knew or guessed its URL.** The new student-facing syllabus tree (Block 2)
  correctly hid individual lesson rows from an unpublished course, but the
  page wrapping it never checked the *course* itself was published before
  rendering its title and section names — the same category of leak
  `LessonAccess`'s own history warns about, one level up, that this slice's
  own gate (`isVisibleTo()`) doesn't cover because it's lesson-scoped, not
  course-scoped. Fixed with a new `LessonAccess::isCourseVisibleTo()`
  (same staff-bypass posture as every other gate in that class) checked
  before the syllabus page renders anything.

One more item was checked and deliberately left alone: `canJoinLive()`
doesn't re-check the owning course is still published, only that the
viewer's batch enrolment and the session's live window are both valid. An
already-enrolled student could in theory retain a working join link via a
stale bookmark if a course were unpublished after they enrolled — a narrow
edge case (requires a rare admin action plus a pre-existing bookmark), not
a new information disclosure to strangers, so left as a documented
limitation rather than built out for a demo that doesn't require it.

**What's not real yet, on purpose:** no real Zoom account exists, so
`ZoomVideoSdkProvider` is real and unit-tested but not the bound driver —
picking "Zoom Video SDK" in the scheduling form today safely degrades to
`FakeLiveClassDriver`'s deterministic behaviour. BigBlueButton stays out of
scope entirely (ops cost not justified solo). Automatic recording capture
is deferred — attach is manual, ingestion afterward is automatic (free,
reused from S3). Rotating-QR check-in and the live-webhook attendance
writer both wait on a webhook-capable provider that doesn't exist yet.
General chapter/syllabus completion tracking doesn't exist anywhere in the
app — S5 ships a `live_session`-scoped attendance-backed flag only, read
by nothing yet (built ahead of its first consumer, same posture
`LiveSessionRoomCreated` has had since Block 1).

**Demo #5 target: 2026-10-17** — a student opens the syllabus tree, sees a
topic in `LIVE` state, joins from that node (not a calendar), the
instructor marks the roster present/absent, the instructor uploads the
recording after class, and the same node now shows a player — with the
recording already queued into the existing AI ingestion pipeline.

## S6 — Assessments: COMPLETE (all 9 blocks)

New `app/Domains/Assessments/` domain (`Question`/`Quiz`/`QuizAttempt`/
`QuizAnswer`), built on two contracts pinned but unused since earlier
slices: `Lesson.type` already had `'quiz'` (S1), and
`lesson_artifacts.seed_questions` was already populated by S3's
`ExtractLessonConcepts` with nothing reading it — S6 is its first consumer.
Six decisions locked before the block plan: a new domain rather than
folding into Content/Batches; a quiz anchors to a `quiz`-type `Lesson`
(`Quiz.lesson_id`, same topic-anchor shape S5 used for
`BatchSession.lesson_id`); a quiz attempt's question set is drawn fresh per
attempt, not fixed on the quiz, to satisfy "no two consecutive identical
sets"; AI generation reuses `seed_questions` first, falls back to a fresh
`chat()` call only when the blueprint asks for more; auto-submit is a daily-
scale scheduled sweep, not a per-attempt queued job (though built at
`everyFiveMinutes()`, not daily — see B7 below); assignments/gradebook/
certificates are explicitly not S6.

| Block | What it built | Status |
|---|---|---|
| **B0** | Domain groundwork — `questions`/`quizzes`/`quiz_attempts`/`quiz_answers` migrations + models, tenant registry | ✅ Done |
| **B1** | Question bank CRUD (I1) — manual authoring in `/teach`, MCQ/true-false/short-answer/numerical | ✅ Done |
| **B2** | AI question paper generation (L5) — `QuestionGenerator`, `seed_questions`-first then blueprint `chat()` fallback | ✅ Done |
| **B3** | Review/edit/approve gate (L6) — draft → approved workflow | ✅ Done |
| **B4** | Quiz assembly (I2) — blueprint-driven per-attempt draw, non-repeat rule | ✅ Done |
| **B5** | Quiz runner UI — `QuizRunner` Livewire component, wired into the syllabus tree's `quiz` branch | ✅ Done |
| **B6** | Auto-grading (I4) + short-answer review queue (I5) | ✅ Done |
| **B7** | Time-limit auto-submit sweep (I3) — `AutoSubmitExpiredQuizAttempts` | ✅ Done |
| **B8** | Quiz performance trend (M9), full test coverage, isolation registration, review pass | ✅ Done |

**Test suite: 356/357 passing.** Same 1 pre-existing failure carried since
S1/S2 (`CourseSlugUniquenessTest`, unrelated to S6, still out of scope).

### Bug-hunting pass after the build — 3 real bugs found and fixed

Same practice as every prior slice: once all 9 blocks were done, every file
got read fresh rather than trusting green tests alone. Full detail with
file:line references is in the S6 plan's "Post-S6 review" section.

- **A retired question silently under-scored a student's in-flight
  attempt.** Grading correctly skips a question that's been soft-deleted
  since it was drawn (nothing left to grade), but the attempt's maximum
  achievable score was never adjusted down to match — a student's genuinely
  perfect run on the remaining questions would show as short of full marks
  with no explanation anywhere. Fixed: the maximum score is now recomputed
  from the questions actually gradable at submit time, not the count fixed
  when the attempt started.
- **Deleting a quiz could silently destroy every student's graded history
  for it, in one click, with no way back.** Unlike question bank rows
  (soft-deleted on purpose, specifically so historical answers keep their
  reference), a quiz was never given the same protection — and by the time
  quizzes had real attempts attached to them (several blocks after the
  delete button was built), nothing had gone back to guard it. Fixed: a
  quiz can only be deleted once it has zero attempts.
- **A partially-credited short answer displayed as a flat "Correct"** in
  the student's own results view, indistinguishable from a fully-correct
  answer. The underlying score was always accurate; only the label
  overstated it. Fixed to show the real score instead of a binary label.

**What's not real yet, on purpose:** no real AI vendor for question
generation (`FakeAiDriver` only, same posture every prior slice shipped
with). Extended question types, proctoring, ranked test series, and OMR
scanning are P2/P3 in FEATURES.md, deliberately absent. There's no answer
autosave in the quiz runner — a response only reaches the database at the
moment a student clicks Submit, so an attempt that times out without ever
being submitted is graded as entirely blank by the auto-submit sweep; this
is a known, documented limitation, not a silent one, and the natural next
step (autosave) is a fast-follow candidate. Instructor-facing dashboards
beyond the one raw per-quiz score list M9 asks for are out of scope, same
"basic only" call S5 made for attendance.

**Demo #6 target: 2026-10-31** — a teacher generates a question paper from
their own lecture notes, reviews and approves it, publishes the quiz to a
topic node in the syllabus tree; a student opens it, attempts it under the
time limit, and sees an auto-graded result with explanations the moment
they submit.

## S7 — Fees: COMPLETE (all 8 blocks)

New `app/Domains/Fees/` domain (`FeeInvoice`/`FeePayment`/`FeeCoupon`),
built on two contracts pinned but unused since earlier slices:
`Batch.is_paid`/`price` (S1/S4) with `BatchEnrolmentService::enrol()` never
reading either, and `Tenant` having no GSTIN/billing fields at all. Seven
decisions locked before the block plan: a new domain, same per-slice
precedent as Assessments (S6); a payment gate through a new
`Enrolment.status = 'pending_payment'` rather than a parallel system; Fees
never calls `BatchEnrolmentService` directly — a symmetric pair of
one-way-ignorant events (`EnrolmentAwaitingPayment` /
`Fees\PaymentCaptured`) crosses the domain boundary in both directions;
instalments are a JSON template on `Batch`, materialized into real
`FeeInvoice` rows at enrolment time (same template-then-materialize shape
S6 used for `Quiz.blueprint`); GST invoice numbering is a per-tenant
sequential counter under `lockForUpdate()`; the Razorpay webhook lives in
`routes/api.php`, outside every tenant domain group, tenant resolved from
the order's own `notes.tenant_id` and bound only after signature
verification; coupon/invoice-sequence races take the same
`lockForUpdate()` treatment `BatchEnrolmentService` already established for
seats.

| Block | What it built | Status |
|---|---|---|
| **B0** | Domain groundwork — `fee_invoices`/`fee_payments`/`fee_coupons` migrations, `tenants`/`batches` column additions, models, `PaymentProvider` + `FakeRazorpayDriver`, tenant registry | ✅ Done |
| **B1** | Order creation (P1) — `FeeInvoiceService`, `FeeCheckout` Livewire, `enrol()` gains the `pending_payment` path for paid batches | ✅ Done |
| **B2** | Webhook capture + auto-enrolment on payment (P5) — signature verification, idempotency, `PaymentCaptured` -> Batches listener finalizes the enrolment, retry-on-failure | ✅ Done |
| **B3** | Coupon codes (P2) — percentage/fixed, usage limit, applied once at invoice creation | ✅ Done |
| **B4** | Instalment plans (P3) — `Batch.instalment_plan` template materialized into per-enrolment invoices | ✅ Done |
| **B5** | GST-compliant invoices + receipts (P4) — sequential per-tenant numbering, GSTIN snapshot, printable HTML | ✅ Done |
| **B6** | Fee defaulter list + reminders (P7) — `/admin` overdue list, daily overdue-flag sweep, T-3/T-0/T+3 mail reminder | ✅ Done |
| **B7** | Full test coverage, isolation registration, review pass | ✅ Done |

**Test suite: 443/444 passing.** Same 1 pre-existing failure carried since
S1/S2 (`CourseSlugUniquenessTest`, unrelated to S7, still out of scope).

### Bug-hunting pass after the build — 1 real bug found and fixed

Same practice as every prior slice: once all 8 blocks were done, every file
got read fresh rather than trusting green tests alone. Full detail with
file:line references is in the S7 plan's "Post-S7 review" section.

- **A different institute's staff could open any tenant's GST invoice —
  GSTIN, billing address, a student's name/email, and exact fee amounts —
  just by knowing or guessing an invoice id.** The download route's
  owner-or-staff check tested role membership alone
  (`hasAnyRole(['instructor', 'institute_admin'])`), the same class of gap
  `EnsureRole` (S1) exists specifically to close on the admin/teach panels:
  Spatie roles carry no institute of their own, and the login cookie is
  shared across every `*.lms.test` subdomain by design, so a staff
  member's session from institute B stays valid on institute A's URL. This
  plain, non-panel controller never got the same fix `EnsureRole` already
  had. Fixed by also requiring the staff member's own institute to match
  the invoice's institute; a regression test now covers exactly this
  cross-institute case, mirroring the one that's guarded the admin/teach
  panels since the third S1/S2 review round.

One more item was checked and deliberately left alone, flagged instead for
a future dedicated pass: the same role-only pattern (no institute
cross-check) is also how lesson streaming and quiz visibility decide who
gets in (`LessonAccess`, `ShowQuizController` — Content/Assessments,
S2/S3/S5/S6). That's a materially bigger surface than one invoice route,
but it spans four domains built across four earlier, already-reviewed
slices — out of S7's own scope to fix as a drive-by.

**What's not real yet, on purpose:** no real Razorpay account
(`FakeRazorpayDriver` only, same posture every prior vendor integration
shipped with). WhatsApp reminders wait on S8's BSP integration — mail is
the real channel for now. Refunds, grace-period access restriction,
subscriptions, and multi-currency are all Phase-P2/P3 in FEATURES.md,
deliberately absent. Invoices are printable HTML, not a new PDF dependency.

**Demo #7 target: 2026-11-14** — a student joins a paid batch, pays via
Razorpay (fake driver), lands active immediately; an instalment-plan batch
shows a second invoice due later; an overdue instalment fires a reminder.
One test (`DemoScriptE2ETest`) now walks this exact script end to end in a
single run.

## S8 — Comms: COMPLETE (all 9 blocks)

New `app/Domains/Comms/` domain (`ParentGuardianLink`, `StudentMetrics`,
`SendAbsenceAlert`, `ExportAttendanceRegister`), built on two contracts
pinned but unused since earlier slices: `FeeReminderDue::via()` already
had a docblock naming the WhatsApp gap S8 would close (S7), and
`RoleHierarchy::LEVELS` already had a `parent` key tied with `student`
(S1) — genuinely a *correction* (splitting the tie), not new territory, a
stale claim in S8's own design doc caught before Block 0 started. This is
the eighth and final slice of the P1 "Institute Zero" roadmap.

| Block | What it built | Status |
|---|---|---|
| **B0** | Domain groundwork — `users.phone`, `parent_student_links`, `CommsProvider` + `FakeWhatsAppDriver` + real (unbound) `AiSensyProvider`, `WhatsAppChannel` notification channel | ✅ Done |
| **B1** | Parent accounts + linkage (O1) — admin creates + links a parent to ≥1 student in `/admin`, `ParentPortal` login | ✅ Done |
| **B2** | Parent portal (O2) — read-only attendance/coverage/quiz/fee view per linked student, `StudentMetrics` service | ✅ Done |
| **B3** | Same-day absence WhatsApp alert (H9/O3) — `AttendanceRecorded` event, `SendAbsenceAlert` listener, deduped per parent+student+day | ✅ Done |
| **B4** | Fee WhatsApp reminder (O4) — `FeeReminderDue` gains the WhatsApp channel S7 pinned | ✅ Done |
| **B5** | Staff dashboards (H8/M11/M12) — instructor `StudentProgress` page, admin `InstituteOverviewWidget` | ✅ Done |
| **B6** | Attendance CSV report (S3, partial) — this app's first real queued (`ShouldQueue`) job outside the AI ingestion chain | ✅ Done |
| **B7** | In-app notification centre (N3) — one shared `NotificationCentre` component, mounted on both portals and all three staff panels via this app's first Filament render hook | ✅ Done |
| **B8** | Full test coverage, isolation registration, review pass | ✅ Done |

**Test suite: 528/529 passing.** Same 1 pre-existing failure carried since
S1/S2 (`CourseSlugUniquenessTest`, unrelated to S8, still out of scope).

### Bug-hunting pass after the build — 3 real bugs found and fixed, one initial fix corrected after its own regression test disproved it

Same practice as every prior slice: once all 8 build blocks were done,
every file got read fresh rather than trusting green tests alone. Full
detail with file:line references is in the S8 plan's "Post-S8 review"
section.

- **A CSV formula-injection gap in the new attendance export.** A student
  name/email starting with `=`, `+`, `-`, or `@` would become a live
  formula the instant an institute_admin opened the exported register in
  Excel or Sheets (CWE-1236) — this app's first CSV export, and students
  can already self-register their own name, so this was realistically
  triggerable. Fixed with a leading-apostrophe guard on every
  user-supplied cell.
- **A missing `ON DELETE CASCADE` on `parent_student_links.tenant_id`,**
  a one-line deviation from both the design doc's own schema and this
  app's own convention (18 of 20 other tenant foreign keys cascade).
  Currently unreachable (nothing deletes a tenant yet) but cheap and
  correct to fix before it's forgotten.
- **The new in-app notification bell could briefly render on a
  cross-tenant parent session before that session's own tenant-mismatch
  logout took effect.** Both new Livewire components mount independently
  on the parent dashboard page, and the notification bell was listed
  first in the page template — so on the exact cross-subdomain session
  hop this app's shared login cookie makes possible, it read the
  session's own (still valid at that instant) notifications before the
  page's own safety check invalidated it. Not a cross-*user* leak, and no
  exploitable action followed — but the guarantee wasn't uniformly
  enforced across two components sharing one page. Fixed by reordering so
  the safety check always runs first.
- **A fix for a suspected fourth bug turned out to be unnecessary, and
  was reverted once its own regression test proved the code was already
  safe.** The review's first pass suspected the new parent-linking form
  would accept a tampered request linking a parent to a different
  tenant's student, since the field's dropdown only filters what's
  *displayed*. A server-side re-check was added — and its own regression
  test then failed, because Filament's form field already validates a
  submitted value against that same tenant-scoped list, rejecting the
  tampered request on its own before any custom code could run. The
  extra check was reverted as redundant, and the tests were corrected to
  assert the real (and already correct) behaviour instead.

**What's not real yet, on purpose:** no real AiSensy/WhatsApp BSP account
exists (`FakeWhatsAppDriver` only, same posture every prior vendor
integration shipped with). No student anywhere in this app has a phone
number captured — only `ParentGuardianResource` ever writes one, and only
for parents — so `FeeReminderDue`'s new WhatsApp channel is wired
correctly but silently sends nothing today; the absence alert works
because it targets the *parent's* own phone, not the student's. Parent
linkage is admin-created only, no self-service invite-code flow. The
monthly WhatsApp report card, live-class T-30 reminders, course
announcements, and the rest of the report catalog beyond the attendance
register are all explicitly deferred to fast-follow (design doc §2.2),
same "narrow to the demo script" posture every prior slice used.

**Demo #8 target: 2026-11-28** — a parent (admin-created, linked to a
student in one action) logs in and sees that student's attendance/
coverage/quiz/fee summary; the student is marked absent in a live session
and the linked parent receives a same-day (fake) WhatsApp alert; an
instructor sees a per-student progress table and an admin sees enrolment/
revenue/at-risk widgets; an admin generates and downloads a real
attendance-register CSV; every surface — both portals and all three staff
panels — carries the same minimal notification bell.

## The P1 roadmap itself: nothing left

All eight sub-projects of the P1 "Institute Zero" roadmap are built,
tested, and reviewed. Demo #1 on 2026-08-22, demo #2 on 2026-09-05, demo
#3 on 2026-09-19, demo #4 on 2026-10-03, demo #5 on 2026-10-17, demo #6 on
2026-10-31, demo #7 on 2026-11-14, and demo #8 on 2026-11-28 (the scripts
above) are all buildable against what exists today.

## S9 — Hardening & Backlog: COMPLETE (all 15 blocks, 2026-08-13)

**Not part of the original P1 roadmap** — a new, owner-requested slice.
Every one of S1-S8's design docs deferred something to "fast-follow," and
three separate review passes (S5, S7, S8) each found a real gap and
flagged it "out of this slice's own scope" rather than fixing it. Nobody
had collected that backlog into a buildable slice, or cross-checked it
against the live code rather than the deferring doc's own (sometimes
stale) wording, until now.
`docs/superpowers/specs/2026-08-12-s9-hardening-backlog-design.md` and
`...-plan.md` cover 15 blocks (B0-B14):

- **B0 (done)** closed two real, still-open multi-tenant security gaps
  first, ahead of everything else — `StudentPortal` now has the same
  tenant-mismatch session check `ParentPortal` (S8) already had, and
  `LessonAccess::canStream()`/`Quiz::isVisibleTo()` now cross-check the
  viewer's own `tenant_id`, closing the same bug class
  `EnsureRole`/`DownloadInvoiceController` (S7) were each fixed for.
  `canJoinLive()` also gained the course-published check `isCourseVisibleTo()`
  already answers, and `myLiveBatchSession()` gained a deterministic
  `orderBy('starts_at')` tiebreak. 8 new regression tests; suite green at
  536/537 (1 pre-existing, unrelated `CourseSlugUniquenessTest` failure
  carried since S4).
- **B1 (done)** built TOTP two-factor auth — but not via the design doc's
  own recommended package. `filament/filament` v5.7.6 already ships a
  complete, built-in TOTP multi-factor system (it depends on
  `pragmarx/google2fa-qrcode` itself), so the three Filament panels get
  enrollment/QR/recovery-codes/challenge for free via `->profile()` +
  `->multiFactorAuthentication(...)`, wired through a new shared
  `TenantAwareLogin` base class that keeps Filament's own MFA bookkeeping
  while still checking credentials tenant-scoped. The two portals
  (StudentPortal, ParentPortal) get a hand-rolled challenge step reusing
  the same underlying verification service, plus a stub enrollment page
  (`/settings/two-factor`) until B9's real profile page absorbs it. 11 new
  regression tests; suite green at 547/548 (same pre-existing failure).
- **B2 (done)** built Google OAuth login — scoped to the student and
  parent portals only (a deliberate cut, not the three Filament panels,
  which already have password + optional TOTP from B1). Google's redirect
  URI has to be one fixed address, so the redirect step runs on the
  tenant's own subdomain (remembering the tenant + portal in session) while
  the callback is a host-agnostic route that reads them back — the one
  place in this app the shared-session-across-subdomains behavior is a
  feature, not the leak S9 B0 spent a whole block closing. New
  `GoogleOAuthAuthenticator` matches by `google_id`, falls back to linking
  an existing same-tenant `email` match, or creates a new role-less user.
  8 new regression tests (Socialite mocked, no real network call); suite
  green at 555/556 (same pre-existing failure).
- **B3 (done)** built Super Admin impersonation + audit log. A
  `super_admin` picks any non-super_admin user from a single searchable
  select (`ImpersonateUser`, the first page the Super panel has ever had
  beyond its own Dashboard) and starts a session-based impersonation,
  resolved per-request by a new `ResolveImpersonation` middleware —
  registered on both the global `web` group and each panel's own
  middleware array, since Filament panel page loads don't pick up a
  global-group-only append the way `ResolveTenant`'s does. Guards: cannot
  impersonate another super_admin (blocked before any audit row is
  written); cannot have the impersonated identity silently apply on a
  *different* tenant's subdomain just because the session cookie is shared
  across them. `ImpersonationAudit` deliberately does not use
  `BelongsToTenant` (the acting super_admin has no tenant to resolve one
  from) and is not registered in `TenantModelRegistry` as a result — a
  documented deviation from the block's own original plan note, corrected
  after checking `RoleAssignmentAudit`'s actual shape rather than trusting
  the note's assumption about it. A shared "Stop impersonating" banner
  mounts on all three panels and both portals. 8 new regression tests;
  suite green at 563/564 (same pre-existing failure).
- **B4 (done)** built the last of S1's four "lands in S8" broken promises
  — custom-domain support, DNS-verification-only (SSL provisioning stays a
  documented manual step). A tenant's own `institute_admin` sets a domain
  and publishes a TXT record on a new `CustomDomainSettings` Admin page
  (this app's first tenant-settings UI of any kind); `ResolveTenant` falls
  back to a verified custom domain only after the normal subdomain lookup
  misses. Scoped deliberately to resolution only — every tenant route is
  still registered under `*.lms.test` only, so a custom domain isn't yet
  reachable at the router level for full page browsing; that's a
  materially bigger change than "DNS verification," written down as a
  known boundary rather than silently assumed away. 9 new regression
  tests; suite green at 572/573 (same pre-existing failure). **All four of
  S1's "lands in S8" promises are now built (B1-B4).**
- **B5 (done)** built self-service parent linking via invite code. An
  `institute_admin` generates a short, human-typeable code for a specific
  student (`GenerateParentInviteCode`, a new Admin page — no Students
  Resource or a natural spot on `ParentGuardianResource` existed for this,
  confirmed by checking); a prospective parent redeems it on a
  host-agnostic page (`/invite`), which creates their `parent`-role
  account, links them to the student, and consumes the code — all tenant
  attribution comes from the code itself, never from whatever subdomain
  happened to render the redemption form. 8 new regression tests; suite
  green at 581/582 (same pre-existing failure).
- **B6 (done)** built real rendering for `pdf`/`text`/`embed` lesson types
  — previously a dead end (a teacher could select them, nothing showed,
  since `LessonAccess::isPlayable()` was unconditionally shaped for video
  and 404'd every other type regardless of what the syllabus tree linked
  to). New `text_body`/`embed_url`/`pdf_disk_path` columns (`pdf_disk_path`
  is its own column, not a reuse of `video_disk_path`); `isPlayable()`
  generalized to check the right column per type; `embed_url` validated
  against an allow-list (YouTube/Vimeo/Google Docs) at save time; a new
  signed `DownloadLessonPdfController` matches the existing video byte
  route's own security posture. Content protection
  (`controlsList="nodownload"` + right-click disabled on the player)
  added, framed honestly as a deterrent, not real DRM. 8 new regression
  tests; suite green at 589/590 (same pre-existing failure).
- **B7 (done)** built quiz answer autosave, closing a gap
  `AutoSubmitExpiredQuizAttempts` (S6) had already named as a real, known
  limitation in its own docblock rather than pretending it away. A new
  `QuizAttemptService::saveAnswer()` persists each answer to a real
  `QuizAnswer` row (a new `'draft'` status, kept out of the instructor
  review queue) on blur/selection-change; `submit()` — used by both a
  real student Submit and the unchanged auto-submit sweep — now falls back
  to that persisted draft whenever no explicit response was passed, so an
  expired attempt grades what the student actually answered instead of a
  blank one. 9 new regression tests; suite green at 598/599 (same
  pre-existing failure).
- **B8 (done)** built fee-checkout live refresh + a real Razorpay
  idempotency guard. Razorpay's own Orders API has no idempotency
  *header* (that only exists on its Payouts/Refunds APIs, confirmed
  against Razorpay's own docs, not assumed) — its `receipt` field plays
  that role, and a re-used receipt is rejected outright rather than handed
  back the original order. `FeeInvoiceService::createPaymentOrder()` now
  checks for an already-open `FeePayment` before ever calling the provider
  a second time, keyed deterministically per invoice+attempt. Live refresh
  is `wire:poll.3s` (no real broadcasting infrastructure exists) on the
  "awaiting payment" state only. 5 new regression tests; suite green at
  603/604 (same pre-existing failure).
- **B9 (done)** closed the last named UX gap: a shared `UserProfile`
  page (never claimed by any prior slice), mounted the same way
  `NotificationCentre` (S8) is — both portal Blade layouts directly, plus
  the existing Filament render hook, no second registration. New
  `avatar_path`/`timezone`/`locale` columns on `users`; avatar upload
  reuses `BrandingService`'s exact upload/storage shape (S2); password
  change uses Laravel's built-in `current_password` rule (genuinely new
  territory, no prior password-change flow existed); the 2FA enrollment
  entry point B1's own notes named as a placeholder nests the existing
  `TwoFactorSettings` component, shown only outside a Filament panel.
  Its own regression tests caught a real bug: a freshly created `User`
  (factory, or any real create-then-log-in path) has `timezone`/`locale`
  null in memory despite the migration's DB-level defaults, since
  Eloquent doesn't pull server-side defaults back into the model after an
  insert — `mount()` now falls back explicitly rather than trusting the
  attribute. 16 new regression tests; suite green at 619/620 (same
  pre-existing failure).
- **B10 (done)** built production readiness: encrypted nightly backups
  (`spatie/laravel-backup`, none installed before) scoped to the database +
  `storage/app/private` only, written to a **new** `'backups'` disk kept
  separate from the `'local'` disk it backs up (writing into the same tree
  would zip up every prior run's own archive), flat 30-day retention.
  Of 9 candidate jobs named for "real queue retry/backoff," 5 turned out to
  be plain classes, not `ShouldQueue` — `Schedule::job()` silently runs a
  non-`ShouldQueue` class inline rather than queuing it, confirmed by
  reading Laravel's own scheduler code, so `$tries`/`backoff()` on any of
  them would have been dead configuration. Converted all 5 to real queued
  jobs (a new lower-priority `'scheduled'` Horizon queue), each audited for
  idempotency first — one (`SendFeeReminders`) wasn't safe to retry as
  written, and got a same-day dedupe guard before retry was enabled. A
  second bug surfaced by that same conversion: a persistent Horizon worker
  can leave a stale tenant bound from a prior job, silently narrowing one
  of these cross-tenant sweeps to a single tenant — fixed with an explicit
  clear at the top of each job. The 4 AI ingestion jobs deliberately got no
  retry config — they self-fail on every exception by design, so Laravel's
  automatic retry never gets a chance to run. `failed_jobs` already existed
  (Laravel's own default migration). 27 new regression tests; suite green
  at 639/640 (same pre-existing failure).
- **B11 (done)** built the report catalog's two remaining fast-follow
  exports (gradebook stays deferred — no Gradebook feature exists yet):
  `ExportRevenueRegister` (one row per `FeeInvoice` — invoice number,
  student, batch, amounts, status, due date, paid at) and
  `ExportEngagementRegister` (one row per actively-enrolled student —
  attendance %, video coverage %, quiz average %, fee status, reusing
  `StudentMetrics::summarize()` per row). Both copy `ExportAttendanceRegister`'s
  exact shape (S8 B6) — no new architectural pattern. `csvSafe()` (the CSV
  formula-injection guard, S8 B6) extracted into a shared trait once a
  third export needed it, and the original retrofitted to use it too,
  rather than a third hand-rolled copy. 30 new regression tests; suite
  green at 664/665 (same pre-existing failure).
- **B12 (done)** turned S2's bandwidth-quota and S3's AI-spend-cap
  *measurement* into real *enforcement*: `Tenant.plan_tier` (flat
  `'free'`/`'paid'` enum) plus hardcoded, explicitly-placeholder per-tier
  caps. Bandwidth check centralized in a new `LessonAccess` method — found
  two controllers record bandwidth, not one
  (`DownloadLessonPdfController` alongside `StreamLessonController`), by
  checking every call site rather than assuming. AI spend check wraps the
  `AiProvider` container binding itself in a decorator
  (`SpendCapGuardedAiDriver`), covering all six real call sites across the
  app with one change instead of six inline guards, since five of six
  resolve the interface fresh from the container. Both new exceptions
  render as a specific `409`, not a bare `403`. Found and fixed two
  pre-existing tests that asserted the exact behavior this block
  deliberately reverses (never blocks; resolves as the bare fake), plus a
  real `UploadedFile::fake()` test-fixture gotcha (declared size vs. zero
  actual bytes) while writing its own tests. 24 new/updated regression
  tests; suite green at 681/682 (same pre-existing failure).
- **B13 (done)** built a real outbound low-attendance alert and a 3-day
  inactivity nudge, both reusing S8 B3's absence-alert plumbing exactly
  (same `AttendanceRecorded`-adjacent trigger, `WhatsAppChannel`+`database`
  dual-channel notification, dedupe via the `notifications` table — no new
  mechanism). `InstituteOverviewWidget`'s at-risk threshold (S8 B5) moved
  into `StudentMetrics` once a second consumer needed it. The alert fires
  on every attendance event regardless of status, not just absences,
  since a `'present'` mark can still leave a student's overall percentage
  below threshold. The nudge is a real `ShouldQueue` job (matching S9
  B10's own retry/queue posture) deriving "last activity" from
  `VideoWatchEvent`/`QuizAttempt` timestamps — this app has no dedicated
  "last active" column anywhere. Both dedupe once per student per week,
  a judgment call approximating "fires once, not every check" without a
  new schema column. 15 new regression tests; suite green at 696/697
  (same pre-existing failure).
- **B14 (done)** confirmed `TenantModelRegistry` complete (`ParentInviteCode`
  registered, `ImpersonationAudit` correctly absent — cross-checked all 22
  models actually importing the `BelongsToTenant` trait against the
  registry array directly, exact match) and closed the slice with a fresh
  bug-hunting pass across every domain S9 touched.

### Bug-hunting pass after the build — 6 real issues found and fixed, 2 more flagged (not fixed)

Same practice as every prior slice: once all 14 build blocks were done,
every file got read fresh — a manual pass across the highest-risk
cross-block seams, plus an independent agent given the same brief in
parallel. Full detail with file:line references is in the S9 plan's
"Post-S9 review" section.

- **Google OAuth login silently bypassed TOTP 2FA entirely** — the
  highest-severity finding. The password login path (B1) correctly
  challenges a 2FA-enabled user; the Google callback (B2) called
  `Auth::login()` unconditionally, with no check at all. Fixed by handing
  off to the exact same challenge UI the password path already has.
- **Impersonation locked the real super_admin out of the Super panel**
  while impersonating anyone and navigating there directly — `CurrentTenant`
  is never bound on that bare host, so the tenant-mismatch guard (written
  to skip a *mismatched* tenant) never fired for an *unbound* one either,
  and the swapped-in target then failed the panel's own super_admin check.
  Fixed by requiring a bound *and matching* tenant for the swap to apply.
- **`QuestionGenerator`'s real AI spend never reached the spend cap it was
  gated behind (B12).** The spend-cap decorator correctly gates this call
  site along with the other five, but the measurement half never read
  from it — `ChatResult::$usage` was discarded on every call. Fixed with a
  new `questions.token_cost` column, same shape the other two sources
  already use.
- **`docs/FEATURES.md`'s T2 ("failed-job retention") and A6 ("60-min
  auto-expiry" for impersonation) were both claimed but never actually
  built** — the `failed_jobs` table existed with nothing ever pruning it,
  and impersonation had no time-based cutoff at all. Both fixed: a
  scheduled `queue:prune-failed --hours=720`, and a
  `MAX_DURATION_MINUTES = 60` check against the impersonation audit row's
  own `started_at`.
- **`NotificationCentre` showed B13's two new notification types with a
  raw class-basename label** instead of a human summary — a real, minor
  regression from B13 itself. Fixed with two more `match` arms.

**Two more real gaps found, flagged rather than fixed** — bigger,
product-shaped decisions a review pass shouldn't make alone, same posture
B4's "SSL stays manual" boundary already established for this slice:
`docs/FEATURES.md`'s B5 row claims TOTP 2FA is "mandatory" for Super Admin
+ Institute Admin — it's built and functional, just opt-in for every role,
never enforced; and D8/T3's "overage billing" and "rehearsed restore"
claims — B12's bandwidth cap blocks access rather than billing for it, and
B10's backups have never had a restore actually exercised. Both
recommended as fast-follow, not attempted here.

**Test suite: 707/708 passing.** Same 1 pre-existing failure carried since
S4 (`CourseSlugUniquenessTest`, unrelated to S9, still out of scope).

Explicitly deferred (full reasoning + landing spot per item in the design
doc's §2.2): actually binding any of the five real-but-unbound vendor
drivers (still no accounts), 9 of `lesson_artifacts`' 11 originally-pinned
AI consumers, Assignments/Gradebook, Certificates, extended question
types/proctoring/test-series/OMR, timetable conflict detection,
refunds/subscriptions/multi-currency, mandatory 2FA enforcement, bandwidth
overage billing, backup restore rehearsal, and the rest of
`docs/FEATURES.md`'s P2/P3 catalog.

**All 15 S9 blocks are now built, tested, and reviewed** — the first
slice not on the original P1 "Institute Zero" roadmap, closing out a
fast-follow backlog every one of S1-S8's own design docs had deferred.

## S10 — Assignments + Gradebook + Certificates: COMPLETE (all 16 blocks, 2026-08-16)

**Not part of the original P1 roadmap, and a new slice beyond S9 too** —
picked over three other P2 candidates (account-security round 2,
plan-tier billing, engagement analytics) as the single largest structural
gap in `docs/FEATURES.md`'s ~75-item P2 backlog: J1-J7 (assignments +
consolidated gradebook) and K1-K4 (certificates), a whole feature family
with zero prior coverage, unlike security/billing which S9 had already
partly touched.
`docs/superpowers/specs/2026-08-16-s10-assignments-gradebook-design.md`
and `...-plan.md` cover 16 blocks (B0-B15):

- **B0-B4** built the new `app/Domains/Assignments/` domain end to end:
  schema (`Assignment`/`AssignmentSubmission`/`Certificate`, plus new
  `courses.gradebook_weights`/`passing_threshold` columns), `/teach`
  assignment CRUD with a rubric builder, the student-facing submit flow
  (an enrolment-gated visibility check stricter than Quiz's own, since a
  real file upload with grading history warrants it), versioned
  resubmission (≤3 versions, one unified service method for both first
  submission and every resubmission — a plan-note deviation, recorded),
  and grading (marks-only or rubric-derived, `marks_awarded` always
  computed from the rubric breakdown when one exists, never a
  separately-typed number).
- **B5-B8** built the gradebook itself: per-course weight configuration on
  `Course` (sum-to-100 validated), `GradebookService::scoreFor()` (best
  attempt per quiz, *latest graded* — not best-scoring — version per
  assignment, a category with zero items drops out of the weighted
  average entirely rather than scoring as zero), its own
  `GRADEBOOK_AT_RISK_THRESHOLD` deliberately separate from S9 B13's
  attendance-based threshold, and a `Gradebook` table on both `/teach` and
  `/admin` (one row per active enrolment, course-filterable, not truly
  DB-sortable — matches `StudentProgress`'s own S8 B5 precedent for an
  identical computed-aggregate tradeoff).
- **B9-B10** built three export formats off one shared row-building trait
  (`BuildsGradebookRows`) rather than three divergent queries — CSV
  (reusing S9 B11's `EscapesCsvFormulas`), then Excel and PDF once this
  app's first uses of `maatwebsite/excel` and `barryvdh/laravel-dompdf`
  were installed and their real (very recently released) APIs read
  directly from the installed source, not assumed. CWE-1236 formula
  injection turned out not to be CSV-specific — the Excel export reuses
  the same guard, verified by loading the actual generated `.xlsx` file,
  not a mocked assertion.
- **B11-B14** built certificates: a daily unscoped sweep
  (`CheckCourseCompletionAndIssueCertificates`, same `CurrentTenant::clear()`
  shape S9 B10 established) issues a certificate once "course completion"
  — newly defined here as gradebook-based, since no generic
  lesson-completion tracking exists anywhere else in this app — is met;
  per-institute branding (signature/background upload, `/admin`'s first
  page to reuse `BrandingService`'s upload machinery, which had existed
  since S1 but had never actually been wired to a Filament page before);
  a public, host-agnostic verification page (`GET /certificates/verify/{id}`,
  no auth) with a QR code (`endroid/qr-code`, this app's first use) and a
  LinkedIn "Add to profile" link built from LinkedIn's own documented
  static-URL parameters.
- **B15's own dedicated review pass** found and fixed two more real bugs
  beyond the ones each block's own tests already caught along the way:
  `AssignmentSubmissionService::submit()` computed `version_number` from
  a plain `count()` with no lock, so two tabs or a double-click racing
  the same count could both hit the DB's unique index as an uncaught
  `QueryException` (fixed with a one-retry-on-collision shape, the same
  pattern `BatchEnrolmentService::createEnrolment()` established for an
  identical race in S4); and `Batch`/`Course` are both `SoftDeletes` with
  no delete guard against active enrolments (unlike Assignment/Quiz,
  which both gained one) — an active enrolment could point at a
  soft-deleted batch or course, and every Gradebook consumer (both
  Filament pages, all three export formats, the certificate sweep)
  dereferenced the relation unconditionally. Filtered at the query level
  across all four call sites.

**Test suite: 804/805 passing.** Same 1 pre-existing failure carried since
S1/S2 (`CourseSlugUniquenessTest`, unrelated to S10, still out of scope).

**What's not real yet, on purpose**: all three new dependencies
(`endroid/qr-code`, `maatwebsite/excel`, `barryvdh/laravel-dompdf`) are
first-use with no prior production track record in this app — PDF/Excel
edge cases (fonts, large datasets) that this slice's own test suite
wouldn't surface are worth watching. Certificate issuance latency is up
to 24h by design. Gradebook weighting is course-wide only, no
per-section override. Extended question types, evaluators (J8), and a
certificate request/evaluation workflow (K5) stay P3, deliberately
absent.

**All 16 S10 blocks are now built, tested, and reviewed.**

---

## S11 — Plan Tiers + Institute Billing: COMPLETE (all 17 blocks, 2026-08-17)

Not part of the original P1 roadmap, and a new slice beyond S9/S10 too —
picked over 2 other leftover P2 candidates (account-security round 2,
engagement analytics + parent comms follow-up). Directly closes the
fast-follow `BandwidthQuota`/`AiSpendCap` (S9 B12) themselves flagged as
deferred ("richer tiers/limits product... once real customers need
different limits from each other").
`docs/superpowers/specs/2026-08-16-s11-plan-billing-design.md` and
`...-plan.md` cover 17 blocks (B0-B16):

- **B0-B4** built the new `app/Domains/Billing/` domain end to end: schema
  (`Plan` — a new, un-tenanted catalog table — plus `TenantSubscription`/
  `SubscriptionInvoice`/`TenantStorageUsage`, all three `BelongsToTenant`),
  `tenants.plan_id` added additively (nullable, existing `plan_tier` enum
  + hardcoded caps kept as the legacy fallback), Super-panel `PlanResource`
  CRUD, `TenantPlanLimits` (the one place resolving a tenant's real limits
  — plan-aware when `plan_id` is set, legacy fallback otherwise),
  `SeatQuota` (staff seats only — students are unlimited, the institute's
  own monetisation lever) enforced at `RoleAssignmentPolicy::assign()`,
  and `StorageQuota` wired into every file-store/delete call site across
  three domains (Assignments, Content, Tenancy).
- **B5-B10** built the real subscription lifecycle: a second, separate
  Razorpay integration (`SubscriptionProvider`, Subscriptions API — a
  different vendor surface from the existing Orders-API `PaymentProvider`)
  with `RazorpaySubscriptionProvider`/`FakeRazorpaySubscriptionDriver`;
  Super-panel-initiated subscription creation
  (`TenantResource`'s "Plan" section + `PlanChangeService::subscribe()`,
  matching this platform's actual sales motion — every institute here is
  operator-provisioned, no public checkout page); a real webhook
  (`RazorpaySubscriptionWebhookController` + `SubscriptionReconciler`,
  same signature-first, no-business-logic-in-the-controller division of
  responsibility `Fees\PaymentReconciler` already established) writing
  `SubscriptionInvoice` rows; a daily grace-period sweep
  (`SuspendOverdueTenantSubscriptions`, 7-day `grace_ends_at` window,
  webhook-driven entry + time-driven exit — mirrors `FlagOverdueInvoices`);
  suspension enforcement confirmed on the existing `ResolveTenant` gate
  (reuses `Tenant::STATUS_SUSPENDED`, not a second flag) plus a
  `GracePeriodBanner`; upgrade/downgrade with proration computed in this
  app's own ledger rather than delegated to Razorpay's opaque INR
  proration (`PlanChangeService::changePlan()`, blocks a downgrade that
  would strand the tenant over the new plan's seat/storage limits).
- **B11-B15** built the remaining surfaces plus hardening: an
  institute-facing Admin-panel `BillingOverview` (plan, usage bars,
  subscription status, invoice history) and a Super-panel cross-tenant
  `InstituteSubscriptions` list; `PlanChangeService::cancel()` (reverts to
  `plan_id = null` — the existing legacy-fallback branch, not a new
  "cancelled plan" state); grace-entry and suspension-imminent
  WhatsApp+email+database notifications (`GracePeriodReminder`, mirrors
  Fees' own `FeeReminderDue` shape); a dedicated concurrency pass found
  and fixed 4 real races with no unique DB constraint to catch-and-retry
  against — `PlanChangeService`'s three public methods and
  `RoleAssignmentPolicy`'s seat-consuming branch both now lock the
  `Tenant` row inside their own transaction, serializing concurrent calls
  per tenant (one gap deliberately left as a documented limitation:
  `SubscriptionReconciler` and `PlanChangeService` lock different row
  targets, so a webhook and a manual plan-change on the same tenant at the
  same instant have no mutual exclusion between them — low-severity,
  proration-math precision only, self-correcting, no data corruption).
- **B16's own dedicated review pass** — re-read every B1-B15 file for real
  bugs, not just re-confirmed the suite stayed green — found and fixed 3
  more: `StorageQuota::adjust()` had a lost-update race on its
  read-then-write increment (two concurrent file uploads for the same
  tenant could silently undercount real usage against the quota this
  class exists to enforce), fixed with atomic `increment()`/`decrement()`
  plus a retry-once-on-collision for its own create-row race, matching
  `AssignmentSubmissionService::submitWithRetry()`'s S10 B15 shape;
  `PlanChangeService::liveSubscriptionFor()` excluded `SUSPENDED` from its
  status filter, so `cancel()`/`changePlan()` both refused exactly the
  tenant a super_admin is most likely to want to act on, while
  `EditTenant`'s own header actions stayed visible for that same tenant —
  a real visible-but-broken button, not a hypothetical gap; and
  `SubscriptionReconciler::charged()` recovered `TenantSubscription::status`
  on a successful payment but never touched `Tenant::status` — the column
  that actually gates login — so a tenant the daily sweep had already
  suspended stayed locked out forever even after billing genuinely
  recovered. All three ship with regression tests, plus one new full
  lifecycle E2E test (subscribe → charge → grace → suspend → recover →
  cancel, chained in one run, mirroring S7's own `DemoScriptE2ETest`
  posture) proving the pieces still fit together end to end.

**Test suite: 925/926 passing.** Same 1 pre-existing failure carried since
S1/S2 (`CourseSlugUniquenessTest`, unrelated to S11, still out of scope).

**What's not real yet, on purpose**: no public pricing/checkout page —
plan assignment and subscription creation stay Super-panel-initiated,
matching this platform's actual current sales motion; self-serve signup
is a later, separate R-series item. A live mid-cycle charge on a plan
change is genuinely deferred — proration is written as a ledger row and
collected on the next billing cycle, not charged immediately. The
`SubscriptionReconciler`/`PlanChangeService` cross-service lock-target gap
noted above (B15) stays a documented, deliberately-accepted limitation,
not a bug queued for a fix.

**All 17 S11 blocks are now built, tested, and reviewed.**

## S12 — Security & Resilience Hardening: COMPLETE (all 15 blocks, 2026-08-18)

Fourth P2-backlog slice (after S9 hardening, S10 assignments/gradebook/
certificates, S11 billing). `docs/FEATURES.md` Section B (auth/session/
audit/GDPR items B9-B13, B15) + Section V-a (resilience/correctness items
V1-V5) — 11 roadmap items, 15 finer-grained build blocks.
`docs/superpowers/specs/2026-08-17-s12-security-resilience-design.md` and
`...-plan.md` cover B0-B14:

- **B0-B4** built the new `app/Domains/Security/` domain's device/session/
  login surface: 5 new `BelongsToTenant` models (`DeviceSession`,
  `LoginHistory`, `AccountSharingFlag`, `GdprExportRequest`,
  `GdprDeletionRequest`, all registered in `TenantModelRegistry`) plus 2
  deliberately-not-tenant-scoped ones (`AuditLogEntry`, `CookieConsent`,
  same `ImpersonationAudit`-style exception S9 B3 established);
  `DeviceFingerprintService` (UA + first-party cookie hash) +
  `DeviceRegistrar` enforcing a config'd registered-device limit (default
  2) at the one point every login surface already funnels through
  (`Illuminate\Auth\Events\Login`); an Admin/Teach-panel "My devices" page
  with remote logout, enforced lazily on the revoked device's next request;
  `LoginHistory` + a plain-haversine `ImpossibleTravelDetector` (config'd
  max plausible km/h, `FakeGeoIpResolver` standing in for a real MaxMind
  driver, deferred — no `.mmdb` file to test against in this environment);
  a daily account-sharing sweep flagging by distinct-device-count
  threshold, forcing re-auth rather than locking the account.
- **B5-B9** built the general audit log + GDPR/DPDP surface: `AuditLogger`
  (`audit_log_entries`, written synchronously so the record survives even
  a failed queue worker) wired into role assignment, impersonation, plan
  change, and tenant suspension; a monthly 365-day-retention prune sweep +
  Super-panel read-only viewer; a cookie-consent banner recorded
  per-session before login and reconciled to the user on authentication;
  an async GDPR export job (zip of every domain's rows for one user,
  signed download link, matching `ExportRevenueRegister`'s S9 B11 shape)
  and a GDPR deletion service (anonymize PII on rows with a legal/
  financial retention requirement, hard-delete everything else, 30-day
  grace window before a daily sweep executes it).
- **B10-B13** built the resilience half (V1-V4): `App\Support\Resilience\CircuitBreaker`
  (Redis `INCR`/`SETEX`, half-open implicit via TTL expiry) wrapped as a
  decorator around all 6 external-provider drivers (Bunny, Claude, both
  Razorpay integrations, WhatsApp BSP, live-class) with a Sentry alert on
  trip and a shared dashboard widget; `AggregateCache` standing up this
  app's first-ever cache layer for `GradebookService::scoreFor()`/
  `StudentMetrics::summarize()`/`InstituteOverviewWidget`, busted on every
  write path that can change the underlying numbers; `SoftDeletes` +
  `TrashedFilter`/`RestoreAction` closing the 4 real gaps
  (`FeePayment`/`FeeInvoice`/`TenantSubscription`/`SubscriptionInvoice` —
  3 of the 4 needed a brand-new minimal browse-only Filament resource,
  since only `FeeInvoice` already had one); Form Request extraction on the
  last 2 inline-`validate()` controllers.
- **B14's own dedicated review pass** — re-read every B0-B13 file for real
  bugs, not just re-confirmed the suite stayed green — found and fixed 2:
  `DeviceRegistrar` inserts a fresh `device_sessions` row (not a revived
  one) when a revoked fingerprint logs back in, but `EnsureDeviceNotRevoked`
  checked *any* historical row for that fingerprint rather than the latest
  one, permanently locking out a legitimately-relogged-in device;
  `RoleAssignmentPolicy::assign()` wrote its `'role.assigned'` audit row
  before the seat-quota check that can still throw, leaving a false
  "success" record for a role grant that never took effect. Both fixed
  with regression tests, plus one new lifecycle E2E test chaining login →
  device-limit-hit → remote-logout → login-history-flagged in one run,
  mirroring S11 B16's own `SubscriptionLifecycleE2ETest` posture.

**Test suite: 1094/1093 passing.** Same 1 pre-existing failure carried
since S1/S2 (`CourseSlugUniquenessTest`, unrelated to S12, still out of
scope) — 168 new tests across all 15 blocks.

**What's not real yet, on purpose**: `RealGeoIpResolver` — a genuine
MaxMind GeoLite2 driver — is deferred; `FakeGeoIpResolver` stays the bound
driver until a real `.mmdb` file is provisioned (MaxMind now gates even
the free tier behind account registration, so there's no way to build or
test a real driver in this environment). Circuit-breaker dashboard
visibility is a read-only widget on the existing Super/Admin overview
pages, not a dedicated Filament resource — V1 needed breaker state
visible, not a new CRUD surface.

**All 15 S12 blocks are now built, tested, and reviewed.**

## S13 — Discovery & Content Engagement: COMPLETE (all 18 blocks, 2026-08-19)

Fifth P2-backlog slice (after S9 hardening, S10 assignments/gradebook/
certificates, S11 billing, S12 security/resilience). `docs/FEATURES.md`
C7-C10 (catalog/discovery) + N7-N10 (engagement) — 8 roadmap items, 18
finer-grained build blocks. `docs/superpowers/specs/2026-08-19-s13-discovery-engagement-design.md`
and `...-plan.md` cover B0-B17. Extends the existing `Content` domain (7
of 8 items — discovery/engagement is Content's own business logic, not a
cross-cutting layer the way `Billing`/`Security` were) plus `Comms`
(direct messaging + moderation); no new domain. 12 new `BelongsToTenant`
models — the widest single-slice model count so far (S12 had 5), all
registered in `TenantModelRegistry`.

- **B0-B4** built C7/C10 (catalog + discovery): `Category` (self-referential
  `parent_id`) + `Tag`, `course_category`/`course_tag` pivots, Admin-panel
  CRUD; public `CourseCatalog` Livewire page (category/tag filters,
  Eloquent `LIKE` search, no raw SQL); `Wishlist` toggle + "My wishlist"
  view; `RecommendationService` (same-category/tag heuristic, explicitly
  not a real behavioural recommender — zero production traffic to train
  one on yet).
- **B5-B6** built C8 (prerequisites + sequential unlocking):
  `lesson_prerequisites` self-referential pivot + `PrerequisiteGraph`
  (bounded-DFS circular-dependency check, write-time not read-time);
  `LessonCompletion` (per-lesson-type binary heuristic — video watched
  past a config'd threshold, quiz attempted, assignment submitted,
  everything else presence-based) wired into `LessonAccess::canStream()`,
  staff bypass unchanged.
- **B7-B8** built C9 (Programs — course bundles): `Program`/`program_batch`
  schema (bundles `Batch` rows, this app's real sellable unit, not raw
  `Course` rows) + Admin-panel CRUD; `fee_invoices.program_id` nullable FK
  (`batch_id`/`enrolment_id` relaxed to nullable alongside it — a bundle
  invoice has neither); `ProgramEnrolmentService` creates one `Enrolment`
  per bundled batch on payment capture, wired through the existing
  Razorpay webhook path via a new `ProgramInvoicePaid` event (not a direct
  cross-domain call — see B17 below).
- **B9-B11** built N7/N8 (lesson Q&A + notes/bookmarks): `LessonQuestion`/
  `LessonQuestionReply`/`LessonQuestionVote` — post/reply/upvote gated by
  `LessonAccess::canStream()`'s own verdict, resolved-marking
  instructor/admin-only; `LessonNote` (nullable `body` doubles as a
  bookmark marker), timeline markers on the video player reusing
  `DoubtChat`'s own Alpine.js seek-to dispatch pattern.
- **B12-B13** built N9 (direct messaging): new Comms-domain
  `DirectMessage`/`BlockedUser`/`MessageReport` — 1:1, tenant-scoped,
  symmetric block enforcement at send time (one directed row, checked
  both directions); Admin-panel moderation queue (`actioned` hides a
  message from its thread — display-time only, row untouched;
  `dismissed` clears the flag — flag-don't-auto-act, same posture
  `AccountSharingFlag` already established in S12).
- **B14-B15** built N10 (course ratings + reviews): `CourseReview`
  (unique per learner per course, DB `CHECK` on rating 1-5),
  `CourseReviewPolicy` gates submission on genuine (active/expired)
  enrolment in any batch of the course, not scoped to the current one;
  instructor-response write path; Admin-panel hide/show moderation —
  genuinely reversible both directions (design decision 11), unlike
  message reports' one-way status transitions.
- **B16** — `TenantModelRegistry` sweep, confirmed exact-complete (all 12
  new models registered as each block built them, not deferred).
- **B17's own dedicated review pass** — 8 parallel finder agents (line-by-line
  diff, removed-behavior audit, cross-file tracer, reuse, simplification,
  efficiency, altitude, CLAUDE.md conventions), same discipline S9 B14/
  S10 B15/S11 B16/S12 B14 each already established. **Found and fixed 7
  real bugs:** (1) `PaymentReconciler` called `ProgramEnrolmentService`
  directly from Fees into Content — a hard CLAUDE.md "Events only"
  violation, independently flagged by 3 of the 8 agents — fixed with a
  new `ProgramInvoicePaid` event + Content-side listener, mirroring
  `PaymentCaptured`'s own shape. (2) `TeachPanelProvider`'s directory-wide
  resource discovery silently exposed Category/Tag/Program/CourseReview
  management (taxonomy governance, bundle pricing, review moderation) to
  every instructor — `AdminPanelProvider`'s own explicit `->resources()`
  array only ever *added* those to Admin, never removed them from Teach's
  scan; fixed by switching Teach to the same explicit-array pattern.
  (3) `LessonResource`'s prerequisite field's same-course scoping was
  UI-only — confirmed against Filament's own vendor source that
  `modifyRelationshipQueryUsing` never constrains what `sync()` actually
  writes — and the one real server-side gate silently skipped validation
  on an unresolvable id instead of failing; fixed to fail loudly on both
  a cross-course and a bogus id. (4) `CourseResource`'s categories/tags
  fields had zero server-side validation at all, same gap one step
  further; fixed with a tenant-scoped existence check. (5)
  `CourseReviewPolicy::canReview()` counted *any* enrolment status,
  letting a rejected or never-paid applicant post a public review; fixed
  to require genuine (active/expired) participation.
  (6) `CourseCatalog::courses()` reimplemented course visibility with a
  bare status check instead of reusing `LessonAccess`, silently dropping
  the staff-preview bypass — the exact "one place answers this" gap
  `LessonAccess`'s own docblock already warns about once before (S5 B8);
  fixed by widening `LessonAccess::isStaff()` to `public` and reusing it.
  (7) `ProgramEnrolmentService::enrolFromInvoice()` had a TOCTOU race on
  concurrent webhook deliveries, unlike `BatchEnrolmentService::createEnrolment()`'s
  own established try/catch pattern for the identical unique index; fixed
  the same way. All 7 ship with regression tests, plus one new full
  lifecycle E2E test (`DiscoveryEngagementLifecycleE2ETest`, chains
  catalog search → wishlist → real enrolment → prerequisite-denied →
  completion-unlocked in one run).

**Test suite: 1206/1207 passing.** Same 1 pre-existing, unrelated
`CourseSlugUniquenessTest` failure carried since S1/S2, still out of
scope — 113 new tests across all 18 blocks.

**What's not real yet, on purpose**: program checkout has no UI/controller
yet — B8 built only the payment-capture half (`FeeInvoiceService::invoiceForProgram()`
→ webhook → `ProgramEnrolmentService`), so a program purchase is fully
wired and idempotency-correct but currently unreachable by a real user;
`ExportRevenueRegister`/`FeeInvoiceResource` both still assume every
invoice has a `batch`, so a program invoice would show blank/"Untitled"
in the finance export and admin list once checkout exists — revisit
together. `syllabus-tree.blade.php` shows "Enrol to access" for a lesson
actually blocked by an unmet prerequisite (not enrolment) — `canStream()`
returns a bare bool with no reason code to render a more accurate message
from; needs a reason-aware return shape threaded through every call site,
a bigger change than the review pass should make unilaterally. Several
N+1 query patterns flagged by the efficiency review
(`LessonAccess::canStream()`'s prerequisite walk, `RecommendationService::forViewer()`'s
per-course taxonomy pluck, `SyllabusTree`'s per-lesson quiz/assignment
lookups) are real but left alone — zero production traffic yet to
justify the added complexity. Recommendations remain an MVP
same-category/tag heuristic, not a real behavioural recommender, by
design (no enrolment-pattern volume exists yet to train one on).

**All 18 S13 blocks are now built, tested, and reviewed.****

## S14 — Video & Live-Class Depth: COMPLETE (all 19 blocks, 2026-08-22)

Sixth P2-backlog slice (after S9 hardening, S10 assignments/gradebook/
certificates, S11 billing, S12 security/resilience, S13 discovery/
engagement). `docs/ROADMAP.md`'s own S14 row lists D9-D13/E4/G5-G10 (12
items), but D10/D11/D12 (multi-DRM, forensic watermark, offline DRM) are
P3-tier, cut from this slice's real build scope (9 items) — each needs a
real paid vendor relationship this platform has no reason to have yet.
`docs/superpowers/specs/2026-08-19-s14-video-live-depth-design.md` and
`...-plan.md` cover B0-B18. Extends `Content` (concurrent-playback,
subtitles, print/save protection) and `Batches` (Zoom OAuth, recording
capture, Google Meet, live polls); no new domain. 6 new `BelongsToTenant`
models (`LessonStreamSession`, `InstituteZoomConnection`, `LiveRecording`,
`LivePoll`, `LivePollOption`, `LivePollResponse`), all registered in
`TenantModelRegistry`, confirmed exact-complete in B17.

- **B0-B1** built D9 (concurrent-playback limiting): `lesson_stream_sessions`
  + `ConcurrentStreamGuard` — one *active* stream per user across different
  lessons (a heartbeat within `config('content.stream_session_timeout_seconds')`),
  wired into `StreamLessonController`/`WatchHeartbeatController` (the one
  place session rows get written), daily stale-row sweep for
  reporting/cleanliness (enforcement is read-time, not the sweep).
- **B2-B3** built D13 (auto-subtitles + instructor editing): `SubtitleGenerator`
  renders SRT/VTT straight from the existing ingestion transcript chunks
  (no new AI call) via a queued listener firing on the same
  `LessonArtifactReady` event `StartIngestion`'s own chain already
  produces; a `LessonResource` textarea lets an instructor overwrite the
  same VTT file; `<track>` wired onto the player from a temporary signed
  route, same shape the PDF download route already uses.
- **B4-B5** built E4 (print/save protection): browser-dependent deterrents
  (`#toolbar=0` + right-click guard for PDF, `@media print` + guard for
  text — honestly framed as a deterrent, not real DRM, same posture the
  existing video watermark feature already takes), gated behind a new
  per-lesson `print_disabled` toggle (default off, opt-in).
- **B6-B7** built G7 (Zoom OAuth per institute): `institute_zoom_connections`
  (one row per tenant) + a direct-to-Zoom-REST-API OAuth flow (no
  Socialite driver exists for Zoom) + `ZoomMeetingsProvider` (real meeting
  creation, `start_url` for staff vs `join_url` for students) — a new
  `zoom_meetings_oauth` `live_provider` option alongside the pre-existing,
  still-unbound `zoom_video_sdk` (a different mechanism: client-side JWT
  Video SDK, not a real per-institute OAuth-connected Meetings API).
- **B8-B9** built G5/G6 (recording capture → storage → playback):
  `live_recordings` + a real Zoom webhook (`ZoomRecordingWebhookHandler`,
  genuine HMAC-SHA256 signature verification + the unsigned
  `endpoint.url_validation` handshake) + `DownloadLiveRecording` (downloads
  onto the lesson's existing `video_disk_path` — no new playback column —
  and fires the *unchanged* `LessonVideoReady` into S3's ingestion
  pipeline). G6's "signed URL + enrolment check" needed zero new code
  (`StreamLessonController` already treats `video`/`live_session`
  identically) — confirmed by a dedicated B10 verification test rather
  than assumed.
- **B11-B12** built G10 pt.2/G8 (token-expiry handling + Google Meet):
  `ZoomMeetingsProvider` now refreshes an expired OAuth `access_token` via
  the stored `refresh_token` before every call, storing Zoom's rotated
  refresh token (not reusing the old one); `ZoomVideoSdkProvider`'s own
  2-hour join-JWT confirmed already correct. New `GoogleMeetProvider`
  (Calendar API `conferenceData`/`hangoutsMeet` event creation) — no
  per-institute connection table exists for Google, so it uses one
  platform-wide `GOOGLE_CALENDAR_REFRESH_TOKEN`; Meet has no host/
  participant URL split, so every viewer gets the same link (Google's own
  meeting lobby is the real access control, a documented limitation).
- **B13-B16** built G9 (live polls): `live_polls`/`live_poll_options`/
  `live_poll_responses` (unique per student per poll, DB-level, same
  `lesson_question_votes` defense-in-depth precedent from S13);
  `LivePollService` (create/open/close, reuses
  `EnrolmentApprovalPolicy::canDecide()` rather than a new role constant,
  `open()` gated to the session actually being live); `LivePollResponse`
  (student vote, gated by `LessonAccess::canJoinLive()`'s own verdict,
  app-check-plus-unique-constraint one-vote enforcement); `LivePollBoard`
  (instructor launch controls + the shared instructor/student results
  view, `wire:poll.2s` while open — B14 shipped the service with no UI to
  reach it, B16 is what closes that gap). Both Livewire components sit on
  the syllabus tree's own live_session row, next to the Join link, since
  students leave the app once they click it.
- **B18's own dedicated review pass** — same discipline S9 B14/S10 B15/
  S11 B16/S12 B14/S13 B17 each already established, re-reading every
  B0-B17 file for real bugs rather than trusting a green suite alone.
  **Found and fixed 1 real bug:** `GenerateLessonSubtitles` (B2) is a
  `ShouldQueue` listener calling `$this->fail($e)` on a throw, but only
  used the `Queueable` trait — `fail()` lives on `InteractsWithQueue`,
  which `CallQueuedListener` only wires up when the class actually uses
  it, so a real generation failure would have fatally errored instead of
  marking `subtitle_status: failed`. `DownloadLiveRecording`'s own
  docblock (B9) had already flagged this exact bug class in itself and
  fixed it there, but explicitly left this sibling instance unfixed —
  B18 closed it, with a new regression test forcing the throw
  (previously untested, since the happy-path test alone never exercised
  the catch branch). Two new lifecycle E2E tests
  (`S14VideoLiveDepthE2ETest`): Zoom OAuth connect → schedule on the new
  provider → recording-completed webhook → downloaded → ingested →
  playable, chained in one run; and a live poll instructor-launches → 2
  students respond → a 3rd non-enrolled student is refused → instructor
  closes → aggregation numbers match exactly.

**Test suite: 1283/1284 passing.** Same 1 pre-existing, unrelated
`CourseSlugUniquenessTest` failure carried since S1/S2, still out of
scope — 78 new tests across all 19 blocks.

**What's not real yet, on purpose**: `GoogleMeetProvider` and
`ZoomMeetingsProvider` are both real, `Http::fake()`-tested drivers with
no live vendor account exercised against them (same "flag, don't fake"
posture `ZoomVideoSdkProvider`/`RealGeoIpResolver` already established).
Live polls are `wire:poll.2s`, not real WebSockets (`BROADCAST_CONNECTION`
is still `log` — no real-time infra exists yet, same boundary S9 B8's
`FeeCheckout` and S13's direct messaging both already accepted). Google
Meet's per-viewer access control is Google's own meeting lobby, not this
app's role check. Print/save protection is a browser-dependent deterrent,
not real DRM.

**All 19 S14 blocks are now built, tested, and reviewed.**

## S15 — Batch & Institute Operations: COMPLETE (all 22 blocks, 2026-08-24)

Seventh P2-backlog slice (after S9 hardening, S10 assignments/gradebook/
certificates, S11 billing, S12 security/resilience, S13 discovery/
engagement, S14 video/live-class depth). `docs/ROADMAP.md`'s own S15 row
lists F6-F10/§S.S2-S7 (11 items), but §S.S7 (job board/placement module)
is P3-tier and not named in the roadmap's own one-line S15 scope
description — cut, same "P3 item absent from the headline gets cut"
precedent S14 established for D10-D12. Real build scope was 10 items, 22
finer-grained build blocks. `docs/superpowers/specs/2026-08-22-s15-batch-institute-operations-design.md`
and `...-plan.md` cover B0-B21. Closed a real, never-built P1 gap found
before writing the design: `docs/FEATURES.md`'s own §S.S1 row
("instructor invitations, 14-day link, assignment to courses/batches,
deactivate/reactivate") was never actually built despite being P1-tier
and S1 "Foundation" marked done — every `instructor`-role user had
blanket tenant-wide access (Spatie roles are global, not resource-scoped)
until this slice's own B0-B2 closed it. New `app/Domains/Operations/`
domain for the admission CRM (§S.S4) — pre-enrolment, pre-`User` in most
cases, doesn't fit `Batches` or `Tenancy`; everything else extends
`Tenancy`/`Batches`/`Comms`. 11 new `BelongsToTenant` models
(`InstructorInvitation`, `TimetableTemplate`, `ForumThread`, `ForumPost`,
`BatchFeedback`, `StudentImportBatch`, `Enquiry`, `EnquiryFollowUp`,
`IdCard`, `Notice`, `NoticeReadReceipt`), all registered in
`TenantModelRegistry`, confirmed exact-complete in B20 by a reverse sweep
(62-for-62 against every real `BelongsToTenant` usage in
`app/Domains/*/Models/*.php`).

- **B0-B2** built the slice's own foundation, since F7 and §S.S2 both
  need it first: `instructor_invitations` (token + 14-day expiry,
  `batch_ids` selected at invite time) + `batches.instructor_id`
  (nullable FK, one primary instructor per batch, not a pivot — design
  decision 1); Admin-panel invite/accept/assign/deactivate/reactivate
  (`InstructorInvitationService`, a host-agnostic accept Livewire page,
  on-demand `Notification::route()` since no `User` row exists yet for
  the invitee, `users.is_active` gating every panel login and both
  portals); per-instructor permission enforcement —
  `EnrolmentApprovalPolicy::canDecide()` tightened from "any same-tenant
  instructor" to "that batch's own assigned instructor" (institute_admin
  keeps the same-tenant check), `BatchResource` registered on the Teach
  panel for the first time (it had zero route there before this slice)
  and scoped to `instructor_id = auth()->id()`.
- **B3-B4** built F7 (timetable templates + conflict detection):
  `timetable_templates` (a reusable weekly-slot stamp — creating a
  session "from" one copies fields at creation time, no live FK, no
  retroactive recompute on template edit — design decision 3) +
  `TeacherConflictDetector` (write-time overlap check across every
  session sharing an instructor, any batch, PHP-side window comparison
  not raw SQL, wired into `BatchSessionsRelationManager`'s own
  `->rule()`).
- **B5-B6** built F6 (discussion forum): `forum_threads`/`forum_posts`
  (depth capped at 3, enforced at write time via a stored `depth`
  column — a depth-3 reply is refused, not silently flattened) +
  `BatchForum`, a batch-scoped Livewire component, enrolment-gated with
  an institute_admin/assigned-instructor bypass.
- **B7-B8** built F8 (batch feedback): `batch_feedback` (unique per
  student per batch, 1-5 DB `CHECK`, same `CourseReview` precedent from
  S13) + a read-only `FeedbackRelationManager` on `BatchResource` using
  Filament's own `Average` column summarizer.
- **B9-B10** built F9 (bulk student CSV import): `StudentCsvImporter`
  (phase 1, synchronous parse + validate on upload, ≤5000 rows, immediate
  file-shape feedback) + `ImportStudentsFromCsv` (phase 2, a real
  `ShouldQueue` creation job + a combined, sorted, downloadable per-row
  error report) — this app's first real CSV import (`maatwebsite/excel`
  had only ever been used for export in every prior slice).
- **B11-B12** built F10 (bulk enrolment + bulk invitations as background
  jobs): `BulkEnrolStudents` and `InviteInstructorsFromEmailList` — both
  thin `ShouldQueue` wrappers around the single-row path already built
  (`BatchEnrolmentService::addManually()`, B0/B1's own invitation
  service), not new bulk-insert code paths, inheriting every existing
  guarantee (seat-limit locking, policy gates) for free.
- **B13-B14** built §S.S4 (admission CRM): new `Operations` domain,
  `enquiries` + `enquiry_follow_ups`; `EnquiryConversionService::convert()`
  writes a real `Enrolment` through the existing `BatchEnrolmentService`,
  not a parallel enrolment mechanism (design decision 9) —
  find-or-create the student `User` by email (same cross-tenant-email
  refusal posture B10-B12 already established), grants the `student`
  role, then enrols.
- **B15** built §S.S5 (ID cards): `id_cards` + `IdCardGenerator` (dompdf
  + a QR code baked in as a base64 PNG data URI — new to this codebase,
  `Certificate`'s own QR only ever renders inline SVG on a public web
  page, a different rendering context), issued on demand only, never
  auto-generated on enrolment (design decision 10 — unlike `Certificate`,
  an ID card has no completion criteria to wait for).
- **B16-B17** built §S.S6 (noticeboard): `notices` (role-targeted via an
  `audience_roles` JSON column, a broadcast not a per-recipient message —
  design decision 11) + Admin-panel authoring with a "Publish now" action,
  then `notice_read_receipts` + a `NoticeBoard` Livewire component
  embedded on both the student and parent dashboards. Fixing B17's own
  missing `Livewire::component()` alias also surfaced that **B6's
  `comms.batch-forum` and B7's `batches.batch-feedback-form` had the
  exact same gap** — all three had been silently 500ing on their real
  host pages the whole time, since neither block's own tests ever hit
  those pages over real HTTP (only `Livewire::test()`, which bypasses
  tag-name resolution entirely). All three registered now.
- **B18-B19** built S3's real gap-close (the only two report types with
  no export at all): `ExportEnrolmentRegister` and
  `ExportCompletionRegister`, both copying `ExportAttendanceRegister`'s
  exact shape (`ShouldQueue` job + `RebindsTenant` + a bare Filament
  export page + a gated download controller, `EscapesCsvFormulas`) — no
  new architectural pattern, the fourth and fifth exports built this
  exact way. Completion reuses `GradebookService::isComplete()`/
  `scoreFor()` verbatim, including that service's own
  soft-deleted-batch/course guard, not re-derived.
- **B20** — `TenantModelRegistry` sweep, confirmed exact-complete: all 11
  named models present with `seedAttributes()`/`updateAttributes()`
  entries, plus a reverse grep over every real `BelongsToTenant` usage
  finding nothing missing (`ImpersonationAudit` correctly stays
  unregistered, by design, unrelated to this slice).
- **B21's own dedicated review pass** — 10 parallel finder agents (line-
  by-line diff scan, removed-behavior audit, cross-file tracer, a
  PHP/Laravel-pitfall specialist, wrapper/proxy correctness, efficiency,
  altitude/right-depth, simplification, CLAUDE.md conventions, and reuse/
  duplication), the widest review pass this discipline has run yet,
  matching this being the largest single slice by file count (74 files,
  3576 insertions) — same discipline S9 B14/S10 B15/S11 B16/S12 B14/S13
  B17/S14 B18 each already established, re-reading every B0-B20 file for
  real bugs rather than trusting a green suite alone. **Found and fixed 7
  real bugs, each independently corroborated by 2-4 of the 10 agents:**
  1. A cross-tenant + cross-instructor leak on `DownloadIdCardController`
     (role-only check, no batch-ownership check — any instructor in the
     tenant could download any *other* instructor's own batch's ID cards)
     and `DownloadStudentImportErrorReportController` (role-only check,
     no explicit tenant check — an institute_admin logged in on a
     *different* tenant's subdomain could resolve and download another
     institute's CSV error report) — the exact leak class this same
     slice's own B18/B19 sibling export controllers were already
     hardened against. Fixed by reusing
     `EnrolmentApprovalPolicy::canDecide()` (closes both the tenant and
     per-instructor gap in one call) and adding the same explicit
     `$viewer->tenant_id === CurrentTenant::id()` check the sibling
     controllers already had.
  2. `batches.instructor_id` had **no reachable Admin-panel field
     anywhere** — the only production code path that ever wrote it was
     `InstructorInvitationService::accept()`, so an admin assigning an
     already-active instructor (not routing through a fresh email
     invitation), or any batch that existed before this slice's migration
     (nullable, no backfill), left `instructor_id` permanently `null`.
     Silently broke three things this same slice built on that column:
     `EnrolmentApprovalPolicy::canDecide()` (the assigned instructor
     couldn't approve/reject/cancel/bulk-enrol/issue ID cards for their
     own batch), `StudentProgress` (Teach panel roster went empty), and
     `TeacherConflictDetector` (its own `instructor_id === null` early
     return meant the double-booking guard silently never fired for such
     a batch). Fixed with an Admin-only `instructor_id` `Select` on
     `BatchResource::form()` (hidden on the Teach panel — an instructor
     editing their own batch shouldn't reassign it away from themselves).
  3. `StudentCsvImporter::parse()` crashed the entire synchronous upload
     with an uncaught `ValueError` (`array_combine()`, PHP 8) on one
     column-count-mismatched row — a very easy mistake authoring a CSV by
     hand and omitting an "optional" trailing column. Fixed by counting
     first and routing a mismatch into the same per-row `$errors` array
     every other validation failure already uses.
  4. A UTF-8 BOM wasn't stripped — Excel's own "CSV UTF-8" save option
     always prepends one, silently attaching it to the first header cell
     and failing a perfectly valid re-upload's `badHeaders()` check.
     Fixed with a `preg_replace` strip before line-splitting.
  5. `NoticeBoard`/`Notice::isPublished()` treated any non-null
     `published_at` as "live now," ignoring a future-dated timestamp —
     `NoticeResource`'s own `DateTimePicker` is freely editable to any
     date with no min/max, so a "schedule for later" read of the UI
     actually published immediately. Separately, a roleless viewer
     bypassed the audience filter entirely: an empty
     `orWhereJsonContains` loop produces an empty `where(Closure)` group,
     which the query builder drops rather than matching nothing,
     degrading the whole query to "every published notice in the
     tenant." Fixed both (`published_at <= now()`, an explicit
     empty-roles early return), plus hardened `markAsRead()` to re-check
     visibility before writing a receipt for an arbitrary client-supplied
     notice id.
  6. `BatchForum`'s read path (`threads()`/`openThread()`/`postTree()`/
     `render()`) never called `canParticipate()` — only the write paths
     (`createThread()`/`reply()`) did, and the embedding route carries no
     `auth` middleware. Any guest or non-enrolled/non-staff tenant user
     could read every thread title and the full nested post tree (author
     names + bodies) for a batch they had no relationship to. Fixed by
     gating `render()`'s data and `openThread()` itself behind the same
     `canParticipate()` check the write paths already used.
  7. `IdCardGenerator` had a check-then-write race — the Filament action's
     `!idCard()->exists()` visibility guard is UI-only, no DB constraint
     backs it, and the disk path is deterministic per enrolment; two
     near-simultaneous "Issue ID card" clicks could both pass, each write
     a distinct `card_number` to the *same* path, leaving two `IdCard`
     rows with the second PDF silently overwriting the first. Fixed with
     `DB::transaction()` + `lockForUpdate()` on the `Enrolment` row,
     making `generate()` idempotent — a concurrent or repeat call returns
     the existing card instead of creating a duplicate.
  Also capped the CSV upload field's size (the UI's own "Up to 5000 rows"
  claim was previously unenforced — Phase 1 reads the whole file into
  memory synchronously, in-request). **Flagged, not fixed** — documented
  limitations, same "flag rather than fix under this block's time-boxed
  scope" posture S11 B15 took for its own webhook/manual-change race:
  `TeacherConflictDetector` has a check-then-write race of its own (the
  overlap check runs inside a synchronous Filament `->rule()`, before
  Filament's own separate persistence step, so no transaction the rule
  itself opens can span both — two near-simultaneous overlapping
  submissions for the same instructor could both pass); the direct
  Comms→Assignments/Operations→Batches/Operations→Tenancy service calls
  this slice adds each bypass CLAUDE.md's "domains talk through Events/
  only" rule, but a repo-wide check confirmed this exact pattern is
  already pervasive across S1-S14 — not a new regression, existing
  architecture debt; and duplicated boilerplate across the 5 CSV-export
  Job/Controller/Filament-page triples and 3 separate "find-or-create a
  user across a tenant boundary" implementations, both real but scoped
  out of a bug-fix pass. Two new lifecycle E2E tests
  (`S15BatchInstituteOperationsE2ETest`), both chained through the real
  Filament resources/relation managers rather than services called in
  isolation: enquiry → follow-up → convert-to-enrolment → ID card issued;
  and instructor invite → accept → both assigned batches → a second
  overlapping session on the other assigned batch refused.

**Test suite: 1406/1407 passing.** Same 1 pre-existing, unrelated
`CourseSlugUniquenessTest` failure carried since S1/S2, still out of
scope — 124 new tests across all 22 blocks.

**What's not real yet, on purpose**: `TeacherConflictDetector`'s
check-then-write race (above) is a documented limitation, not a fix —
closing it properly needs an architecture change (the conflict check and
the session insert happening in one atomic step, which Filament's own
form-validation-then-persist lifecycle doesn't currently allow). ID cards
and noticeboard notices are plain-text/PDF only, no rich formatting.
Bulk CSV import is students only (bulk course/batch import is a
different, larger data-modeling problem, explicitly deferred). The
admission CRM stops at "convert to enrolment" — no marketing attribution,
ad-spend tracking, or lead-source analytics.

**All 22 S15 blocks are now built, tested, and reviewed.****

## S16 — Assessment & Attendance Depth: COMPLETE (all 18 blocks, 2026-08-25)

Eighth P2/P3-mixed backlog slice (after S9 hardening, S10 assignments/
gradebook/certificates, S11 billing, S12 security/resilience, S13
discovery/engagement, S14 video/live-class depth, S15 batch/institute
operations). `docs/ROADMAP.md`'s own S16 row lists I6-I11/H10-H13 (10
items as counted there), but I7 (numerical/JEE-NEET questions) already
shipped in S6 and H9 (WhatsApp-on-absence) already shipped in S8/S9 —
both verified before writing the design, not assumed from the roadmap's
own wording. Real build scope was 9 items, 18 finer-grained build blocks.
`docs/superpowers/specs/2026-08-24-s16-assessment-attendance-depth-design.md`
and `...-plan.md` cover B0-B17 in full per-block detail — not repeated
here. Unlike S9-S15, a deliberate mixed P2/P3 grab-bag, not a
phase-boundary slice (I6/I10/H10 are P2; I8/I9/I11/H11/H12/H13 are P3,
all named explicitly in the roadmap's own S16 headline, so none cut for
being P3-tier). No new domain — every item extended `Assessments`
(Question/Quiz-adjacent: I6/I8/I9/I10/I11), `Content` (`AttendanceRecord`
gained a `role` column, H7, foundation for H13), `Batches` (3 new
attendance writers: geofenced check-in H11, generic device webhook H12,
teacher-attendance sweep H13), or `Tenancy`/`Comms` (H10's per-institute
threshold + staff-facing at-risk view). 7 new `BelongsToTenant` models
(`BatchGeofence`, `DeviceWebhookCredential`, `TeacherAttendanceFlag`,
`QuizProctoringEvent`, `TestSeries`, `OmrTemplate`, `OmrSheetScan`), all
registered in `TenantModelRegistry`, confirmed exact-complete in B16 by a
reverse sweep (69-for-69 against every real `BelongsToTenant` usage in
`app/Domains/*/Models/*.php`).

- **B0** — `attendance_records.role` column (student/instructor), the H7
  foundation H13 needs. Zero-risk: every existing writer omits the new
  column, defaulting to `'student'` for free.
- **B1** — H10: `tenants.at_risk_threshold_percent` (nullable, falls back
  to `StudentMetrics`'s existing hardcoded 60%) + a new Admin settings
  page + staff-facing at-risk roster on both Admin (tenant-wide) and
  Teach (own-batches) panels — the real gap was narrow, `SendLowAttendanceAlert`
  already existed but was parent-only with no staff view at all.
- **B2/B3** — H11: `batch_geofences` (opt-in per batch) + `GeofenceCheckInService`
  reusing `ImpossibleTravelDetector`'s own haversine formula (extracted to
  shared `Support\Geo\HaversineDistance` rather than a cross-domain
  service call) + a student-facing `GeofenceCheckIn` Livewire component on
  a new host route.
- **B4** — H12: a generic, vendor-agnostic HMAC-signed device webhook
  (`DeviceWebhookCredential` + `DeviceWebhookController`), same signature
  scheme `ZoomRecordingWebhookHandler` already established. The payload
  carries no session reference, so the target session is resolved as the
  credential's own currently-live one for the identified user.
- **B5/B6** — H13: instructor self-marks their own attendance
  (Teach-panel-only action) + `TeacherAttendanceSweep` (daily, unscoped)
  detects an unmarked-past-end session, raises a `TeacherAttendanceFlag`,
  notifies every institute_admin, and a new `TeacherAttendanceFlagResource`
  lets an admin resolve it with an optional instructor reassignment — no
  automatic substitute matching, by design.
- **B7/B8** — I6: 4 new question types (`multi_select`, `fill_in_blank`,
  `matching`, `ordering`) with partial-credit grading, plus the Filament
  authoring UI and `QuizRunner` student-facing rendering. `matching`/
  `ordering` shuffle their display order once at authoring time so the
  correct answer isn't readable straight off the stored data.
- **B9/B10** — I8: opt-in-per-quiz proctoring (`quizzes.proctoring_enabled`)
  logging tab-switch/fullscreen-exit/copy/paste events, detection/evidence
  only, no lockdown; plus per-question `time_spent_seconds`, accumulated
  across autosaves via the existing S9 autosave path.
- **B11** — I9: a configurable violation threshold auto-submits an
  in-progress attempt (reusing the exact same `auto_submitted` call
  `AutoSubmitExpiredQuizAttempts` already makes for time-expiry) and sets
  `flagged_for_review`; a new `FlaggedQuizAttemptResource` review queue
  surfaces both the responses and the event log.
- **B12/B13** — I10: `test_series` bundles quizzes via a nullable FK
  (mirrors `Program`'s own bundle-via-FK shape); `TestSeriesRankingService`
  computes per-tenant rank/percentile/topper-comparison on demand
  (PHP-side sort, no raw-SQL window function) from each student's best
  attempt per quiz in the series; a student-facing results view shows only
  their own standing, never the full roster.
- **B14/B15** — I11: `OmrTemplate::generateLayout()` deterministically
  computes a bubble-grid coordinate layout (no freeform designer); the
  question set is drawn once at template-generation time via the same
  `QuizAttemptService::draw()` an online attempt's own `start()` uses, so
  a later scan grades against the exact sheet that was printed.
  `OmrScanner` reads bubble darkness via GD (no skew/rotation correction —
  a real, flagged production-accuracy limit); `ProcessOmrSheetScan`
  resolves the bubbled roll number against a real `Enrolment` scoped to
  the quiz's own course, queuing a non-match `failed` for manual
  resolution rather than dropping it, and grades a match through the
  existing `QuizAttemptService::submit()` path — no parallel
  "offline score" concept.
- **B16** — `TenantModelRegistry` reverse sweep, zero gaps found, no code
  changes needed.
- **B17** — lifecycle E2E tests (unmarked teacher session → flag → admin
  reassignment, chained in one run; a full test-series lifecycle — two
  quizzes bundled, two students, rank/percentile computed correctly for
  both) + this review pass + doc updates.

**What's still rough, on purpose:** `OmrScanner` has no skew/rotation
correction — only a flat, upright scan against the known coordinate grid
is supported (design doc §5's own flagged limitation, not discovered
late). H13's substitute-teacher workflow stops at flag + admin
notification, no automatic substitute matching. I10's "All-India Rank" is
computed per-tenant, not a literal cross-institute leaderboard (would
require a shared cross-tenant students table, directly contradicting this
app's own isolation guarantees). H12's device webhook is a generic,
vendor-agnostic contract — no relationship with any specific
biometric/RFID vendor to build a real SDK client against.

**All 18 S16 blocks are now built, tested, and reviewed.**

## S17 — AI Layer, Round 2: COMPLETE (all 14 blocks, 2026-08-26)

Ninth P2/P3-mixed backlog slice. `docs/ROADMAP.md`'s own S17 row lists
L9-L15 (7 items as counted there), but L10 (auto-subtitles) already
shipped in S14 and L15 (AI content-generation assist for instructors) is
P3-tier and not named in the roadmap's own S17 headline — same "not
named, not in scope yet" boundary S16 drew the other way around. Real
build scope was 5 items, 14 finer-grained build blocks.
`docs/superpowers/specs/2026-08-25-s17-ai-layer-round-2-design.md` and
`...-plan.md` cover B0-B13 in full per-block detail — not repeated here.
Unlike S16, this is a clean phase-boundary slice — all 5 items are P2. No
new domain — every item landed in the existing `AI` domain, reusing
`LessonArtifact`/`LessonArtifactChunk` (pgvector `nearestNeighbors`,
already proven by `DoubtChatService`), `AiProvider` (one new additive
method, `chatWithImage()`), and the `AiSpendCap`/circuit-breaker
decorators (already wrap every `AiProvider` call, so every new call site
inherited spend-cap/resilience guarantees for free — no new plumbing). 3
new `BelongsToTenant` models (`CourseOutlineDraft`, `LessonRevisionNote`,
`PhotoDoubtQuery`), each registered in `TenantModelRegistry` immediately
on creation rather than deferred to a single sweep block — the registry's
own guard test runs on every `composer test`, so leaving a gap open
between blocks isn't safe. B12's reverse sweep confirmed 72-for-72 against
every real `BelongsToTenant` usage in `app/Domains`, zero gaps.

- **B0** — `AiProvider::chatWithImage()`, a new additive interface method
  (not an overload of `chat()`) implemented in `FakeAiDriver` and both
  guard decorators. No real driver exists yet (only `FakeAiDriver` is
  bound), so no fourth implementation was needed.
- **B1/B2** — L9: `course_outline_drafts` + `CourseOutlineGenerator`
  (`AiProvider::chat()` in structured-output mode, same pattern
  `ExtractLessonConcepts` already uses) → `CourseOutlineDraftResource`
  (Teach panel) with a header Action generating the outline (no blank
  create form, same shape L5's own "Generate with AI" action already
  established) → editable nested Repeater on the Edit page →
  `CourseOutlineApprovalService::approve()` creates real
  `Course`/`CourseSection`/`Lesson` rows directly (design decision: AI
  domain relating into Content's own models by direct Eloquent call, not
  an event — a synchronous, user-triggered click, not a domain reacting to
  another domain's state change). Re-approving/re-rejecting an
  already-decided draft is a no-op, not blocked — a double-click race
  can't create a duplicate `Course`.
- **B3/B4** — L11: `lesson_revision_notes` (unique per
  `[lesson_id, source_artifact_version]`, never overwritten) +
  `RevisionNotesGenerator` → instructor-triggered "Generate revision
  notes" row action + student-facing `LessonRevisionNotes` component
  gated through `LessonAccess::isVisibleTo()` directly in its own
  `mount()`. No ready artifact throws a new `LessonArtifactNotReadyException`
  (409) rather than writing a placeholder row — the table's own unique
  constraint is built around a real artifact version, so a placeholder
  would need a fake version number.
- **B5/B6** — L12: `SemanticSearchService` — embeds the query,
  `nearestNeighbors()` across `LessonArtifactChunk` (tenant-scoped,
  ready-artifacts-only), **filters every candidate through
  `LessonAccess::isVisibleTo()` before taking the top 10, not after** —
  the load-bearing test proves an invisible lesson's chunk stays excluded
  even when embedded with the exact query text (guaranteed nearest
  match). Wired into the student dashboard with a debounced search box;
  an empty query never calls `AiProvider::embed()`.
- **B7/B8/B9** — L13: `WeakTopicDetectionService::weakTopicsFor()` groups
  a student's graded quiz answers by exact-normalized `Question.concept`,
  flags anything below a 0.6 correctness-ratio placeholder threshold,
  cross-references `lesson_artifacts.concepts` for covering lessons (an
  unmatched concept stays reported with an empty lessons list, never
  dropped) → student-facing `WeakTopics` view on the syllabus page (own
  concepts only, structurally guaranteed by the service's own
  `auth()->user()->id` scoping) → `classWideWeakTopicsFor()` aggregates
  weak-student counts per concept across a batch's actively-enrolled
  students, surfaced as a `BatchWeakTopicsWidget` stat-tile widget on
  `EditBatch` — aggregate only, never a per-student breakdown.
- **B10/B11** — L14: `photo_doubt_queries` + `PhotoDoubtService::submit()`
  (extension allowlist + 5MB size cap, new `PhotoTooLargeException`) →
  `ProcessPhotoDoubtQuery` calls `chatWithImage()`, parses a fixed
  `"Question: ...\nSolution: ..."` text shape (no structured-output mode
  exists for vision calls), falls back to storing the whole response as
  `solution` when the format doesn't match. A driver failure transitions
  the row to `failed` inside a `catch (Throwable)`, not `$this->fail()` —
  an AI failure here is an expected, handled outcome, not a queue-worthy
  retry signal. Student-facing upload UI reuses `FeeCheckout`'s own
  `wire:poll.3s`-while-in-flight shape rather than inventing a new
  polling mechanism.
- **B12** — `TenantModelRegistry` reverse sweep, zero gaps found (a loose
  first-pass grep flagged `ImpersonationAudit`, which turned out to be a
  docblock-prose false positive explaining why that model *doesn't* use
  the trait — no code change needed).
- **B13** — lifecycle E2E tests (outline generation → instructor approval
  → real `Course`/`CourseSection`/`Lesson` rows matching the generated
  outline exactly; real `QuizAttemptService::submit()` grading → concept
  tagging → weak-topic report correctly excludes the concept answered
  correctly) + this review pass + doc updates. Review pass re-examined the
  two spots most likely to hide a `GeofenceCheckIn`-class bug (L12's
  visibility gate, L14's async failure path) and found both sound.

**One real testing gotcha hit and fixed (B4):** `Livewire::test()`'s
initial mount runs through a real, temporary route with exception
handling disabled for everything *except* `HttpException`/
`AuthorizationException` (`RequestBroker::
temporarilyDisableExceptionHandlingAndMiddleware()`), so a component-level
`abort_unless(..., 404)` surfaces as a normal 404 response
(`->assertNotFound()`), not a thrown PHP exception the way a plain
service-method gate (`DoubtChatService::ask()`) does. The first draft of
`LessonRevisionNotesTest.php`'s gate tests assumed the latter and silently
exercised nothing until traced down.

**What's still rough, on purpose:** L14's photo capture is a plain web
file input, not native camera capture (`docs/FEATURES.md` W4 already
reserves that for S25's mobile shell) and makes no accuracy guarantee on
handwriting quality. L13's concept matching is exact-string only — a
concept phrased differently by two authors won't link. L12's search is
scoped to lesson video/audio transcripts only, not PDFs/attachments/forum
posts. L9's outline generator produces structure only, never lesson
bodies/video/quiz content.

**All 14 S17 blocks are now built, tested, and reviewed.**
