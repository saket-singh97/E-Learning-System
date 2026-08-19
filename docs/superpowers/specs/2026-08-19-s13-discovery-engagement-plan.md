# Implementation Plan: S13 Discovery & Content Engagement

Full design: `2026-08-19-s13-discovery-engagement-design.md`. This is the
block-by-block build log — update the PROGRESS table as each block lands,
same as every S1-S12 plan did. 18 blocks (B0-B17), finer-grained than the
8-item roadmap count since several items split into independent blocks
(catalog/search/wishlist, prerequisites/unlocking, programs/purchase,
Q&A/upvoting, messaging/moderation, reviews/instructor-response each split
in two).

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-19-s13-discovery-engagement-design.md and
docs/superpowers/specs/2026-08-19-s13-discovery-engagement-plan.md, then build
Block 0 of the S13 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | Categories + tags schema/models (`Category`, `Tag`, pivots) + Filament CRUD + assignment on `CourseResource` (C7 pt.1) | ⬜ not started |
| B1 | Catalog filtering — public `CourseCatalog` Livewire page, category/tag filters (C7 pt.2) | ⬜ not started |
| B2 | Course search on the catalog page — Eloquent `LIKE`/`orWhere`, no raw SQL (C10 pt.1) | ⬜ not started |
| B3 | Wishlist — model + toggle action + "My wishlist" view (C10 pt.2) | ⬜ not started |
| B4 | Recommendations — same-category/tag heuristic, surfaced on catalog + course page (C10 pt.3) | ⬜ not started |
| B5 | Course prerequisites — `lesson_prerequisites` pivot + `PrerequisiteGraph` circular-dependency check + Filament UI on `LessonResource` (C8 pt.1) | ⬜ not started |
| B6 | Sequential unlocking — `LessonCompletion` service (per-lesson-type heuristic) wired into `LessonAccess` (C8 pt.2) | ⬜ not started |
| B7 | Programs — `Program`/`program_batch` schema + Super/Admin Filament CRUD (C9 pt.1) | ⬜ not started |
| B8 | Program purchase — `fee_invoices.program_id`, `ProgramEnrolmentService`, webhook-driven bundle enrolment (C9 pt.2) | ⬜ not started |
| B9 | Lesson Q&A — `LessonQuestion`/`LessonQuestionReply` schema + post/reply UI on `LessonPlayer` (N7 pt.1) | ⬜ not started |
| B10 | Q&A upvoting + resolved marking — `LessonQuestionVote`, instructor/admin resolve action (N7 pt.2) | ⬜ not started |
| B11 | Timestamped notes + bookmarks — `LessonNote` schema + `LessonPlayer` timeline markers (Alpine.js seek-to, reuses `DoubtChat`'s pattern) (N8) | ⬜ not started |
| B12 | Direct messaging — `DirectMessage`/`BlockedUser` schema + send/receive Livewire component, block enforcement (N9 pt.1) | ⬜ not started |
| B13 | Message reporting + Admin-panel moderation queue — `MessageReport`, actioned/dismissed flow (N9 pt.2) | ⬜ not started |
| B14 | Course reviews — `CourseReview` schema + submit-review flow, enrolment-gated (N10 pt.1) | ⬜ not started |
| B15 | Instructor response + review moderation — hide/show in Filament, `instructor_response` write path (N10 pt.2) | ⬜ not started |
| B16 | `TenantModelRegistry` sweep — all 12 new `BelongsToTenant` models registered, `CrossTenantTest` generated coverage confirmed | ⬜ not started |
| B17 | Full test coverage, review pass, V5 regression-guard confirmation, `PROJECT-STATUS.md`/`HANDOVER.md`/`ROADMAP.md` update | ⬜ not started |

**Test suite:** 1094/1093 at slice start (1 pre-existing, unrelated
`CourseSlugUniquenessTest` failure carried since S1/S2, out of scope here
same as every prior slice). Design doc §5 estimates ~90-110 new tests by
B17 (~1185-1205 total) — a rough anchor, not a target; every prior slice's
own estimate undershot its actual by a wide margin.

---

## Block detail (expand as each block builds; stubs below are the starting checklist)

### B0 — Categories + tags schema/models — not started

- Migrations: `categories` (self-referential `parent_id`), `tags`,
  `course_category` pivot, `course_tag` pivot — all 4 tenant-scoped
  except the pivots.
- `Category`/`Tag` models, `BelongsToTenant`, `$fillable` explicit (no
  `$guarded = []`).
- Admin-panel `CategoryResource`/`TagResource` (simple CRUD, mirrors
  `PlanResource`'s own minimal shape).
- `CourseResource` gains a category/tag multi-select on the edit form.

### B1 — Catalog filtering — not started

- New `app/Livewire/CourseCatalog.php` (public, tenant-resolved route,
  matches `StudentPortal`'s own routing shape) listing published courses.
- Category/tag filter UI, query via Eloquent relationship `whereHas`.
- Route + view wired into `routes/content.php`.

### B2 — Course search — not started

- Search box on `CourseCatalog`, `title`/`description` `LIKE` via
  Eloquent (`orWhere`, no `whereRaw`/`DB::raw` — `SecurityBaselineTest`
  enforces this repo-wide).

### B3 — Wishlist — not started

- `wishlists` migration + model, unique on `(tenant_id, user_id,
  course_id)`.
- Toggle action on `CourseCatalog`/course page; "My wishlist" view.

### B4 — Recommendations — not started

- Same-category/tag heuristic, excludes courses the viewer is already
  enrolled in, surfaced on `CourseCatalog` + the single-course page.

### B5 — Course prerequisites — not started

- `lesson_prerequisites` pivot migration (self-referential on `lessons`).
- `PrerequisiteGraph` service — bounded DFS, throws
  `CircularPrerequisiteException` on a cycle.
- Filament UI on `LessonResource` to pick prerequisite lesson(s).

### B6 — Sequential unlocking — not started

- `LessonCompletion` service — per-type binary heuristic (design doc
  decision 5).
- `config('content.lesson_completion_watch_threshold')`, default 90.
- `LessonAccess::canStream()` extended: a lesson with unmet
  prerequisites is denied, same exception shape already used for
  publish-status/drip-date denial.

### B7 — Programs — not started

- `programs` (SoftDeletes) + `program_batch` pivot migrations.
- `Program` model, `BelongsToTenant`.
- Super-panel `ProgramResource` (bundle authoring) + Admin-panel
  read view.

### B8 — Program purchase — not started

- `fee_invoices.program_id` nullable FK migration.
- `ProgramEnrolmentService` — on payment success, one `Enrolment` per
  bundled batch, idempotent on a retried webhook.
- Wired into the existing Razorpay webhook path (Fees domain), not a new
  payment surface.

### B9 — Lesson Q&A — not started

- `lesson_questions` (SoftDeletes) + `lesson_question_replies`
  (SoftDeletes) migrations.
- Post-a-question / reply UI on `LessonPlayer`, gated by
  `LessonAccess::canStream()`'s own verdict (design doc decision 8).

### B10 — Q&A upvoting + resolved marking — not started

- `lesson_question_votes` migration, unique on `(lesson_question_id,
  user_id)`.
- Upvote action (any viewer with access); resolved-marking action
  (instructor/admin only).

### B11 — Timestamped notes + bookmarks — not started

- `lesson_notes` migration (nullable `body` — decision 7).
- `LessonPlayer` timeline markers, reuses `DoubtChat`'s existing
  Alpine.js `dispatch`-based seek-to mechanism.

### B12 — Direct messaging — not started

- `direct_messages` + `blocked_users` migrations.
- Send/receive Livewire component, symmetric block enforcement at
  read/send time (decision 9).

### B13 — Message reporting + moderation — not started

- `message_reports` migration.
- Admin-panel moderation queue (`actioned` hides the message,
  `dismissed` clears the flag) — flag-don't-auto-act (decision 10).

### B14 — Course reviews — not started

- `course_reviews` migration, unique on `(course_id, user_id)`.
- Submit-review flow, `CourseReviewPolicy` gates on any past-or-current
  enrolment in the course (decision 12).

### B15 — Instructor response + review moderation — not started

- Instructor-response write path (course's own instructor only).
- Admin-panel hide/show moderation action (`status` flag, not delete —
  decision 11).

### B16 — TenantModelRegistry sweep — not started

- Confirm all 12 new `BelongsToTenant` models
  (`Category`, `Tag`, `Program`, `Wishlist`, `LessonQuestion`,
  `LessonQuestionReply`, `LessonQuestionVote`, `LessonNote`,
  `CourseReview`, `DirectMessage`, `BlockedUser`, `MessageReport`)
  registered with `seedAttributes()`/`updateAttributes()` entries;
  `CrossTenantTest` generates coverage for each with no hand-written
  per-model test.

### B17 — Full test coverage, review pass — not started

- Lifecycle E2E test(s) mirroring S11 B16/S12 B14's own posture — at
  minimum: catalog search → wishlist → enrol → prerequisite-gated next
  lesson unlocks after completion, chained in one run.
- V5 regression-guard: re-run `SecurityBaselineTest` explicitly.
- Explicit review pass across every B0-B16 file for real bugs, not just
  a green suite — same discipline S9 B14/S10 B15/S11 B16/S12 B14 each
  already established (each found real bugs this way).
- Update `HANDOVER.md` (S13 complete entry + any new `## Avoid` entries),
  `docs/PROJECT-STATUS.md` (new S13 section), `docs/ROADMAP.md` (S13 row
  → Done).
