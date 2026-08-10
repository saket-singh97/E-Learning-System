# Implementation Plan: S4 Batches + Enrolment

Full design: `2026-08-10-s4-batches-enrolment-design.md`. This is the
block-by-block build log — update the PROGRESS table as each block lands,
same as the S1/S2/S3 plans did.

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | Domain scaffold + scope lock | ✅ done |
| B1 | `Batch` model + CRUD (`/admin` panel) | ✅ done |
| B2 | `BatchSession` + session scheduling | ✅ done |
| B3 | `Enrolment` core — three enrol modes | ✅ done |
| B4 | Waitlist + FIFO auto-promotion | ✅ done |
| B5 | Approval expiry (scheduler + notification) | ✅ done |
| B6 | `attendance_records` schema pin | ✅ done |
| B7 | Student batch catalog + self-enrol UI | ✅ done |
| B8 | Full test coverage, isolation registration, review pass | ✅ done |

**Test suite: 208/209 passing.** B8 added 2 new tests (approve() seat-limit
guard, see "Post-S4 review" below); a second review round added 7 more (see
"Post-S4 review, round 2" below).
Same 1 pre-existing failure carried from B0/B1, still untouched by S4:
`CourseSlugUniquenessTest::it_allows_the_same_slug_to_be_reused_across_different_tenants`
— unscoped `Course::where(...)->count()` assertion doesn't account for
`BelongsToTenant`'s global scope filtering to the current tenant. S1/S2
territory. Flagged, not fixed (out of S4 scope).

**Deviation from this plan's written order:** `Batch` was registered in
`TenantModelRegistry::MODELS` in B1, not deferred to B8 as originally
written below. Reason: `tests/Isolation/CrossTenantTest.php`'s guard test
(`it fails when a tenant-scoped model in app/Domains is missing from the
registry`) fires the moment any `BelongsToTenant` model exists unregistered
— confirmed by running the suite with `Batch` unregistered, which failed
that guard immediately. Per CLAUDE.md ("every model... must be listed") and
this plan's own gate ("composer test must stay green after every block"),
deferring registration to B8 is incompatible with keeping the suite green
in between. `BatchSession`, `Enrolment`, `AttendanceRecord` will each get
registered in the block that creates them (B2, B3, B6) for the same reason
— B8's remaining job is the full review pass and `PROJECT-STATUS.md`
update, not the registry entries themselves.

**Deviation from this plan's written service contract (B3):** the design
doc's §3.4 contract lists six `BatchEnrolmentService` methods
(`enrol`, `approve`, `reject`, `cancel`, `seatsRemaining`, `canEnrol`) with
no manual-add method, but its own §3.1 data-flow diagram puts manual_add on
a separate branch, "not reachable from here — staff-only." Built a seventh
method, `addManually(Batch, User $student, User $actor): Enrolment`, so the
staff-only path still goes through the service rather than the
`EnrolmentsRelationManager` re-implementing status/source assignment inline
— keeps CLAUDE.md's centralising rule intact for the one path the pinned
contract didn't actually cover. `cancel()` is still B4's, per plan.

**Deviation from the design doc (B3):** `EnrolmentApprovalPolicy`'s "an
instructor assigned to the batch's course" (design doc §3.4) has no backing
schema — no course/batch-instructor pivot exists anywhere in this codebase,
and roles are global to a user, not per-course (see `RoleAssignmentPolicy`).
Read "assigned" as "same tenant as the batch" instead, mirroring
`RoleAssignmentPolicy`'s own same-tenant guard. `EnrolmentApprovalPolicy::canDecide()`
denies before the Filament action runs, throwing the new
`EnrolmentApprovalDeniedException` (registered in `bootstrap/app.php`,
`abort(403)`) — same shape as `PrivilegeEscalationException`, per the
design doc's own comparison. Revisit if a real per-course assignment model
ever lands (would tighten "assigned" from tenant-wide to course-specific).

