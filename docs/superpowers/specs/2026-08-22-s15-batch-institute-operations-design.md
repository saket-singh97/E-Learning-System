# Design: S15 Batch & Institute Operations

## 1. Why this document exists

Locks scope and architecture for S15 before any block is built, same role
S9-S14's own design docs played. Full block-by-block build log lives in the
companion `2026-08-22-s15-batch-institute-operations-plan.md`.

S15 is the seventh P2-backlog slice (after S9 hardening, S10 assignments/
gradebook/certificates, S11 billing, S12 security/resilience, S13 discovery/
engagement, S14 video/live-class depth). Scope per `docs/ROADMAP.md`'s own
S15 row: `docs/FEATURES.md` §F (F6-F10) + §S (S2-S7) — **11 roadmap items as
counted there, but S7 (job board/placement module, P3, FRP-tagged) is cut**
(§2.2) — not named in `docs/ROADMAP.md`'s own one-line S15 scope description
("discussion forums, timetable conflict detection, bulk CSV import,
per-instructor permissions, admissions CRM, ID cards"), same "P3 item absent
from the headline description gets cut" precedent S14 established for
D10-D12. Real build scope is **10 items**, one of which (S5, ID cards) stays
in scope despite being P3 because the roadmap's own headline explicitly
names it and it needs no paid vendor relationship (unlike S14's cut items).

## 2. Scope

### 2.1 In scope — 10 items

| # | Item | Source | Phase |
|---|---|---|---|
| F6 | Batch-scoped discussion forum (3-level nesting) | REQ-9 | P2 |
| F7 | Timetable templates + teacher conflict detection | FRP, NEW | P2 |
| F8 | Batch feedback collection | FRP | P2 |
| F9 | Bulk student CSV import (≤5000 rows) with per-row error report | REQ-47 | P2 |
| F10 | Bulk enrolment + bulk invitations as background jobs | REQ-47 | P2 |
| S2 | Per-instructor permission sets | REQ-46 | P2 |
| S3 | Reports: enrolment, attendance, gradebook, revenue, engagement, completion — CSV/Excel/PDF as jobs | REQ-56 | P2 |
| S4 | Enquiry → follow-up → admission CRM | MKT, NEW | P2 |
| S5 | ID card generation | NEW, MKT | P3 |
| S6 | Noticeboard | INF | P2 |

(`docs/FEATURES.md` §S numbering collides with this project's own slice
numbering — S2-S6 above are §S *feature rows*, not slices S2-S6, which
already shipped months ago. Every reference to a feature row below is
written `§S.S2` etc. to keep the two apart.)

### 2.2 Out of scope, and why (locked before any code, not discovered mid-build)

- **§S.S7 (job board / placement module) is cut.** P3-tier, `FRP`-tagged
  (feature request pattern, not a locked requirement), and — unlike ID
  cards — genuinely a separate product surface (employer-facing listings,
  applications, a whole second audience this platform has never served).
  Not named in `docs/ROADMAP.md`'s own S15 headline. Revisit once a real
  institute asks for it.
- **S3's "CSV/Excel/PDF" is not read as "all three formats for all six
  report types."** §2.3 below found gradebook already has all three
  formats (S10) and attendance/revenue/engagement already have CSV (S8/S9)
  — this slice's real gap is the two report types with **no export at
  all** (enrolment, completion), built in the same CSV-first shape the
  other three already established. Adding Excel/PDF variants to the three
  already-CSV reports is real but lower-value work with an existing
  fallback (CSV) — cut from this slice, flagged as a fast-follow, not
  silently dropped.
- **§S.S2's "per-instructor permission sets" stays coarse: one primary
  instructor per `Batch`, not a many-to-many co-teaching model.** F7's own
  conflict detection and §S.S2's own scoping both only need "which
  instructor teaches this batch," answerable with a single nullable FK.
  Multiple instructors per batch (TA/co-teacher scenarios) is a real
  future need with zero current demand signal — same "don't build for a
  hypothetical" posture `RecommendationService` (S13) already took.
- **Bulk CSV import (F9) is students only**, matching the roadmap item's
  own wording ("bulk student CSV import"). Bulk course/batch import is a
  different, larger data-modeling problem (§2.3's V14 backlog row,
  P2-but-unscheduled) explicitly deferred to whenever that item's own turn
  comes.
- **The admission CRM (§S.S4) stops at "convert to enrolment."** It does
  not touch marketing attribution, ad-spend tracking, or lead-source
  analytics (`docs/FEATURES.md` §U's growth items) — this slice builds the
  operational pipeline an admissions counselor works from, not a
  marketing dashboard.

### 2.3 Verified before writing this design (grepped/read, not assumed)

- **No discussion forum, timetable, feedback, bulk-import, enquiry/lead,
  ID-card, or noticeboard concept exists anywhere in `app/`** — each
  grepped individually (`Forum|Thread|Discussion`, `Timetable|conflict`,
  `Feedback`, `Import|Csv`, `Enquiry|Lead|Admission`, `IdCard`,
  `Notice|Announcement`), all fully greenfield. `LessonQuestions` (Content)
  and `DirectMessages` (Comms) are adjacent-but-distinct existing
  threaded features a forum design must not collide with; `NotificationCentre`
  (S8 B7) is confirmed a strictly per-user database-notifications feed,
  not an institute-wide noticeboard.
- **`maatwebsite/excel` (`composer.json`, already a dependency since S10)
  has only ever been used for export in this codebase** — `Excel::import()`
  is never called anywhere, `WithValidation`/`ToModel`/`Importable` are
  never implemented. F9 is this app's first real import, not a reuse of
  an existing pattern.
- **No instructor↔course/batch assignment exists anywhere, at all.**
  Grepped `app/Domains/Batches` and `app/Domains/Content` for
  `instructor_id|primary_instructor|teacher_id`: zero matches on `Batch`,
  `BatchSession`, or `Course`. Every `instructor`-role user currently has
  blanket tenant-wide access (Spatie roles are global, not tenant- or
  resource-scoped — confirmed in `RoleAssignmentPolicy.php`'s own
  docblock: "User deliberately has no BelongsToTenant scope"), and
  `TeachPanelProvider`'s directory-wide resource discovery (the S13 B17
  gap fixed at the *resource-list* level) still shows every instructor
  every batch's *data* once inside a shared resource — nothing scopes the
  underlying query. **This is a real, load-bearing gap two of this
  slice's own items (F7, §S.S2) cannot be built without closing first.**
- **`docs/FEATURES.md`'s own §S.S1 row ("Instructor invitations, 14-day
  link, assignment to courses/batches, deactivate/reactivate") was never
  actually built**, despite being P1-tier and `docs/ROADMAP.md` marking
  S1 "Foundation" done. Grepped `app/` for `invit` (case-insensitive): the
  only real hits are `ParentInviteCode`/`ParentInviteCodeService`/
  `ParentInviteRedemption` (S9 B5) — a different, already-shipped feature
  for parent-guardian linking. No `Instructor*` files exist anywhere; no
  Admin-panel user-management resource exists for instructors (14
  existing Admin pages enumerated, none is one).
  `RoleAssignmentPolicy::assign()` exists and is generic, but its own
  docblock admits nothing calls it with `'instructor'` outside tests yet.
  **F10's "bulk invitations" has no single-instructor-invitation path to
  bulk-ify — this slice must build the single path first**, same
  "verify before building on a stale claim" discipline that caught S9
  B6's wrong "reusing already-fillable columns" premise and S9 B8's
  wrong "Idempotency-Key header" premise.
- **`BatchEnrolmentService`** (`app/Domains/Batches/Services/`) has a real
  single-enrolment precedent to extend for F10's "bulk enrolment":
  `enrol()` (self-service), `addManually()` (staff-added, calls
  `createEnrolment()`), `approve()`/`reject()`/`cancel()`, all synchronous
  with seat-race `lockForUpdate()` locking. No bulk/queued variant exists.
- **Existing report jobs, read in full**: gradebook has all three formats
  (`ExportGradebookCsv`/`Excel`/`Pdf`, Assignments domain, S10);
  attendance (`ExportAttendanceRegister`, Comms, S8 B6), revenue
  (`ExportRevenueRegister`, Comms, S9 B11), and engagement
  (`ExportEngagementRegister`, Comms, S9 B11) each have CSV only, all
  three sharing `App\Domains\Comms\Jobs\Concerns\EscapesCsvFormulas`
  (the CWE-1236 CSV-formula-injection guard extracted in S9 B11) — any
  new export job in this slice reuses that trait, not a new one. No
  enrolment or completion export job exists anywhere (`Export(Enrolment|Completion)(Register)?`
  greps, zero matches).
- **Roles are 5, not a commonly-assumed 4**: `RoleHierarchy::LEVELS`
  (`app/Domains/Tenancy/Services/RoleHierarchy.php`) = `super_admin(1),
  institute_admin(2), instructor(3), student(4), parent(5)`. `EnsureRole`
  checks role + tenant match only, no resource-level scoping anywhere in
  the app today (every existing Policy gates a specific *action*, never
  "which records can this actor touch").
- **`barryvdh/laravel-dompdf` and `endroid/qr-code`** (both installed in
  S10 for certificates) are still `composer.json` dependencies — ID cards
  (§S.S5) reuse both exactly as `Certificate` already does, no new
  packages.

## 3. Architecture

### 3.1 New domain: `Operations`

The admission CRM (§S.S4) is genuinely new business surface — pre-enrolment,
pre-`User` in most cases (a prospect isn't a tenant user until they convert),
and doesn't fit `Batches` (nothing to enrol into yet) or `Tenancy` (not a
role/branding/tenant-config concern). New `app/Domains/Operations/` domain,
same "a new domain lands when nothing existing fits" precedent `Billing`
(S11) and `Security` (S12) each established. Everything else in this slice
extends an existing domain:

- **`Tenancy`** — instructor invitation + assignment (closes §S.S1's real
  gap), per-instructor permission scoping (§S.S2).
- **`Batches`** — timetable templates + conflict detection (F7), batch
  feedback (F8, same "entity-owning domain, not Comms" precedent
  `CourseReview` set for course-scoped feedback in S13), bulk student CSV
  import (F9), bulk enrolment (F10 pt.1), ID cards (S5, issued per
  enrolment), enrolment/completion reports (S3).
- **`Comms`** — discussion forum (F6, adjacent to the existing
  `DirectMessage`/`MessageReport` shape), noticeboard (S6).
- **`Operations`** (new) — admission CRM (S4), bulk instructor invitations
  (F10 pt.2, since it dispatches through `Tenancy`'s new invitation model
  but the *bulk job* itself is an operations concern — same "Batches
  reads Content models, doesn't own them" cross-domain-model-read posture
  every prior slice already established).

### 3.2 New schema

```
-- Tenancy: closes §S.S1's real gap, foundation for F7/§S.S2/F10 pt.2
instructor_invitations (Tenancy — §S.S1 real gap, F10 pt.2)
  id, tenant_id, email, token (unique), invited_by_user_id,
  status (pending|accepted|expired|revoked), expires_at (14 days),
  accepted_user_id (nullable), timestamps

-- Batches: gains a nullable primary-instructor FK, not a new table
batches
  + instructor_id (nullable FK -> users, §2.2 decision — one primary
    instructor per batch)

-- Batches: F7
timetable_templates (Batches — F7 pt.1)
  id, tenant_id, title, day_of_week (0-6), starts_at (time),
  duration_minutes, timestamps
  -- a reusable weekly slot shape; BatchSession scheduling can start from
  -- one, still creates its own BatchSession row (no FK back to the
  -- template — same "template is a stamp, not a live link" posture
  -- nothing else in this app currently needs to contradict)

-- Comms: F6
forum_threads (Comms — F6 pt.1)
  id, tenant_id, batch_id, title, created_by_user_id, timestamps

forum_posts (Comms — F6 pt.1)
  id, tenant_id, forum_thread_id, parent_id (nullable, self-referential),
  depth (0|1|2 — enforced at write time, same bounded-depth posture
  lesson_prerequisites' own PrerequisiteGraph established for a
  different bounded-graph problem in S13), author_id, body, timestamps

-- Batches: F8
batch_feedback (Batches — F8 pt.1)
  id, tenant_id, batch_id, user_id, rating (1-5, DB CHECK, same
  CourseReview precedent), comment (nullable), timestamps
  -- unique on (batch_id, user_id) — one feedback row per student per
  -- batch, same CourseReview-per-course-per-learner shape

-- Batches: F9
student_import_batches (Batches — F9 pt.1)
  id, tenant_id, uploaded_by_user_id, status (pending|processing|done|failed),
  total_rows, success_count, error_count, error_report_disk_path (nullable),
  timestamps

-- Operations: S4
enquiries (Operations — S4 pt.1)
  id, tenant_id, name, email, phone (nullable), course_id (nullable,
  interest), status (new|contacted|follow_up|converted|lost),
  source (nullable), assigned_to_user_id (nullable), converted_enrolment_id
  (nullable), timestamps

enquiry_follow_ups (Operations — S4 pt.2)
  id, tenant_id, enquiry_id, user_id (who logged it), note,
  next_follow_up_at (nullable), timestamps

-- Batches: S5
id_cards (Batches — S5)
  id, tenant_id, enrolment_id, card_number (unique per tenant),
  disk_path, issued_at, timestamps

-- Comms: S6
notices (Comms — S6 pt.1)
  id, tenant_id, title, body, audience_roles (json — subset of the 5
  RoleHierarchy roles), published_at (nullable — null means draft),
  created_by_user_id, timestamps

notice_read_receipts (Comms — S6 pt.2)
  id, tenant_id, notice_id, user_id, read_at, timestamps
  -- unique on (notice_id, user_id)
```

### 3.3 New `BelongsToTenant` models (all registered in `TenantModelRegistry`)

`InstructorInvitation`, `TimetableTemplate`, `ForumThread`, `ForumPost`,
`BatchFeedback`, `StudentImportBatch`, `Enquiry`, `EnquiryFollowUp`,
`IdCard`, `Notice`, `NoticeReadReceipt` — **11 new models**, the widest
single-slice count yet (S13 had 12, but that included 2 pivots; this is 11
full models). All registered as each block builds them, not deferred to a
sweep-only block (same discipline S14 B0/B6/B8/B13 each already followed).

## 4. Design decisions

1. **One primary instructor per `Batch`, not a pivot.** §2.2 — F7's
   conflict check and §S.S2's own scoping both only need this; a
   many-to-many co-teaching model is real future scope with zero current
   demand, deferred rather than built speculatively.
2. **Instructor invitations reuse the *shape* of `ParentInviteCode` (S9
   B5) — a token + expiry + redemption row — but are a genuinely separate
   model, not an extension of it.** `ParentInviteCode` redemption links a
   parent to a specific student (`ParentGuardianLink`); an instructor
   invitation assigns courses/batches and a role. Different enough data
   shape that forcing one model to serve both would need nullable
   columns neither redemption path uses.
3. **Timetable templates are a stamp, not a live link.** Creating a
   `BatchSession` "from" a template copies `day_of_week`/`starts_at`/
   `duration_minutes` into the session's own row at creation time; no FK
   persists. Editing a template later never retroactively changes
   already-scheduled sessions — same "explicit snapshot, not implicit
   recompute" posture this app already prefers (e.g. `FeeInvoice` amounts
   are snapshotted at issue time, not recomputed from `Course` pricing on
   read).
4. **Conflict detection is a write-time check, not a background sweep.**
   Creating/editing a `BatchSession` for a batch with an assigned
   `instructor_id` checks every other `BatchSession` sharing that same
   instructor (across any batch) for an overlapping `[starts_at,
   starts_at + duration_minutes)` window and refuses the save if one
   exists — same "refuse before persisting" posture `PrerequisiteGraph`'s
   own cycle check (S13) already established for a different bounded
   check.
5. **Forum nesting depth is capped at 3 (thread → post → reply), enforced
   at write time by a stored `depth` column, not computed by walking
   `parent_id` on every read.** A reply to a depth-2 post is refused, not
   silently flattened — matches this app's existing "reject invalid state
   loudly" posture rather than a UI-only nesting limit that would leave a
   database row the UI itself can't render correctly.
6. **Bulk CSV import is two phases, not one job.** Phase 1 (synchronous,
   on upload) parses and validates every row, writing `student_import_batches`
   with `total_rows`/an in-memory error list — cheap enough to run inline
   for ≤5000 rows, and the user gets immediate feedback on file-shape
   errors (wrong headers, empty file) without waiting on a queue. Phase 2
   (a real `ShouldQueue` job) does the actual `User`/`Enrolment` creation
   per valid row, since the multi-tenant/role/enrolment writes are exactly
   the size of workload every other bulk-writer job in this app (`GdprExportRequest`,
   `ExportRevenueRegister`) already queues. The per-row error report is a
   downloadable CSV (`error_report_disk_path`), not a synchronous
   response — consistent with `≤5000 rows` being explicitly too large for
   one request/response cycle.
7. **Bulk enrolment (F10 pt.1) is a thin `ShouldQueue` wrapper around
   `BatchEnrolmentService::addManually()`, called once per row inside the
   job — not a new bulk-insert code path.** Reuses every existing
   guarantee (seat-limit `lockForUpdate()`, `EnrolmentApprovalPolicy`
   gate) for free; a naive bulk-insert would have to re-derive all of
   them and risk drifting from the single-enrolment path's own behavior.
8. **Bulk instructor invitations (F10 pt.2) is the same "thin queued
   wrapper" shape around the single-invitation path built in this
   slice's own foundational block**, not a separate bulk-specific
   invitation code path.
9. **Enquiry → conversion writes a real `Enrolment` through the existing
   `BatchEnrolmentService`, not a parallel enrolment path.** `converted_enrolment_id`
   on `Enquiry` is set from that call's own return value — an admissions
   counselor's "convert" action is enrolment staff-adding a student, not
   a new mechanism.
10. **ID cards are generated on demand (an Admin/instructor action per
    enrolment), not auto-issued on every enrolment.** Unlike `Certificate`
    (auto-issued by a daily sweep once completion criteria are met), an ID
    card has no completion criteria to wait for — issuing one is a
    deliberate institute action (a physical/printable artifact), so a
    manual trigger avoids generating (and re-generating on every
    `Enrolment` edit) cards nobody asked for yet.
11. **Notices target roles, not individual users.** `audience_roles` is a
    JSON subset of the 5 `RoleHierarchy` roles (e.g. `["student","parent"]`)
    — an institute-wide announcement is inherently a broadcast, not a
    per-recipient targeted message (`DirectMessage`'s own job); read-receipt
    tracking is per-user for "seen" state only, not per-user delivery
    targeting.
12. **Enrolment/completion reports (S3's real gap) copy
    `ExportAttendanceRegister`'s exact shape**: `ShouldQueue` job +
    `RebindsTenant` + `Storage::disk('local')` + a bare Filament `Page`
    with generate/download header actions + a gated plain download
    controller, reusing `EscapesCsvFormulas`. No new architectural
    pattern — the fourth and fifth exports built this exact way.

## 5. Testing strategy

- **Unit/feature per service**: `ConflictDetector` (or equivalent —
  named at block-build time), `StudentCsvImporter`,
  `EnquiryConversionService`, `IdCardGenerator` each get a dedicated test
  file.
- **Cross-tenant isolation**: all 11 new `BelongsToTenant` models register
  in `TenantModelRegistry`, auto-generating `CrossTenantTest` coverage —
  no hand-written per-model isolation tests.
- **Conflict detection**: a dedicated test proves two `BatchSession`s for
  the same instructor with overlapping windows across *different*
  batches are refused, and that non-overlapping or different-instructor
  sessions are not.
- **Bulk import**: a CSV with a mix of valid/invalid rows produces the
  exact right `success_count`/`error_count` and a downloadable error
  report naming the right row numbers — not just that the job runs.
- **Forum depth cap**: a reply attempt at depth 3 is refused; depth 0-2
  succeed.
- **Lifecycle E2E**: at minimum, enquiry → follow-up → convert-to-enrolment
  → ID card issued, chained in one run; plus instructor invite → accept →
  assigned batch → conflict detected on a second overlapping session
  attempt.
- Design doc §5 estimate: this is a rough anchor, not a target, same
  posture every prior slice's own estimate already undershot — expect
  70-100 new tests across the slice by its own final block.
