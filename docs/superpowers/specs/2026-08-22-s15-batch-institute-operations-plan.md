# Implementation Plan: S15 Batch & Institute Operations

Full design: `2026-08-22-s15-batch-institute-operations-design.md`. This is
the block-by-block build log — update the PROGRESS table as each block
lands, same as every S1-S14 plan did. 22 blocks (B0-B21), finer-grained
than the 10-item real build scope since several items split into
independent blocks (instructor onboarding split across three, timetable
split in two, forum split in two, feedback split in two, bulk CSV import
split in two, bulk background jobs split in two, admission CRM split in
two, noticeboard split in two, reports split in two).

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-22-s15-batch-institute-operations-design.md and
docs/superpowers/specs/2026-08-22-s15-batch-institute-operations-plan.md, then build
Block 0 of the S15 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | Instructor invitation + assignment schema — `instructor_invitations`, `batches.instructor_id` (closes §S.S1's real gap, foundation for F7/§S.S2/F10 pt.2) | ⬜ not started |
| B1 | Instructor invitation flow — Admin-panel invite/accept/assign/deactivate/reactivate | ⬜ not started |
| B2 | Per-instructor permission enforcement (§S.S2) — Teach panel + policies scoped to only the instructor's own assigned batches | ⬜ not started |
| B3 | Timetable templates schema — `timetable_templates` (F7 pt.1) | ⬜ not started |
| B4 | Teacher conflict detection — write-time overlap check on `BatchSession` create/edit against the assigned instructor's other sessions (F7 pt.2) | ⬜ not started |
| B5 | Discussion forum schema — `forum_threads`/`forum_posts`, depth-capped at 3 (F6 pt.1) | ⬜ not started |
| B6 | Discussion forum UI — batch-scoped Livewire component, enrolment-gated (F6 pt.2) | ⬜ not started |
| B7 | Batch feedback schema + student submission — `batch_feedback` (F8 pt.1) | ⬜ not started |
| B8 | Batch feedback aggregation view — instructor/admin-facing average + comments (F8 pt.2) | ⬜ not started |
| B9 | Bulk student CSV import — schema + parser/validator — `student_import_batches` (F9 pt.1) | ⬜ not started |
| B10 | Bulk student CSV import — queued creation job + downloadable per-row error report (F9 pt.2) | ⬜ not started |
| B11 | Bulk enrolment as a background job — thin `ShouldQueue` wrapper around `BatchEnrolmentService::addManually()` (F10 pt.1) | ⬜ not started |
| B12 | Bulk instructor invitations as a background job — thin `ShouldQueue` wrapper around B1's own invitation path (F10 pt.2) | ⬜ not started |
| B13 | Admission CRM schema — new `Operations` domain, `enquiries` (§S.S4 pt.1) | ⬜ not started |
| B14 | Admission CRM follow-up + convert-to-enrolment — `enquiry_follow_ups`, real `Enrolment` on convert (§S.S4 pt.2) | ⬜ not started |
| B15 | ID card generation — `id_cards`, dompdf + qr-code reuse from `Certificate`'s own precedent (§S.S5) | ⬜ not started |
| B16 | Noticeboard schema + authoring — `notices`, role-targeted, Admin-panel compose (§S.S6 pt.1) | ⬜ not started |
| B17 | Noticeboard display + read tracking — `notice_read_receipts`, portal/panel banner (§S.S6 pt.2) | ⬜ not started |
| B18 | Enrolment report export — CSV job, `ExportAttendanceRegister`-shaped (§S.S3 pt.1, real gap-close) | ⬜ not started |
| B19 | Completion report export — CSV job, same shape (§S.S3 pt.2, real gap-close) | ⬜ not started |
| B20 | `TenantModelRegistry` sweep — all 11 new `BelongsToTenant` models confirmed registered | ⬜ not started |
| B21 | Full test coverage, review pass, `PROJECT-STATUS.md`/`HANDOVER.md`/`ROADMAP.md` update | ⬜ not started |

**Test suite:** 1283/1284 at slice start (1 pre-existing, unrelated
`CourseSlugUniquenessTest` failure carried since S1/S2, out of scope here
same as every prior slice). Design doc §5 estimates ~70-100 new tests by
B21 — a rough anchor, not a target; every prior slice's own estimate
undershot its actual by a wide margin.

---

## Block detail (expand as each block builds; stubs below are the starting checklist)

### B0 — Instructor invitation + assignment schema — not started

- `instructor_invitations` migration (`tenant_id`, `email`, `token` unique,
  `invited_by_user_id`, `status` pending|accepted|expired|revoked,
  `expires_at`, `accepted_user_id` nullable).
- `batches.instructor_id` nullable FK migration (design decision 1 — one
  primary instructor per batch, not a pivot).
- `InstructorInvitation` model, `BelongsToTenant`, registered in
  `TenantModelRegistry` (seed/update attributes included).

### B1 — Instructor invitation flow — not started

- Admin-panel invite action (email + course/batch assignment at invite
  time), 14-day token expiry, same host-agnostic-callback shape
  `ZoomOAuthController`/`ParentInviteRedemption` already established for
  a token-carries-context flow.
- Accept flow creates the `User` (or links an existing one) with the
  `instructor` role and sets `batches.instructor_id` for every assigned
  batch.
- Deactivate/reactivate action on an accepted instructor (closes the
  remaining half of §S.S1's original, never-built scope).

### B2 — Per-instructor permission enforcement (§S.S2) — not started

- `TeachPanelProvider` (or the relevant resource query scopes) narrowed
  from directory-wide discovery to `where('instructor_id', auth()->id())`
  on every batch-scoped resource — the *data*-level fix S13 B17's
  resource-*list*-level fix didn't reach.
- Policy/query audit across every Teach-panel resource that touches
  `Batch`/`BatchSession`/`Enrolment`, same "every call site checked, not
  assumed" discipline S9 B12's bandwidth-quota sweep already used.

### B3 — Timetable templates schema — not started

- `timetable_templates` migration (`tenant_id`, `title`, `day_of_week`,
  `starts_at` time, `duration_minutes`).
- `TimetableTemplate` model, registered in `TenantModelRegistry`.
- Admin-panel CRUD.

### B4 — Teacher conflict detection — not started

- Write-time check on `BatchSession` create/edit: does this session's
  `[starts_at, starts_at + duration_minutes)` window overlap any other
  `BatchSession` whose batch shares the same `instructor_id`, across any
  batch — refuse the save if so (design decision 4).
- `BatchSessionsRelationManager` (or wherever sessions are scheduled)
  surfaces the refusal as a real validation error, not a generic 500.
- Optional: "create from template" action copying a `TimetableTemplate`'s
  `day_of_week`/`starts_at`/`duration_minutes` into a new session's own
  columns (design decision 3 — a stamp, no persisted FK).

### B5 — Discussion forum schema — not started

- `forum_threads`/`forum_posts` migrations (`forum_posts.parent_id`
  self-referential nullable, `depth` 0-2).
- `ForumThread`/`ForumPost` models, registered in `TenantModelRegistry`.
- Depth-3 refusal enforced at write time (design decision 5).

### B6 — Discussion forum UI — not started

- Livewire component, batch-scoped, gated by active enrolment in that
  batch (reuses the same enrolment-check shape `LessonQuestions`'s own
  `canStream()`-style gate already established, adapted to batch
  membership instead of lesson access).
- Post/reply composer, thread list, nested reply rendering to depth 2.

### B7 — Batch feedback schema + student submission — not started

- `batch_feedback` migration (unique on `batch_id`+`user_id`, `rating`
  1-5 DB `CHECK`, same `CourseReview` precedent from S13).
- `BatchFeedback` model, registered in `TenantModelRegistry`.
- Student submission form, gated by enrolment (active or expired, same
  "genuine participation" posture `CourseReviewPolicy::canReview()`
  enforces after its own S13 B17 fix).

### B8 — Batch feedback aggregation view — not started

- Instructor/admin-facing average rating + comment list per batch.

### B9 — Bulk student CSV import — schema + parser — not started

- `student_import_batches` migration (`status`, `total_rows`,
  `success_count`, `error_count`, `error_report_disk_path` nullable).
- `StudentImportBatch` model, registered in `TenantModelRegistry`.
- Synchronous phase-1 parse/validate (design decision 6) — file-shape and
  per-row validation errors collected before any queue dispatch.

### B10 — Bulk student CSV import — queued job + error report — not started

- Phase-2 `ShouldQueue` job: creates a `User` + `Enrolment` per valid row
  (reuses `BatchEnrolmentService::addManually()`, design decision 7-adjacent
  for the user-creation half), updates `student_import_batches` counts.
- Downloadable per-row error report CSV, `EscapesCsvFormulas`-guarded.
- Admin-panel upload page + status/download page.

### B11 — Bulk enrolment as a background job — not started

- Thin `ShouldQueue` job wrapping `BatchEnrolmentService::addManually()`
  once per row in a submitted student-id/email list (design decision 7)
  — no new enrolment code path, every existing guarantee (seat lock,
  policy gate) inherited for free.

### B12 — Bulk instructor invitations as a background job — not started

- Thin `ShouldQueue` job wrapping B1's own single-invitation path once
  per row in a submitted email list (design decision 8).

### B13 — Admission CRM schema — not started

- New `app/Domains/Operations/` domain scaffold (`Models/`, `Services/`,
  `Filament/`, `Livewire/`, `Views/`, `Jobs/`, `Policies/`, `Events/` —
  same subfolder shape every domain already carries per `CLAUDE.md`).
- `enquiries` migration (`status` new|contacted|follow_up|converted|lost,
  `course_id` nullable interest, `assigned_to_user_id` nullable,
  `converted_enrolment_id` nullable).
- `Enquiry` model, registered in `TenantModelRegistry`.
- Admin/Teach-panel enquiry intake form + list.

### B14 — Admission CRM follow-up + convert-to-enrolment — not started

- `enquiry_follow_ups` migration + model, registered in `TenantModelRegistry`.
- Follow-up note logging, `next_follow_up_at` reminder field.
- "Convert to enrolment" action calling `BatchEnrolmentService::addManually()`
  (design decision 9 — real enrolment, not a parallel path), sets
  `enquiries.status = 'converted'` + `converted_enrolment_id`.

### B15 — ID card generation — not started

- `id_cards` migration (`enrolment_id`, `card_number` unique per tenant,
  `disk_path`, `issued_at`).
- `IdCard` model, registered in `TenantModelRegistry`.
- `IdCardGenerator` service — dompdf template + `endroid/qr-code`, same
  precedent `Certificate`'s own S10 generator already established.
- Manual per-enrolment "Issue ID card" action (design decision 10 — not
  auto-issued), download route.

### B16 — Noticeboard schema + authoring — not started

- `notices` migration (`audience_roles` json, `published_at` nullable —
  null is draft).
- `Notice` model, registered in `TenantModelRegistry`.
- Admin-panel compose/publish page.

### B17 — Noticeboard display + read tracking — not started

- `notice_read_receipts` migration (unique on `notice_id`+`user_id`) +
  model, registered in `TenantModelRegistry`.
- Portal/panel banner or list showing published notices matching the
  viewer's own role, mark-as-read action.

### B18 — Enrolment report export — not started

- `ExportEnrolmentRegister` `ShouldQueue` job, `ExportAttendanceRegister`-shaped
  (design decision 12): `RebindsTenant`, `Storage::disk('local')`,
  `EscapesCsvFormulas`, a bare Filament `Page` + gated download controller.

### B19 — Completion report export — not started

- `ExportCompletionRegister`, same shape as B18, reusing the
  gradebook-based completion definition `CheckCourseCompletionAndIssueCertificates`
  (S10) already established rather than re-deriving it.

### B20 — TenantModelRegistry sweep — not started

- Confirm all 11 new `BelongsToTenant` models (`InstructorInvitation`,
  `TimetableTemplate`, `ForumThread`, `ForumPost`, `BatchFeedback`,
  `StudentImportBatch`, `Enquiry`, `EnquiryFollowUp`, `IdCard`, `Notice`,
  `NoticeReadReceipt`) registered with `seedAttributes()`/`updateAttributes()`
  entries; `CrossTenantTest` generates coverage for each with no
  hand-written per-model test.

### B21 — Full test coverage, review pass — not started

- Lifecycle E2E test(s) mirroring S11 B16/S12 B14/S13 B17/S14 B18's own
  posture — at minimum: enquiry → follow-up → convert-to-enrolment → ID
  card issued, chained in one run; plus instructor invite → accept →
  assigned batch → conflict detected on a second overlapping session
  attempt.
- Explicit review pass across every B0-B20 file for real bugs, not just
  a green suite — same discipline S9 B14/S10 B15/S11 B16/S12 B14/S13
  B17/S14 B18 each already established (each found real bugs this way).
- Update `HANDOVER.md` (S15 complete entry + any new `## Avoid` entries),
  `docs/PROJECT-STATUS.md` (new S15 section), `docs/ROADMAP.md` (S15 row
  → Done).