**Deviation from this plan's written order (B4):** `StudentEnrolled` firing
was pinned to B4 (Events/ didn't exist before it), but the design doc's own
§3.1 flow already calls for firing it on `enrolDirect()`'s active branch and
on `approve()` — both built in B3, before the event class existed. B4 wires
the dispatch calls into those two existing B3 code paths as well as the new
`promoteNextWaitlisted()`, so all three "lands active" paths fire the same
event rather than only the new one.

**Deviation from this plan's literal pseudocode (B4):** the design doc's
§3.2 flow diagram shows `cancel()` calling `promoteNextWaitlisted()`
unconditionally. Read literally, cancelling a `waitlisted` or
`pending_approval` row (no seat was ever occupied) would still promote the
oldest waitlisted student — over-filling the batch past `seat_limit`, since
no seat was actually freed. Guarded `cancel()` on the enrolment's prior
status being `active` before calling `promoteNextWaitlisted()` or firing
`SeatReleased`; matches the design doc's own prose ("promotion is triggered
by anything that reduces active enrolment count"), just not its pseudocode.
Covered by the added "cancelling a non-active enrolment does not promote
anyone" test.

**Deviation from this plan's task list (B4):** the "Cancel enrolment" action
in `EnrolmentsRelationManager` runs through the same `authorizedActor()` gate
(`EnrolmentApprovalPolicy::canDecide()`) as approve/reject, though the plan
didn't call for a policy check on cancel specifically. Cancelling an active
seat is at least as sensitive as approving one, and the policy already
answers the right question ("may this actor act on this batch's
enrolments") — leaving cancel unguarded would have been the kind of gap
S1–S3's review passes always caught.

**Deviation from the domain's standard subfolder shape (B5):** the design
doc's own architecture section (§3.0) lists `Events/` as Batches' only
event-adjacent subfolder, but `SendEnrolmentExpiredNotification` needs
somewhere to live. Followed the precedent the AI domain already set
(`app/Domains/AI/Listeners/`, `StartIngestion`, `RetryIngestion`) rather than
inventing a new shape — a `Listeners/` folder alongside `Events/` is already
an established pattern in this codebase, just not yet in CLAUDE.md's
subfolder list. Wired via `Event::listen()` in `AppServiceProvider::boot()`,
same as the AI domain's pair, because Laravel's listener auto-discovery only
scans `app/Listeners`, not `app/Domains/*/Listeners` (see the existing
comment above those `Event::listen()` calls).

**Deviation from the design doc's pseudocode (B5):** neither
`ExpirePendingEnrolments` nor `SendEnrolmentExpiredNotification` implements
`ShouldQueue`, unlike the AI domain's ingestion chain. The job is dispatched
directly by the scheduler (`$schedule->job(new ExpirePendingEnrolments)`),
so `dispatch()` on a non-`ShouldQueue` job runs synchronously regardless of
`QUEUE_CONNECTION` — matches the design doc's own description of it as "a
first, minimal listener," and keeps the daily sweep and its notification
side effect observable in the same process for `composer test`'s
`Event::fake()` / `Notification::fake()` assertions, with no `Queue::fake()`
needed.

**Deviation from the design doc's flow (B5):** `notifications` table
migration is new — `EnrolmentRequestExpired`'s `database` channel needs it
and no such table existed anywhere in the app yet (verified: no
`notifications` migration before this block). Standard Laravel shape
(`uuid` id, polymorphic `notifiable`, `data`, `read_at`), not tenant-scoped
(`BelongsToTenant` doesn't apply — notifiable polymorphism already scopes
each row to its owning user).

---

## How to run this plan

Same pattern S1/S2/S3 were built with — no separate executor tooling, one
block at a time, in this repo, main branch. Paste into a fresh session:

```
Read docs/superpowers/specs/2026-08-10-s4-batches-enrolment-design.md and
docs/superpowers/specs/2026-08-10-s4-batches-enrolment-plan.md, then build
Block 0 of the S4 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

Repeat per block (`build Block 1`, `build Block 2`, …), or say `build S4
end to end, block by block, stopping only if a design decision needs my
input` to run the whole slice in one session. Either way, `composer test`
must stay green after every block — that's the gate, not a suggestion.

To run it as a supervised multi-step job instead of one prompt per block,
`superpowers:executing-plans` (`/executing-plans`) is built for exactly this
shape — point it at this file and it will walk the blocks with review
checkpoints between them.

---

## Why Block 0 exists

S1's B0 didn't exist (S1 was the first slice). S2's B0 closed three real gaps
left by S1. S3's B0 resolved one open decision (embedding model) plus a
flagged gap (`RebindsTenant`). S4's B0 is lighter than either — the design
doc's §2.3 review found nothing broken to fix first, only a domain skeleton
to create and three scope decisions to record (already made, §2.4 of the
design doc). B0 exists to make that explicit rather than skip straight to
B1 with undocumented assumptions.

---

## Scope decisions locked (2026-08-10 planning session)

Full rationale in the design doc §2.4. Summary for quick reference while
building:

1. **F1–F5 only.** F7 (timetable templates + conflict detection) is a
   fast-follow, not S4.
2. **`attendance_records`: schema only.** Migration + model, no marking UI,
   no service reads/writes it yet.
3. **Student batch catalog is full scope.** Not a stub — a real browse +
   self-enrol page, per explicit owner instruction.

---

## Order

```
B0 Domain scaffold ──► B1 Batch CRUD ──┬──► B2 Sessions
                                        │
                                        └──► B3 Enrolment core ──┬──► B4 Waitlist
                                                                  │
                                                                  ├──► B5 Approval expiry
                                                                  │
                                                                  └──► B7 Student catalog
                                                                            │
B6 attendance_records (independent, no dependency on B1-B7) ───────────────┤
                                                                            ▼
                                                                    B8 Tests + review
```

B6 has no dependency on anything else in this slice — it's a standalone
migration + model per the design doc's decision 2 — so it can be built in
parallel with B1–B5 if useful, but is sequenced last-but-one here to keep
the build log linear and match how S1's B10 (`lesson_artifacts`, also a
schema-only pin) was sequenced near the end of its slice.

---

## Block 0 — Domain scaffold + scope lock

**What:** Create `app/Domains/Batches/` with the standard subfolders
(`Models/`, `Services/`, `Filament/`, `Livewire/`, `Views/`, `Jobs/`,
`Policies/`, `Events/`). No view namespace registration needed — automatic
per `AppServiceProvider::registerDomainViewNamespaces()`. Confirm the three
scope decisions above are reflected in the design doc (they are, as of
2026-08-10).

**Why:** Every domain in this codebase carries the same subfolder shape;
scaffolding it first means B1 onward never has to ask "where does this go."

**Tasks:**
- [x] Create `app/Domains/Batches/{Models,Services,Filament,Livewire,Views,Jobs,Policies,Events}/`
- [x] Confirm `php artisan route:list` / a smoke request shows no errors from
      the new (empty) domain folder before building anything in it

**Tests:** none new — this block adds no behaviour.

---

## Block 1 — `Batch` model + CRUD

**What:** `batches` migration (design doc §3.5), `Batch` model
(`BelongsToTenant`, `SanitizesHtml` on `description`, `SoftDeletes`,
`$fillable` explicit — no `$guarded = []`, per CLAUDE.md), `belongsTo(Course)`.
`BatchResource` in `/admin` panel (institute admin creates/edits batches,
mirrors `CourseResource`'s shape) — course picker excludes non-published and
soft-deleted courses.

**Why:** Everything else in this slice hangs off a `Batch` row existing.

**Tasks:**
- [x] Migration: `batches` table, unique index on `(tenant_id, slug)` excluding
      soft-deleted (same pattern as the S1 round-1 courses-slug fix)
- [x] `Batch` model
- [x] `BatchResource` (`app/Domains/Batches/Filament/Resources/Batches/`) +
      `Pages/{List,Create,Edit}Batch.php`
- [x] Form validation: `starts_on` < `ends_on`, `seat_limit` nullable positive
      integer, `price` required when `is_paid`
- [x] (unplanned, forced by CLAUDE.md's registry rule) `Batch` registered in
      `TenantModelRegistry::MODELS`

**Tests:**
- [x] `BatchResource` CRUD (create, edit, list, soft-delete + restore)
- [x] Slug uniqueness excludes soft-deleted rows

---

## Block 2 — `BatchSession` + session scheduling (F5)

**What:** `batch_sessions` migration, `BatchSession` model
(`BelongsToTenant`), a relation manager under `BatchResource` for adding/
editing/reordering sessions.

**Why:** F5 is P1; also the calendar skeleton S5 will anchor real live
sessions to later, so getting the shape right now avoids a migration in S5.

**Tasks:**
- [x] Migration: `batch_sessions` table
- [x] `BatchSession` model
- [x] `BatchSessionsRelationManager` under `BatchResource`
- [x] Form validation: `duration_minutes` between 15 and 480 (F5's exact range)
- [x] (per B1's deviation note) `BatchSession` registered in
      `TenantModelRegistry::MODELS`

**Tests:**
- [x] Session CRUD via the relation manager
- [x] Duration validation rejects <15 and >480

---

## Block 3 — `Enrolment` core (F2's three modes)

**What:** `enrolments` migration, `Enrolment` model, `BatchEnrolmentService`
with `enrol()`, `canEnrol()`, `seatsRemaining()` (design doc §3.1, §3.4).
Manual-add path wired into `BatchResource`'s new `EnrolmentsRelationManager`
(staff-only "Add student" action → `status=active` immediately). Self-enrol
paths (direct, approval) get their service methods now; the student-facing
entry point (`BatchCatalog`) arrives in B7 — the service is built and tested
independently of its UI, same order S3 built `AiProvider` (B1) before the
ingestion pipeline that calls it (B3).

**Why:** This is the core of the slice — everything else (waitlist,
expiry, catalog) calls into this service rather than duplicating enrolment
logic.

**Tasks:**
- [x] Migration: `enrolments` table, unique index excluding soft-deleted
- [x] `Enrolment` model
- [x] `BatchEnrolmentService::enrol()` — all three modes, `lockForUpdate()`
      transaction for the seat-race case (design doc §3.1, §4)
- [x] `BatchEnrolmentService::canEnrol()`, `seatsRemaining()`
- [x] `EnrolmentApprovalPolicy` — assigned instructor or institute admin only
      (see PROGRESS's B3 deviation note on "assigned")
- [x] `EnrolmentsRelationManager` under `BatchResource`: list, manual-add,
      approve, reject actions
- [x] (unplanned, see PROGRESS's B3 deviation note) `BatchEnrolmentService::addManually()`
      — staff-only path the pinned §3.4 contract didn't name a method for
- [x] (unplanned, forced by CLAUDE.md's registry rule) `Enrolment` registered
      in `TenantModelRegistry::MODELS`

**Tests:**
- [x] `enrol()` under each of the three modes, correct resulting status
- [x] Seat-race: sequential `enrol()` calls for the last seat under
      `lockForUpdate()`, exactly one lands `active`, the other `waitlisted`
- [x] Duplicate enrolment attempt returns the existing row, no DB error
      surfaced
- [x] `EnrolmentApprovalPolicy`: same-tenant instructor allowed, other-tenant
      instructor denied, institute admin always allowed, student denied
- [x] `EnrolmentsRelationManager` approve/reject actions respect the policy

---

## Block 4 — Waitlist + FIFO auto-promotion (F3)

**What:** `BatchEnrolmentService::cancel()` and `promoteNextWaitlisted()`
(design doc §3.2). `StudentEnrolled` and `SeatReleased` events (`Events/`)
fired at the right points, even though nothing outside the Batches domain
listens yet — pinning the contract now, same move as S1's `lesson_artifacts`
and S3's `LessonVideoReady`.

**Why:** F3 is P1 and is exactly what the "eleventh student" step of the
demo script (design doc §6) exercises.

**Tasks:**
- [x] `BatchEnrolmentService::cancel()` — sets `cancelled`, calls
      `promoteNextWaitlisted()`
- [x] `promoteNextWaitlisted()` — oldest `waitlisted` row by `created_at`,
      promotes to `active`, fires `StudentEnrolled`
- [x] `Events/StudentEnrolled`, `Events/SeatReleased` classes
- [x] "Cancel enrolment" action in `EnrolmentsRelationManager`

**Tests:**
- [x] Cancelling an active enrolment promotes the oldest waitlisted row
- [x] Promotion order is strictly FIFO across 3+ waitlisted students
- [x] Cancelling when nobody is waitlisted is a no-op, not an error
- [x] `StudentEnrolled` fires exactly once per promotion

---

## Block 5 — Approval expiry (F4)

**What:** `ExpirePendingEnrolments` job (`Jobs/`), wired into
`bootstrap/app.php`'s `withSchedule()` — **first use of Laravel's task
scheduler in this codebase** (verified 2026-08-10: no `withSchedule()`
closure exists yet). `EnrolmentApprovalExpired` event, and the first
`Notification` class in this codebase (verified: no `Notifications/` folder
exists anywhere in `app/` as of this writing) sending the requester a
"your enrolment request expired" notice.

**Why:** F4 is P1. Flagged as new infrastructure (scheduler, notifications)
because both are genuinely new to the codebase, not because the feature
itself is unusually risky.

**Tasks:**
- [x] `ExpirePendingEnrolments` job — queries `pending_approval` rows past
      `expires_at`, sets `expired`, fires `EnrolmentApprovalExpired`
- [x] Add `->withSchedule()` to `bootstrap/app.php`, `->daily()`
- [x] `Events/EnrolmentApprovalExpired`
- [x] First `Notification` class: `EnrolmentRequestExpired` (mail + database
      channel, matching how the rest of the app already handles
      `MustVerifyEmail`-style notices)
- [x] Listener inside the Batches domain that sends the notification on
      `EnrolmentApprovalExpired`
- [x] (unplanned, forced by the database channel) `notifications` table
      migration — first `Notification` class in the app, no such table
      existed yet

**Tests:**
- [x] Job expires exactly rows past `expires_at`, leaves earlier-but-not-yet-
      expired rows untouched
- [x] Expiry boundary: a row expiring exactly `now()` is included
- [x] Notification is queued/sent on expiry (fake notification assertion)
- [x] (added, beyond the plan's list) non-`pending_approval` rows past
      `expires_at` are left untouched; no expiry means no notification sent

---

## Block 6 — `attendance_records` schema pin

**What:** Migration only, per decision 2 (§2.4 of the design doc, schema in
§3.5). `AttendanceRecord` model in `app/Domains/Content/Models/` (cross-
cutting, not Batches-specific — same reasoning as `BandwidthUsageDaily`'s
placement). No service, no UI, no writer.

**Why:** Pins the three-writer contract S1's design doc committed to,
without building UI the 2026-10-03 demo doesn't need.

**Tasks:**
- [x] Migration: `attendance_records` table (design doc §3.5), polymorphic
      `attendable_type`/`attendable_id`, indexed
- [x] `AttendanceRecord` model, `$fillable` explicit, `BelongsToTenant`
- [x] (per B1-B3's deviation note) `AttendanceRecord` registered in
      `TenantModelRegistry::MODELS`

**Tests:**
- [x] Migration runs cleanly, indexes exist
- [x] Model instantiates and mass-assigns correctly (no functional test
      needed beyond this — there's no behaviour yet to test)

---

## Block 7 — Student batch catalog + self-enrol UI (decision 3)

**What:** `BatchCatalog` Livewire component (`app/Domains/Batches/Livewire/`)
— lists published batches for the viewer's tenant, shows seats remaining
(via `BatchEnrolmentService::seatsRemaining()`), price, and a mode-aware
action button ("Join" for `self_enrol_direct`, "Request to join" for
`self_enrol_approval`, nothing actionable for `manual_only` — just informational).
Route added to `routes/student.php` (`/batches`), following the same
tenant-scoped pattern `/dashboard` already uses.

**Why:** Decision 3 — this is the real F2 student experience, not a stub,
and it's what the demo's "ten students enrolled" step actually walks
through live.

**Tasks:**
- [x] `BatchCatalog` Livewire component + `batch-catalog.blade.php` view
- [x] Route: `GET /batches` → `student.batches`, tenant-scoped like
      `student.dashboard`
- [x] Mode-aware action button, calls `BatchEnrolmentService::enrol()`
- [x] Waitlist position shown when the result is `waitlisted`
- [x] Link from `/dashboard` to `/batches`

**Tests:**
- [x] Catalog lists only `published` batches for the viewer's own tenant
      (cross-tenant leak check, mirrors the S1/S2 review pattern of testing
      the shared endpoint, not just the component in isolation)
- [x] Join button behaviour per each `self_enrol_mode`
- [x] Seats-remaining display updates after a join

**Deviation (B7):** `BatchCatalog` lives outside Livewire's default
`App\Livewire` auto-discovery root (same as `DoubtChat`, S3), so it's
registered with an explicit alias — `Livewire::component('batches.batch-catalog', BatchCatalog::class)`
in `AppServiceProvider::boot()`, tag `<livewire:batches.batch-catalog />`.

**Gotcha found (B7):** `route('student.batches')` / `route('student.dashboard')`
called from a Blade view throws `UrlGenerationException` ("Missing required
parameter: subdomain") — every route under `routes/student.php` is nested
inside `Route::domain('{subdomain}.lms.test')`, so `route()` needs
`['subdomain' => app(CurrentTenant::class)->get()->subdomain]` explicitly
passed even from a view rendered inside that same tenant's request. Not
covered by CLAUDE.md's existing route-param gotcha (that one is about
controller/closure parameter splicing, this is `route()` URL generation).
Both new cross-links (`student-portal.blade.php` → `/batches`,
`batch-catalog.blade.php` → `/dashboard`) needed the explicit param; worth
checking for the same mistake wherever a future block links between
tenant-domain routes from a view.

---

## Block 8 — Full test coverage, isolation registration, review pass

**What:** Register `Batch`, `BatchSession`, `Enrolment`, `AttendanceRecord`
in `TenantModelRegistry::MODELS` with `seedAttributes()`/`updateAttributes()`
entries. Run `composer test`, confirm zero regressions against the S1+S2+S3
baseline (137/137). Then a deliberate post-build bug-hunting review pass —
same practice every prior slice followed (S1 ×2 rounds, S2 ×1 round plus a
shared third pass, S3 ×2 rounds) — reading every B0–B7 file fresh rather
than trusting green tests alone, specifically checking the paths per-block
tests don't reach: the shared Livewire endpoint (per CLAUDE.md's tenant-
context warning), cross-panel access (can an instructor from tenant A reach
tenant B's batch via URL), and the seat-race transaction under real
concurrent load, not just sequential test calls.

**Why:** Every prior slice found real bugs in this pass, not style nits —
S1 found a cross-tenant leak and a wrong-panel-access bug, S2 found a
broken-login-in-real-browser bug and three crash paths, S3 found a chunk-
duplication bug. No reason to expect S4 is the first slice with nothing to
find.

**Tasks:**
- [x] `TenantModelRegistry` entries for all four new models (already done
      incrementally in B1/B2/B3/B6, per PROGRESS's deviation note — verified
      present, nothing left to add here)
- [x] `composer test` green, 137 + S4's new count, zero regressions
- [x] Post-S4 review pass, documented in this file's "Post-S4 review" section
      (added once the pass runs)
- [x] `docs/PROJECT-STATUS.md` updated: S4 marked complete, summary added

**Tests:** isolation suite auto-generates 4 models' worth of coverage; no
hand-written isolation tests (per CLAUDE.md — hand-writing them would skip
the guard that catches a forgotten registry entry).

---

## Acceptance criteria

1. An institute admin can create a batch against a published course with
   dates, seat limit, price, and a self-enrol mode, via `/admin`.
2. A student can browse `/batches`, see accurate seats-remaining, and join
   a `self_enrol_direct` batch — lands `active` if a seat is open,
   `waitlisted` if not.
3. A student requesting to join a `self_enrol_approval` batch lands
   `pending_approval`; staff can approve (→ `active`) or reject
   (→ `rejected`) from `/admin`.
4. An unactioned `pending_approval` enrolment auto-expires after 7 days and
   the requester receives a notification.
5. Cancelling an `active` enrolment on a full batch promotes the
   longest-waiting `waitlisted` student to `active` in the same request.
6. A batch has zero or more sessions, each 15–480 minutes, visible in
   `/admin`.
7. `attendance_records` exists as a migration with the three-writer shape;
   nothing reads or writes it yet.
8. All acceptance criteria above are demoable per the design doc §6 script.
9. `composer test` green, zero regressions in S1/S2/S3.
10. All four new tenant-owned models pass the generated isolation suite.

---

## Testing plan

| Layer | What | Count (approx.) |
|---|---|---|
| Unit | `BatchEnrolmentService` (all modes, seat-race, waitlist FIFO, expiry boundary) | ~10 |
| Unit | `EnrolmentApprovalPolicy` | ~3 |
| Feature | `BatchResource` + `BatchSessionsRelationManager` + `EnrolmentsRelationManager` CRUD/actions | ~9 |
| Feature | `BatchCatalog` Livewire (list, join per mode, seats display) | ~5 |
| Feature | `ExpirePendingEnrolments` job + notification | ~3 |
| Isolation | Generated, 4 new models | +4 models worth |

---

## Rollback plan

All four new tables are additive — no existing table is altered beyond a
new FK reference from `batches` to `courses` (read-only from `courses`'
side). Rollback is `php artisan migrate:rollback` for this slice's
migrations; no data migration or backfill exists yet to reverse, since S4
is new-build, not a modification of S1–S3 data.

---

## Effort estimate

| Component | Estimate |
|---|---|
| B0 scaffold | 0.5h |
| B1 Batch CRUD | 3h |
| B2 Sessions | 2h |
| B3 Enrolment core + service + policy | 5h |
| B4 Waitlist + promotion + events | 3h |
| B5 Approval expiry (scheduler + notification, both new infra) | 4h |
| B6 attendance_records pin | 1h |
| B7 Student catalog UI | 4h |
| B8 Tests, registry, review pass | 4h |
| **Total** | **~26.5h** |

---

## Files reference

| File | Change |
|---|---|
| `database/migrations/*_create_batches_table.php` | New |
| `database/migrations/*_create_batch_sessions_table.php` | New |
| `database/migrations/*_create_enrolments_table.php` | New |
| `database/migrations/*_create_attendance_records_table.php` | New |
| `app/Domains/Batches/Models/Batch.php` | New |
| `app/Domains/Batches/Models/BatchSession.php` | New |
| `app/Domains/Batches/Models/Enrolment.php` | New |
| `app/Domains/Content/Models/AttendanceRecord.php` | New (cross-cutting placement, see B6) |
| `app/Domains/Batches/Services/BatchEnrolmentService.php` | New |
| `app/Domains/Batches/Policies/EnrolmentApprovalPolicy.php` | New |
| `app/Domains/Batches/Events/StudentEnrolled.php` | New |
| `app/Domains/Batches/Events/SeatReleased.php` | New |
| `app/Domains/Batches/Events/EnrolmentApprovalExpired.php` | New |
| `app/Domains/Batches/Jobs/ExpirePendingEnrolments.php` | New |
| `app/Notifications/EnrolmentRequestExpired.php` | New (first Notification class in the app) |
| `app/Domains/Batches/Filament/Resources/Batches/BatchResource.php` (+`Pages/`, `RelationManagers/`) | New |
| `app/Domains/Batches/Livewire/BatchCatalog.php` | New |
| `app/Domains/Batches/Views/batch-catalog.blade.php` | New |
| `routes/student.php` | Add `/batches` route |
| `bootstrap/app.php` | Add `->withSchedule()` |
| `app/Support/Tenancy/TenantModelRegistry.php` | Add 4 model entries |
| `docs/PROJECT-STATUS.md` | S4 section added on completion |

---

## Out of scope

- F6 (batch discussion forum), F7 (timetable templates + conflict
  detection), F8 (batch feedback), F9 (bulk CSV import), F10 (bulk
  enrolment/invitation jobs) — all P2, fast-follow.
- Real payment collection for paid batches — S7.
- Attendance marking UI and any attendance-reading logic — S5 (schema only
  here, decision 2).
- Multi-branch organisation layer above batches (FEATURES.md A9).

---

## Definition of done

Same bar as S1/S2/S3: every block above checked off, `composer test` green
with zero regressions, all four new models isolation-suite-registered, demo
script (design doc §6) walked through and confirmed working, and a
post-build review pass completed and documented before `PROJECT-STATUS.md`
is updated to mark S4 complete.

---

## Post-S4 review

Full re-read of every B0-B7 file (models, service, policy, both relation
managers, `BatchResource`, `BatchCatalog` + its view, the job, the listener,
the notification, all four migrations, `routes/student.php`, `web.php`,
`bootstrap/app.php`, `TenantModelRegistry`) plus a fresh `composer test`,
same practice as S1 (×2 rounds), S2 (×1 round), S3 (×2 rounds).

**Found and fixed 1 real bug:**

- **`approve()` didn't respect `seat_limit`.** `BatchEnrolmentService::approve()`
  set a `pending_approval` row straight to `active` with no seat check —
  unlike `enrolDirect()`, which takes `lockForUpdate()` on the batch row
  specifically to stop two students overrunning `seat_limit`. A
  `self_enrol_approval` batch with a seat limit could be approved past
  capacity simply by staff working through the pending queue one row at a
  time (or two staff approving concurrently) — no test exercised `approve()`
  against a capped batch at all before this pass. The design doc's own §3.1
  flow diagram draws a seat check only on the `self_enrol_direct` branch, but
  nothing in the schema, the acceptance criteria, or `seatsRemaining()`
  suggests `seat_limit` was meant to apply to one self-enrol mode and not the
  other — a batch capped at 10 seats should stay capped at 10 regardless of
  which of the three sources filled them. Fixed: `approve()` now takes the
  same `lockForUpdate()` transaction `enrolDirect()` does and throws the new
  `BatchFullException` (`app/Exceptions/BatchFullException.php`, rendered as
  409, same one-render-per-class convention as `EnrolmentApprovalDeniedException`)
  when no seat remains, leaving the row `pending_approval` rather than
  silently overfilling. Two regression tests added
  (`tests/Feature/Batches/BatchEnrolmentServiceTest.php`): approving into an
  open seat still works, approving into a full batch throws and leaves the
  row untouched.

**Checked and deliberately left alone:**

- **`addManually()` also skips the seat-limit check.** Unlike `approve()`,
  this is the intended behaviour, not a gap — it's staff explicitly adding a
  named student to a specific batch (the design doc's §3.1 diagram marks this
  path "staff-only... not reachable from here"), the same override posture
  every other staff-only write path in this codebase gets. Left unguarded on
  purpose.
- **`ExpirePendingEnrolments` sweeps every tenant in one console pass with no
  `CurrentTenant` bound.** Confirmed against `BelongsToTenant`'s actual
  implementation (`app/Support/Tenancy/BelongsToTenant.php` — the global
  scope only adds a `where` clause when `CurrentTenant::isBound()`, otherwise
  it's a no-op), matching the job's own doc comment. Not a leak: nothing
  outside the console process reads the unscoped query, and it's the only
  way a daily scheduled sweep can touch every tenant without one job per
  tenant.
- **Cross-panel / cross-tenant URL access for the new `BatchResource`.**
  Covered for free by the S1 round-3 fix already in the shared role-gate
  middleware (session-vs-subdomain tenant check) — `BatchResource` adds no
  new panel and no new middleware path, so it inherits that fix rather than
  needing its own.
- **`BatchCatalog::join()` and `render()` cross-tenant leak.** `Batch` and
  `Enrolment` both carry `BelongsToTenant`; `/batches` sits inside the same
  `resolve.tenant` (strict) route group as `/dashboard`. Already
  test-covered in B7's `BatchCatalogTest`, re-verified by reading the
  component fresh — no change.
- **`BatchSessionsRelationManager`'s create-time tie-break.** Same known gap
  already documented inline (`reorderable()` never assigns `position` on
  create, so unordered rows tie-break by DB order) as
  `SectionsRelationManager`/`LessonsRelationManager` carry from S1/S2 — not
  new to S4, not re-fixed here, consistent with those being left alone too.
  **Correction (second review round, below): this characterization was
  wrong.** `BatchSessionsRelationManager`'s `CreateAction` already assigns
  `position = max + 1` via `mutateFormDataUsing`, same as the other two
  managers — there is no live create-time gap in any of the three files,
  and `BatchSessionTest` (B2) already asserts sequential positions `[1, 2]`.
  Re-read this bullet fresh in the second round rather than trusting the
  first round's own claim.

**What's not real yet, on purpose:** same three items the design doc's own
§2.4 already scoped out — F7 (timetable templates/conflict detection),
`attendance_records`' marking UI, and real payment collection for paid
batches (S7). No change from what B0-B7 already documented.

---

## Post-S4 review, round 2

Independent fresh pass (no assumption round 1 caught everything): re-read
every file in scope plus `AttendanceRecord`, the migrations, routes,
`bootstrap/app.php`, and `TenantModelRegistry`, then cross-checked this plan
doc and the design doc against the actual code. `composer test` before this
round: 201/202 (same pre-existing `CourseSlugUniquenessTest` failure).

**Found and fixed 5 real bugs:**

1. **`BatchEnrolmentService::enrol()` never enforced the "batch must be
   published, with a published course" rule `canEnrol()` advertises.**
   `BatchCatalog::join()` (`app/Domains/Batches/Livewire/BatchCatalog.php`)
   is a directly-callable Livewire method — `/batches` has no role gate, only
   the strict tenant-resolution group — and it calls `enrol()` straight
   through with a client-supplied `$batchId`. `render()`'s display query
   filters to published batches with a published course, but `enrol()`
   itself never re-checked that, so a forged `join($draftBatchId)` call
   landed an `active`/`waitlisted`/`pending_approval` row on a batch that was
   never open for enrolment. Fixed: `enrol()` now throws unless
   `isOpenForEnrolment()` (the same predicate `canEnrol()` already used) is
   true. Tests added: `BatchEnrolmentServiceTest` (2 new — draft batch,
   unpublished course), `BatchCatalogTest` (1 new — forced `join()` call on a
   draft batch throws and creates nothing).
2. **`BatchEnrolmentService::cancel()` mutated seat state with no lock.**
   `enrolDirect()` and `approve()` both take `Batch::whereKey(...)
   ->lockForUpdate()` inside a `DB::transaction` before touching active
   count; `cancel()`'s active branch — which fires `SeatReleased` and calls
   `promoteNextWaitlisted()`, itself a seat-count mutation — took no lock at
   all. Two concurrent cancellations, or a cancellation racing a direct join
   for the seat it just freed, could double-promote one waitlisted student
   while starving the next in line, or push active count past `seat_limit`.
   Fixed: `cancel()`'s active branch now takes the same `lockForUpdate()`
   transaction. (Non-active cancellations — `waitlisted`/`pending_approval`
   — never freed a seat and are unchanged, still lock-free by design.)
3. **`ExpirePendingEnrolments::handle()` had a TOCTOU race against a
   concurrent `approve()`/`reject()`.** It loads every past-due
   `pending_approval` row in one `SELECT`, then calls `$enrolment->update([
   'status' => 'expired'])` on each — an unconditional write by primary key
   with no re-check of `status`. If staff approved a request in the window
   between that `SELECT` and the sweep reaching that specific row, the sweep
   silently overwrote the approval back to `expired`. Fixed: each row is now
   re-fetched with `lockForUpdate()` and a `where('status', 'pending_approval')`
   guard immediately before writing; a row that changed status in that
   window is skipped, not clobbered. Regression test in
   `ExpirePendingEnrolmentsTest` uses `DB::listen()` on the sweep's own
   `SELECT` to inject a raced concurrent approval at exactly that point,
   deterministically (no real threads needed).
4. **Waitlist FIFO order and the position shown to students had no
   deterministic tiebreak, and the tie is real.** `enrolments.created_at` is
   `timestamp(0)` in this app's Postgres schema — Laravel's default column
   precision is 0 and nothing in this codebase overrides it (verified via
   `information_schema.columns`, `datetime_precision = 0` on every timestamp
   column). Two students joining within the same wall-clock second — plainly
   possible for "ten students, one batch" — get an identical `created_at`,
   so `promoteNextWaitlisted()`'s `orderBy('created_at')` and
   `BatchCatalog::waitlistPosition()`'s `created_at <` comparison both had no
   defined order for the tie. (Confirms the existing FIFO test needed
   `travelTo(now()->addSecond())` between joins specifically to dodge this —
   real evidence the tie exists, not a hypothetical.) Fixed: both now break
   the tie on `id` (strictly increasing at insert, so it actually preserves
   arrival order). Regression tests: `BatchEnrolmentServiceTest` (forces a
   tied `created_at` via `forceFill`, confirms the earlier-inserted row is
   promoted), `BatchCatalogTest` (same tie, confirms the two students see
   different positions, not both "position 1").
5. **`EnrolmentsRelationManager`'s "Add student" action skipped
   `EnrolmentApprovalPolicy` entirely.** Approve/reject/cancel all call
   `authorizedActor()` first; the manual-add `CreateAction`'s `using()`
   closure passed `auth()->user()` straight through with no policy check.
   Not currently exploitable — `/admin`'s `authMiddleware` already restricts
   the whole panel to `institute_admin` in the actor's own tenant
   (`EnsureRole::class.':institute_admin'`), so nothing bypasses it today —
   but it broke the "every enrolment-mutating action goes through the
   policy" invariant the other three actions hold, the same standard B4's
   own deviation note applied to justify guarding `cancel`. Fixed:
   manual-add now calls `authorizedActor()` too. Regression test in
   `EnrolmentsRelationManagerTest`: an other-tenant instructor's manual-add
   attempt throws `EnrolmentApprovalDeniedException`.

**Checked and deliberately left alone (round 2):**

- **`BatchCatalog::render()`'s per-row `Enrolment::where(...)->first()`
  query.** N+1 (one query per displayed batch for the viewer's own
  enrolment) — a real performance nit, not a correctness bug; the catalog
  still returns the right rows. Left alone per this pass's scope (fix
  correctness/security bugs, not performance).
- **`SendEnrolmentExpiredNotification`'s `$enrolment?->user?->notify(...)`
  null-safe chain.** Silently no-ops if the enrolment or its user is gone by
  the time the listener runs — a deliberate defensive default matching how
  the rest of this listener already reads, not a swallowed failure; an
  actual notification *send* failure (mail transport, etc.) is not caught
  anywhere and still propagates and fails the job normally.
- **`addManually()` still has no seat-limit check.** Confirmed still
  intentional (staff override), unchanged from round 1's finding.
- **`ExpirePendingEnrolments`' tenant-unscoped sweep.** Re-confirmed against
  `BelongsToTenant`'s actual global-scope implementation — a no-op when
  `CurrentTenant` is unbound, and every write in the job is by primary key
  (now additionally guarded by the round-2 status re-check), so there's no
  cross-tenant write path. Unchanged from round 1's finding.
- **Migrations' `DB::statement()` calls for the two partial unique
  indexes.** CLAUDE.md's `DB::raw`/`DB::statement`/`whereRaw` ban is scoped
  to `app/Domains/`; these are in `database/migrations/`, same established
  pattern the courses-slug fix used. Not a violation.
- **All four models' `$fillable` arrays** — checked against every `create()`/
  `update()` call site in the domain; each matches exactly, no silently-
  dropped or over-permissive field.
- **Fresh `TenantModelRegistry` / isolation suite check** — all four models
  still registered, `composer test`'s isolation suite still green.

**Test suite after round 2: 208/209** (7 new tests, all passing; same 1
pre-existing `CourseSlugUniquenessTest` failure, still S1/S2 territory, still
out of scope).
