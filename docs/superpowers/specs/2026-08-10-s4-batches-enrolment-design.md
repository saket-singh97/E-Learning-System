# Design: S4 Batches + Enrolment — AI-Native LMS

Date: 2026-08-10
Status: APPROVED
Scope: Sub-project 4 of 8 in the P1 build (Institute Zero)
Window: 2026-08-10 → 2026-10-03 (demo #4 date)
Depends on: S1 Foundation (tenancy, roles, `Course`), S2 Content + Video (`Course.status`)
Feeds: S5 Live Classes (`batch_sessions`, `attendance_records`), S6 Assessments (enrolment gating), S7 Fees (paid-batch checkout, auto-enrol-on-payment), S8 Comms (enrolment/approval notifications)

---

## 1. Why this document exists

`docs/FEATURES.md` §F (`Batches & Enrolment`) lists ten features, F1–F5 tagged
P1 and F6–F10 tagged P2. The roadmap (`docs/PROJECT-STATUS.md`, S1 design doc
§2.2) commits S4 to one demo: **one batch, ten students enrolled**, on
2026-10-03. This document turns that one-line commitment into a schema, a
service contract, and a block-by-block build order, the same way the S1, S2,
and S3 design docs did for their slices.

It also records three scope decisions made before any code exists (§2.4),
so the block plan (companion document, `2026-08-10-s4-batches-enrolment-plan.md`)
isn't guessing at boundaries mid-build the way earlier slices sometimes had to.

---

## 2. S4 scope

### 2.1 In scope (P1 — required for the 2026-10-03 demo)

| Feature | FEATURES.md ref | What it means concretely |
|---|---|---|
| Batch CRUD | F1 | Dates, seat limit, price, paid/free flag, self-enrol mode — one batch wraps one `Course` |
| Three enrolment modes | F2 | Manual add (staff enrols a student directly), self-enrol with approval (student requests, staff approves/rejects), direct self-enrol (student joins instantly if a seat is open) |
| Waitlist | F3 | FIFO queue when a batch is full; auto-promotion to `active` the moment a seat is released |
| Approval expiry | F4 | A `pending_approval` enrolment auto-expires after 7 days if nobody acts on it, with a notification to the requester |
| Session scheduling | F5 | Batches have zero or more scheduled sessions, 15–480 minutes each — the calendar skeleton S5 (Live Classes) will anchor real sessions to |

### 2.2 Out of scope, and where each goes

| Feature | FEATURES.md ref | Why deferred | Lands in |
|---|---|---|---|
| Timetable templates + teacher conflict detection | F7 | Tagged P2. "Timetable" in the roadmap's one-line demo description maps to F5 (session scheduling), not F7's templating/conflict engine — confirmed 2026-08-10, same "defer the P2 half" move S2 made with Bunny/HLS and S3 made with the real AI vendor | S4 fast-follow or S5 |
| Batch-scoped discussion forum | F6 | P2, no dependency on anything S4 builds | Fast-follow |
| Batch feedback collection | F8 | P2 | Fast-follow |
| Bulk student CSV import | F9 | P2, ≤5000 rows with per-row error report — real scope on its own | Fast-follow |
| Bulk enrolment + bulk invitations as background jobs | F10 | P2, depends on F9 | Fast-follow |
| Real payment collection for paid batches | S7 scope | `batches.price` and `is_paid` are stored now; Razorpay checkout, instalments, and "auto-enrol on payment" (FEATURES.md P5) are S7's whole sub-project | S7 |
| Attendance marking UI | S5-adjacent | See §2.4 decision 2 — schema only in S4 | S5 |
| Multi-branch organisation layer above batches | FEATURES.md A9 | Not needed for one institute's demo | Later, unscheduled |

### 2.3 Reviewed before writing this: what S1–S3 already promised toward S4

- **`Course.status`** (S2 B0) already gives batches a `published` course to
  wrap — a batch cannot be created against a `draft` course (enforced in
  `BatchEnrolmentService`, §3.4).
- **`attendance_records`** was named in the S1 design doc's dependency graph
  (§2.1) as a table with three writers — S4 (manual), S2 (async video
  coverage), S5 (live webhook) — "designed for three writers from the start."
  S2 shipped without creating it (verified: no `attendance_records` migration
  exists as of this writing). S4 is therefore the first sub-project to touch
  this table; see decision 2 below for how much of it S4 actually builds.
- **No student-facing course/batch catalog page exists yet.** S2's
  post-implementation review noted this explicitly ("that's S3/S4 territory").
  S3 didn't touch it. S4 closes this gap (§2.4 decision 3, §3.1).
- **Nothing in S1–S3's contracts needs to change.** `lesson_artifacts`
  (S1 §5, S3 §3.4) and the tenancy/role model are read-only from S4's
  perspective — S4 adds new tables and one new domain, it does not modify
  existing ones beyond the FK reference in decision 2.

### 2.4 Decided (2026-08-10, before Block 0)

Three scope questions were resolved before writing the block plan, so the
plan isn't ambiguous about boundaries:

1. **Core scope is F1–F5 only, F7 deferred.** (See §2.2.) Rationale: F7 is
   explicitly P2 in FEATURES.md, and every prior slice (S2, S3) deferred its
   P2 half to a fast-follow rather than let scope creep push the demo date.
2. **`attendance_records` gets its schema pinned in S4, not its marking UI.**
   S4 ships the migration (polymorphic `attendable_type`/`attendable_id` so
   both a `BatchSession` (S4/S5) and a `Lesson` (S2-style async video
   coverage) can be the attended thing, a `source` enum recording which of
   the three writers wrote the row) but no instructor-facing "mark
   present/absent" screen. Rationale: mirrors exactly how S1 B10 pinned
   `lesson_artifacts` a whole sub-project before anything read from it, and
   the 2026-10-03 demo script ("one batch, ten students enrolled") never
   mentions attendance — building a UI for it now would be scope the demo
   doesn't need and S5 doesn't need yet either, since S5 is what actually
   marks attendance against live sessions.
3. **The student-facing batch catalog is full scope, not a stub.** A student
   sees a list of open batches (seats remaining, price, self-enrol mode) and
   can request/join one from that page — the real F2 experience, not a
   direct-link-only placeholder. Rationale: explicit owner call, and a
   demo that claims "ten students enrolled" is more credible when the
   enrolment path is the actual student-facing flow rather than an admin
   manually seeding rows.

---

## 3. Architecture

### 3.0 Where this lands in the existing domain layout

New domain: `app/Domains/Batches/`, same subfolder shape every other domain
uses (`Models/`, `Services/`, `Filament/`, `Livewire/`, `Views/`, `Jobs/`,
`Policies/`, `Events/`). View namespace registration is automatic — nothing
manual needed beyond creating the folder (`AppServiceProvider::registerDomainViewNamespaces()`
globs `app/Domains/*` at boot).

Batches talks to Content (`Course`) by direct model reference for the one
FK it needs (`batches.course_id`) — reading another domain's model by FK is
not the same as calling into its services, and every existing domain already
does this (e.g. `CourseSection belongsTo Course`). Anything Batches needs to
*tell* another domain (S5, S6, S7, S8, all future) goes through
`Events/`, per the CLAUDE.md domain-boundary rule.

```
app/Domains/Batches/
  Models/       Batch, BatchSession, Enrolment
  Services/     BatchEnrolmentService   (the one class that answers
                                          "can this student enrol, and what
                                          happens when they do" — same
                                          pattern as Content's LessonAccess)
  Filament/     Resources/Batches/BatchResource.php (+ Pages/, RelationManagers/)
  Livewire/     BatchCatalog.php  (student-facing browse + self-enrol)
  Views/        batch-catalog.blade.php
  Jobs/         ExpirePendingEnrolments.php
  Policies/     EnrolmentApprovalPolicy.php (who may approve/reject a pending enrolment)
  Events/       StudentEnrolled, SeatReleased, EnrolmentApprovalExpired
```

`AttendanceRecord` is **not** in `Batches/Models/` — it's cross-cutting
(three domains write to it across S4/S2/S5), so it lives in
`app/Domains/Content/Models/AttendanceRecord.php` alongside the other
cross-cutting Content-domain tables (`BandwidthUsageDaily` lives there for
the same reason: it's a shared ledger, not Content-specific business logic).
S4 only ships its migration and model — no service reads or writes it yet
beyond the migration existing.

### 3.1 Data flow — enrolment (three modes, F2)

```
Student on /batches (BatchCatalog Livewire component)
        │
        ▼
  clicks "Join" on a published batch
        │
        ▼
BatchEnrolmentService::enrol(User $student, Batch $batch)
        │
        ├─ manual_add mode ──────► not reachable from here — staff-only,
        │                          via BatchResource's EnrolmentsRelationManager
        │                          "Add student" action → status=active immediately
        │
        ├─ self_enrol_direct mode
        │     │
        │     ▼
        │   DB transaction, lockForUpdate() on the batch row:
        │     seats_taken = active enrolment count
        │     if seats_taken < seat_limit (or seat_limit is null):
        │         create Enrolment{status: active, source: self_enrol_direct}
        │     else:
        │         create Enrolment{status: waitlisted, source: self_enrol_direct}
        │     fire StudentEnrolled (if active) or nothing (if waitlisted —
        │       nothing to tell other domains about yet)
        │
        └─ self_enrol_approval mode
              │
              ▼
            create Enrolment{status: pending_approval,
                              source: self_enrol_approval,
                              requested_at: now(),
                              expires_at: now()->addDays(7)}
              │
              ▼
            staff sees it in BatchResource's EnrolmentsRelationManager,
            approves → status=active, fires StudentEnrolled
                     → rejects → status=rejected, terminal
```

The `lockForUpdate()` transaction exists because two students racing for the
last seat is a real concurrency case the 10-student demo will actually
exercise if run live — same class of race the S2 review found and fixed in
`BandwidthUsageDaily::record()` (S2 round-2 review), handled here at the
source instead of discovered after the fact.

### 3.2 Data flow — waitlist promotion (F3)

Promotion is triggered by anything that reduces `active` enrolment count for
a batch: an instructor cancelling a student's enrolment, or (later, S7) a
failed-payment cancellation. Kept inside the Batches domain — no cross-domain
event needed to trigger it, since both the trigger and the promotion live in
`BatchEnrolmentService`:

```
BatchEnrolmentService::cancel(Enrolment $enrolment)
        │
        ▼
  $enrolment->update(['status' => 'cancelled'])
        │
        ▼
  promoteNextWaitlisted($enrolment->batch)
        │
        ▼
  oldest Enrolment where batch_id = X, status = waitlisted, order by created_at asc
        │
        ├─ none found → done
        └─ found → status = active, fires StudentEnrolled
```

FIFO is `created_at` ordering within `status = waitlisted` — no separate
`waitlist_position` column to keep in sync, one less place for drift.

### 3.3 Data flow — approval expiry (F4)

First use of Laravel's task scheduler in this codebase (verified: no
`withSchedule()` closure exists in `bootstrap/app.php` today). Added as part
of Block 5:

```
bootstrap/app.php: ->withSchedule(fn (Schedule $schedule) =>
    $schedule->job(new ExpirePendingEnrolments)->daily())

ExpirePendingEnrolments (app/Domains/Batches/Jobs/)
        │
        ▼
  Enrolment::where('status', 'pending_approval')
            ->where('expires_at', '<=', now())
            ->each(function ($enrolment) {
                $enrolment->update(['status' => 'expired']);
                EnrolmentApprovalExpired::dispatch($enrolment);   // event, not
                                                                    // a notification
                                                                    // send directly
            })
```

`EnrolmentApprovalExpired` is a domain event, not a direct notification send
— S4 fires it and a first, minimal listener inside the Batches domain sends
the actual notification (first `Notification` class in this codebase;
verified none exist yet). Firing an event here rather than notifying inline
keeps the door open for S8 (Comms) to add a WhatsApp-channel listener later
without touching `ExpirePendingEnrolments`.

### 3.4 `BatchEnrolmentService` contract

The one class that answers "can this student enrol, and what happens when
they do" — same centralising principle CLAUDE.md documents for
`LessonAccess` ("the stream route, the player page, and the watch heartbeat
all call it... add new visibility rules there, never inline in a
controller"). `BatchCatalog` (Livewire), `BatchResource`'s relation manager
(Filament), and the approval-expiry job all call this service; none of them
re-implement seat counting or status transitions themselves.

```php
class BatchEnrolmentService
{
    public function enrol(User $student, Batch $batch): Enrolment;
    public function approve(Enrolment $enrolment, User $actor): Enrolment;
    public function reject(Enrolment $enrolment, User $actor): Enrolment;
    public function cancel(Enrolment $enrolment): Enrolment;
    public function seatsRemaining(Batch $batch): ?int;   // null = unlimited
    public function canEnrol(User $student, Batch $batch): bool;   // published,
                                                                     // not already
                                                                     // enrolled,
                                                                     // course published
}
```

`EnrolmentApprovalPolicy` (separate, `Policies/`) answers only "may $actor
approve/reject enrolments for $batch" — an instructor assigned to the
batch's course, or an institute admin. Kept separate from
`BatchEnrolmentService` because it's an authorization question, not a
state-transition question, matching the existing split between
`RoleAssignmentPolicy` (who may assign) and the plain state changes
elsewhere.

### 3.5 Schema

```sql
CREATE TABLE batches (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       BIGINT NOT NULL REFERENCES tenants(id),
    course_id       BIGINT NOT NULL REFERENCES courses(id),
    title           VARCHAR(255) NOT NULL,
    slug            VARCHAR(255) NOT NULL,
    description     TEXT NULL,
    starts_on       DATE NOT NULL,
    ends_on         DATE NOT NULL,
    seat_limit      INTEGER NULL,              -- null = unlimited
    price           DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_paid         BOOLEAN NOT NULL DEFAULT FALSE,
    self_enrol_mode VARCHAR(30) NOT NULL DEFAULT 'manual_only',
                    -- manual_only | self_enrol_approval | self_enrol_direct
    status          VARCHAR(20) NOT NULL DEFAULT 'draft',
                    -- draft | published | archived  (mirrors courses.status)
    deleted_at      TIMESTAMP NULL,             -- soft delete, per FEATURES.md V3
    created_at      TIMESTAMP NOT NULL,
    updated_at      TIMESTAMP NOT NULL
);
CREATE UNIQUE INDEX batches_tenant_slug_unique
    ON batches (tenant_id, slug) WHERE deleted_at IS NULL;   -- same pattern as
                                                               -- courses' slug fix
                                                               -- (S1 round-1 review)

CREATE TABLE batch_sessions (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       BIGINT NOT NULL REFERENCES tenants(id),
    batch_id        BIGINT NOT NULL REFERENCES batches(id),
    title           VARCHAR(255) NULL,
    starts_at       TIMESTAMP NOT NULL,
    duration_minutes INTEGER NOT NULL,          -- CHECK 15-480, validated in
                                                  -- the Filament form (F5)
    position        INTEGER NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL,
    updated_at      TIMESTAMP NOT NULL
);

CREATE TABLE enrolments (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       BIGINT NOT NULL REFERENCES tenants(id),
    batch_id        BIGINT NOT NULL REFERENCES batches(id),
    user_id         BIGINT NOT NULL REFERENCES users(id),
    status          VARCHAR(20) NOT NULL,
                    -- pending_approval | active | waitlisted | rejected |
                    -- expired | cancelled
    source          VARCHAR(30) NOT NULL,
                    -- manual_add | self_enrol_approval | self_enrol_direct
    requested_at    TIMESTAMP NOT NULL,
    decided_at      TIMESTAMP NULL,
    decided_by      BIGINT NULL REFERENCES users(id),
    expires_at      TIMESTAMP NULL,             -- only set for pending_approval
    deleted_at      TIMESTAMP NULL,              -- soft delete, per FEATURES.md V3
    created_at      TIMESTAMP NOT NULL,
    updated_at      TIMESTAMP NOT NULL
);
CREATE UNIQUE INDEX enrolments_tenant_batch_user_unique
    ON enrolments (tenant_id, batch_id, user_id) WHERE deleted_at IS NULL;

-- Schema pinned now (decision 2, §2.4), no S4 writer yet:
CREATE TABLE attendance_records (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       BIGINT NOT NULL REFERENCES tenants(id),
    attendable_type VARCHAR(255) NOT NULL,       -- 'batch_session' | 'lesson'
    attendable_id   BIGINT NOT NULL,
    user_id         BIGINT NOT NULL REFERENCES users(id),
    status          VARCHAR(20) NOT NULL,        -- present | absent | late | excused
    source          VARCHAR(20) NOT NULL,        -- manual | video | live_webhook
    marked_by       BIGINT NULL REFERENCES users(id),
    recorded_at     TIMESTAMP NOT NULL,
    created_at      TIMESTAMP NOT NULL,
    updated_at      TIMESTAMP NOT NULL
);
CREATE INDEX attendance_records_attendable_idx
    ON attendance_records (attendable_type, attendable_id);
```

All four tables use `BelongsToTenant` and must be added to
`TenantModelRegistry::MODELS` (Block 8) — the isolation suite is generated
from that array, not hand-written, per CLAUDE.md.

---

## 4. Error handling

| Failure mode | Handling |
|---|---|
| Two students race for the last seat | `lockForUpdate()` transaction in `BatchEnrolmentService::enrol()` (§3.1) — the loser gets `waitlisted`, not a 500 or a double-booked seat |
| Student tries to enrol in a `draft` or `archived` batch | `canEnrol()` returns false before any row is written; Livewire shows "not open for enrolment" |
| Student tries to enrol twice in the same batch | Unique index on `(tenant_id, batch_id, user_id) WHERE deleted_at IS NULL` — second attempt fails fast, service catches the constraint and returns the existing enrolment's status instead of a raw DB error |
| Approval expiry job finds zero pending rows | No-op, not an error — logged at debug level only |
| Instructor tries to approve an enrolment for a batch they're not assigned to | `EnrolmentApprovalPolicy` denies before `BatchEnrolmentService::approve()` runs, mirrors `PrivilegeEscalationException` pattern from `RoleAssignmentPolicy` |
| A batch's `course_id` points at a soft-deleted course | Batch creation/edit form excludes soft-deleted courses from the picker (same `withoutTrashed()` default every other course-referencing form already uses) |

---

## 5. Testing

### 5.1 The isolation suite

`Batch`, `BatchSession`, `Enrolment`, and `AttendanceRecord` each get a
`TenantModelRegistry` entry (`seedAttributes()` + `updateAttributes()`) in
Block 8 — same generated cross-tenant read/update/no-context-guard coverage
every other tenant-owned model gets, per CLAUDE.md's tenant model registry
rule. Four new models means four new rows in the generated suite, not four
new hand-written test files.

### 5.2 Other S4 tests

| Layer | What | Roughly |
|---|---|---|
| Unit | `BatchEnrolmentService`: all three enrol modes, seat-race via two concurrent calls in a transaction test, waitlist promotion FIFO order, approval expiry boundary (exactly 7 days) | ~10 |
| Unit | `EnrolmentApprovalPolicy`: assigned instructor allowed, unassigned instructor denied, institute admin always allowed | ~3 |
| Feature | `BatchResource` CRUD, `EnrolmentsRelationManager` manual-add/approve/reject actions | ~6 |
| Feature | `BatchCatalog` Livewire: lists only published batches for the viewer's tenant, join button respects `self_enrol_mode`, seats-remaining display | ~5 |
| Feature | `ExpirePendingEnrolments` job: expires exactly the rows past `expires_at`, leaves others untouched | ~2 |
| Isolation | Generated (see §5.1) | +4 models × existing per-model assertion count |

---

## 6. Demo #4 — 2026-10-03

1. Institute admin creates a batch against a published course: dates, seat
   limit of 10, free, `self_enrol_direct`.
2. Ten students visit `/batches`, see it listed with "10 seats open," each
   clicks Join — all ten land `active`.
3. An eleventh student joins — lands `waitlisted`, sees their position.
4. Instructor cancels one of the ten active enrolments — the waitlisted
   student is auto-promoted to `active` within the same request.
5. Instructor adds a session to the batch (title, start time, 60 minutes) —
   visible on the batch's session list.

---

## 7. Definition of done for S4

- [ ] F1–F5 shipped and demoable per §6's script
- [ ] `attendance_records` migration exists, no writer yet (decision 2)
- [ ] All four new models registered in `TenantModelRegistry`, isolation
      suite green
- [ ] `BatchEnrolmentService` is the only place enrol/approve/reject/cancel
      logic lives — no inline duplication in the Filament resource or the
      Livewire component
- [ ] `composer test` green, zero regressions in S1/S2/S3's existing suites
- [ ] Post-S4 bug-hunting review pass completed and documented (same
      practice as S1 ×2, S2 ×1, S3 ×2 rounds), before S4 is marked complete
      in `PROJECT-STATUS.md`

---

## 8. Documents this spec makes stale

- `docs/PROJECT-STATUS.md` — roadmap table's S4 row moves from "not started"
  to "planned" on this document's approval, and to "complete" once the plan
  doc's blocks are all done.
