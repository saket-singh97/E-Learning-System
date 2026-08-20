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
| B0 | Categories + tags schema/models (`Category`, `Tag`, pivots) + Filament CRUD + assignment on `CourseResource` (C7 pt.1) | ✅ done |
| B1 | Catalog filtering — public `CourseCatalog` Livewire page, category/tag filters (C7 pt.2) | ✅ done |
| B2 | Course search on the catalog page — Eloquent `LIKE`/`orWhere`, no raw SQL (C10 pt.1) | ✅ done |
| B3 | Wishlist — model + toggle action + "My wishlist" view (C10 pt.2) | ✅ done |
| B4 | Recommendations — same-category/tag heuristic, surfaced on catalog + course page (C10 pt.3) | ✅ done |
| B5 | Course prerequisites — `lesson_prerequisites` pivot + `PrerequisiteGraph` circular-dependency check + Filament UI on `LessonResource` (C8 pt.1) | ✅ done |
| B6 | Sequential unlocking — `LessonCompletion` service (per-lesson-type heuristic) wired into `LessonAccess` (C8 pt.2) | ✅ done |
| B7 | Programs — `Program`/`program_batch` schema + Admin Filament CRUD (C9 pt.1) | ✅ done |
| B8 | Program purchase — `fee_invoices.program_id`, `ProgramEnrolmentService`, webhook-driven bundle enrolment (C9 pt.2) | ✅ done |
| B9 | Lesson Q&A — `LessonQuestion`/`LessonQuestionReply` schema + post/reply UI on `LessonPlayer` (N7 pt.1) | ✅ done |
| B10 | Q&A upvoting + resolved marking — `LessonQuestionVote`, instructor/admin resolve action (N7 pt.2) | ✅ done |
| B11 | Timestamped notes + bookmarks — `LessonNote` schema + `LessonPlayer` timeline markers (Alpine.js seek-to, reuses `DoubtChat`'s pattern) (N8) | ✅ done |
| B12 | Direct messaging — `DirectMessage`/`BlockedUser` schema + send/receive Livewire component, block enforcement (N9 pt.1) | ✅ done |
| B13 | Message reporting + Admin-panel moderation queue — `MessageReport`, actioned/dismissed flow (N9 pt.2) | ✅ done |
| B14 | Course reviews — `CourseReview` schema + submit-review flow, enrolment-gated (N10 pt.1) | ✅ done |
| B15 | Instructor response + review moderation — hide/show in Filament, `instructor_response` write path (N10 pt.2) | ✅ done |
| B16 | `TenantModelRegistry` sweep — all 12 new `BelongsToTenant` models registered, `CrossTenantTest` generated coverage confirmed | ✅ done |
| B17 | Full test coverage, review pass, V5 regression-guard confirmation, `PROJECT-STATUS.md`/`HANDOVER.md`/`ROADMAP.md` update | ✅ done |

**Test suite:** 1094/1093 at slice start (1 pre-existing, unrelated
`CourseSlugUniquenessTest` failure carried since S1/S2, out of scope here
same as every prior slice). Design doc §5 estimates ~90-110 new tests by
B17 (~1185-1205 total) — a rough anchor, not a target; every prior slice's
own estimate undershot its actual by a wide margin.

**After B0:** 1096/1095 (2 new: `Category`/`Tag` registered in
`TenantModelRegistry`, `CrossTenantTest` auto-generates 2 more parameterized
cases). Same 1 pre-existing `CourseSlugUniquenessTest` failure, untouched.

**After B1:** 1100/1099 (4 new: `CourseCatalogTest` — published-only
listing, category filter, tag filter, combined AND filter). Same 1
pre-existing `CourseSlugUniquenessTest` failure, untouched.

**After B2:** 1103/1102 (3 new: search-by-title, search-by-description,
no-match-returns-empty). Same 1 pre-existing `CourseSlugUniquenessTest`
failure, untouched.

**After B3:** 1110/1109 (1 new from `Wishlist` in `TenantModelRegistry`,
6 new from `CourseCatalogTest`/`MyWishlistTest` — toggle on/off, guest
no-op both pages, own-user-only listing, remove). Same 1 pre-existing
`CourseSlugUniquenessTest` failure, untouched.

**After B4:** 1119/1118 (9 new: `RecommendationServiceTest` — category
match, tag match, excludes draft, excludes already-enrolled, empty for
no-enrolment/guest, enrolment-based catalog recs; +1 `CourseCatalogTest`;
+2 `ShowSyllabusControllerTest`). Same 1 pre-existing
`CourseSlugUniquenessTest` failure, untouched.

**After B5:** 1123/1122 (4 new in `PrerequisiteGraphTest`: valid chain +
legitimate 4th edge, rejects closing a cycle, rejects self-reference,
rejects a cycle attempted through the Filament form without writing the
pivot row). Same 1 pre-existing `CourseSlugUniquenessTest` failure,
untouched.

**After B6:** 1132/1131 (5 new: `LessonCompletionTest` — video threshold,
quiz attempted, assignment submitted, text/engagement-based, per-user
isolation; +4 `SequentialUnlockingTest` — denies incomplete prerequisite,
flips to allowed on completion, no gate on a prerequisite-free lesson,
staff bypass). Same 1 pre-existing `CourseSlugUniquenessTest` failure,
untouched. One run of this block's full suite also showed 4 unrelated
failures (Assignments PDF/Excel export, a transient Postgres deadlock on
`RoleSeeder`) — confirmed flaky, not a regression, by an immediate rerun
coming back clean.

**After B7:** 1137/1136 (4 new in `ProgramTest`: batch bundling + count,
slug uniqueness per tenant, soft-delete/restore keeps the bundle, Admin
panel form creates with only published batches selectable). Same 1
pre-existing `CourseSlugUniquenessTest` failure, untouched.

**After B8:** 1141/1140 (4 new in `ProgramPurchaseE2ETest`: bundle invoice
created with no single batch/enrolment, enrolment created per bundled
batch, idempotent when `enrolFromInvoice()` runs twice, full webhook →
paid invoice → enrolments loop idempotent on a retried delivery). Same 1
pre-existing `CourseSlugUniquenessTest` failure, untouched. All 84
existing Fees-domain tests still pass unchanged — the `batch_id`/
`enrolment_id` nullability relaxation and `PaymentReconciler` branch
didn't disturb the existing single-batch purchase flow.

**After B9:** 1148/1147 (5 new in `LessonQuestionsTest`: post a question,
guest no-op, `canStream()` reuse denies posting on an unmet prerequisite,
reply, guest reply no-op). Same 1 pre-existing `CourseSlugUniquenessTest`
failure, untouched.

**After B10:** 1155/1154 (7 new in `LessonQuestionUpvotingTest`: toggle
upvote on/off, guest no-op, denied when `canStream()` denies the lesson,
instructor marks resolved, student can't, guest can't). Same 1
pre-existing `CourseSlugUniquenessTest` failure, untouched.

**After B11:** 1161/1160 (5 new in `LessonNotesTest`: bookmark with null
body, annotated note with a body, guest no-op for both, `canStream()`
reuse denies on an unmet prerequisite, private-per-user scoping — one
student never sees another's notes). Same 1 pre-existing
`CourseSlugUniquenessTest` failure, untouched.

**After B12:** 1171/1170 (8 new in `DirectMessagesTest`: send, both
directions of a thread visible, unread marked read on select, guest
no-op, blocked in either direction silences send, block toggles on/off,
cross-tenant recipient refused, conversation list shows only real
partners). Same 1 pre-existing `CourseSlugUniquenessTest` failure,
untouched.

**After B13:** 1180/1179 (9 new: `DirectMessageReportingTest` — recipient
reports sender's message, sender can't report own message, viewer
outside the thread can't report, actioned report hides the message from
the thread while an open one stays visible; `MessageReportResourceTest`
— lists an open report, `markActioned` flips status, `dismiss` flips
status, refuses a non-admin). Same 1 pre-existing
`CourseSlugUniquenessTest` failure, untouched.

**After B14:** 1187/1186 (6 new in `CourseReviewsTest`: enrolled student
submits, never-enrolled student refused, guest no-op, re-submission
updates in place rather than duplicating, a *past* enrolment still
allows a review — not scoped to the current batch, out-of-range rating
rejected). Same 1 pre-existing `CourseSlugUniquenessTest` failure,
untouched. One `--filter=Content` run also showed 2 unrelated failures
(a storage-file race in `DripSchedulingTest`, a Postgres deadlock on
`tenants_subdomain_unique` in `LessonPlayerTest`) — confirmed flaky, not
a regression, by an immediate rerun coming back clean.

**After B15:** 1193/1192 (9 new: `CourseReviewsTest` +3 — instructor
responds, student can't, guest can't; `CourseReviewResourceTest` +3 —
lists a review, hide/show round-trip proven reversible against the
public list itself (not just the DB column), refuses a non-admin).
Same 1 pre-existing `CourseSlugUniquenessTest` failure, untouched. N10
(course ratings + reviews) is now fully complete — the last item in
S13's own N-backlog.

**After B16:** 1192/1193, unchanged — a confirmation sweep, no new
tests. `tests/Isolation/CrossTenantTest.php` re-run directly: 46/46
passing (1 registry-completeness guard test + 45 parametrized
cross-tenant cases, one per registered model, all 12 new S13 models
included). Same 1 pre-existing `CourseSlugUniquenessTest` failure,
untouched.

**After B17:** 1206/1207 (1 new lifecycle E2E test +
`DiscoveryEngagementLifecycleE2ETest`, plus 14 new regression tests from
the review pass's own bug fixes — see below). Same 1 pre-existing
`CourseSlugUniquenessTest` failure, untouched. **S13 is now COMPLETE, all
18 blocks.**

---

## B17 — full test coverage, review pass, doc updates — done

- [x] **Lifecycle E2E test** (`DiscoveryEngagementLifecycleE2ETest`):
  catalog search (category filter) → wishlist → real `BatchEnrolmentService::enrol()`
  → `LessonAccess::canStream()` denies the next lesson (unmet
  prerequisite) → watching the prerequisite past threshold flips it to
  allowed, chained in one run — mirrors S11 B16/S12 B14's own lifecycle
  E2E posture (the one thing no per-block test could cover: do the
  pieces still fit together).
- [x] **V5 regression guard**: `SecurityBaselineTest` re-run explicitly —
  4/4 passing (no `$guarded = []`, no raw SQL in `app/Domains/`, CSRF,
  XSS sanitization). S13's own raw-DDL migrations (the `course_reviews`
  rating `CHECK` constraint, the `fee_invoices` nullability relaxation)
  live in `database/migrations/`, outside this test's scan scope, same
  as every prior slice's own Postgres-specific `ALTER`s.
- [x] **Review pass** — 8 parallel finder agents (line-by-line diff,
  removed-behavior audit, cross-file tracer, reuse, simplification,
  efficiency, altitude, CLAUDE.md conventions) plus a Filament
  vendor-source verification pass for the most serious claim. **Found
  and fixed 7 real bugs, all with regression tests:**
  1. **`PaymentReconciler` called `ProgramEnrolmentService` (Content)
     directly from Fees** — a hard CLAUDE.md violation ("Domains talk
     through Events/ only"), independently flagged by 3 of the 8 finder
     agents. Fixed with a new `ProgramInvoicePaid` event + a
     `EnrolFromProgramPayment` listener in Content, mirroring
     `PaymentCaptured`/`FinalizeEnrolmentOnPaymentCaptured`'s own
     one-way-ignorance shape exactly.
  2. **`TeachPanelProvider`'s directory-wide `discoverResources()` on
     `Domains/Content/Filament/Resources` silently exposed
     Category/Tag/Program/CourseReview management to every instructor**
     — B0/B7/B15's own `AdminPanelProvider` explicit `->resources()`
     array only ever *added* those to Admin, it never *removed* them
     from Teach's directory-wide scan. Fixed by switching
     `TeachPanelProvider` to the same explicit-array pattern
     (`CourseResource`/`CourseSectionResource`/`LessonResource` only) —
     the actually-correct fix CLAUDE.md's own resource-registration
     convention was pointing at all along. Confirmed with a Filament
     vendor-source read (`Select::saveRelationshipsUsing()`) that
     `modifyRelationshipQueryUsing` only narrows the *detach* diff, never
     the raw submitted state `sync()` writes — the same class of gap
     surfaces twice more below.
  3. **`LessonResource`'s prerequisites field — same-course scoping was
     UI-only.** `modifyQueryUsing` only narrows the dropdown's own
     options; the `->rule()` closure was the only real server-side gate,
     and it silently skipped validation (no `$fail()`) whenever
     `Lesson::find()` returned null for a cross-tenant or bogus id,
     and never checked the prerequisite's own course matched at all.
     Fixed: the rule now fails loudly on both an unresolvable id and a
     cross-course id, instead of silently writing the pivot row.
  4. **`CourseResource`'s categories/tags fields had zero server-side
     validation at all** — same gap as #3, one step further (no `->rule()`
     existed to even attempt a check). A tampered payload could attach
     another tenant's category/tag id directly. Fixed with a `->rule()`
     on each field confirming every submitted id resolves within the
     tenant-scoped `Category`/`Tag` table.
  5. **`CourseReviewPolicy::canReview()` counted any `Enrolment` status
     at all** — a `rejected` or never-paid `pending_payment` "enrolment"
     let that user post a public review with a visible
     `instructor_response`. Fixed: only `active`/`expired` (genuine
     participation) qualify. `RecommendationService::enrolledCourseIds()`
     had the identical status-blindness (lower severity — relevance, not
     authorization), fixed the same way for consistency.
  6. **`CourseCatalog::courses()` reimplemented course visibility with a
     bare `where('status', 'published')`** instead of reusing
     `LessonAccess`'s own canonical rule — silently dropping the
     staff-preview bypass `isCourseVisibleTo()` already establishes, the
     exact "one place answers this" gap CLAUDE.md's own `LessonAccess`
     section exists to prevent (and the exact bug class its own docblock
     already recounts once, from S5 B8). Fixed by widening
     `LessonAccess::isStaff()` to `public` and reusing it, rather than
     re-deriving the check.
  7. **`ProgramEnrolmentService::enrolFromInvoice()` had a TOCTOU race**
     — an `exists()`-then-`create()` shape with no lock, unlike
     `BatchEnrolmentService::createEnrolment()`'s own established
     try/catch-on-`QueryException` pattern for the identical
     `(tenant_id, batch_id, user_id)` partial unique index. Two
     concurrent webhook deliveries for the same program invoice (which
     `PaymentReconciler`'s own docblock explicitly anticipates) could
     each pass the exists() check before either committed, throwing a
     raw `QueryException` on a payment webhook. Fixed: each batch now
     gets its own transaction + try/catch, matching the established
     shape — and deliberately *not* one big transaction around the whole
     loop, so a genuine one-batch failure doesn't force re-enrolling
     already-succeeded batches on the next retry.
  - **Flagged, not fixed** (bigger scope than a review pass should
    decide alone, same posture every prior slice's own review block
    takes for a genuine product/UX call): `syllabus-tree.blade.php`
    shows "Enrol to access" for a lesson actually blocked by an unmet
    prerequisite, not enrolment — `canStream()` returns a bare bool with
    no reason code to render a more accurate message from; a real fix
    needs a reason-aware return shape threaded through every call site,
    a bigger change than this pass should make unilaterally. Program-linked
    `FeeInvoice` rows show "Untitled"/a blank batch column in
    `ExportRevenueRegister`/`FeeInvoiceResource` (both assume every
    invoice has a `batch`) — currently unreachable in practice, since no
    checkout controller/route calls `FeeInvoiceService::invoiceForProgram()`
    yet (B8 built only the payment-capture half); revisit once a program
    checkout UI actually ships. Several N+1 query patterns flagged by the
    efficiency angle (`LessonAccess::canStream()`'s prerequisite walk,
    `RecommendationService::forViewer()`'s per-course taxonomy pluck,
    `SyllabusTree`'s per-lesson quiz/assignment lookups) — real, but this
    platform has zero production traffic yet (design doc §2.3's own
    framing), so not worth the added complexity until real load exists.
    A handful of simplification/reuse findings (the toggle-on/off shape
    duplicated across `WishlistService`/`LessonQuestions::toggleUpvote()`/
    `DirectMessages::toggleBlock()`; a repeated Lesson/Section/Course seed
    chain in `TenantModelRegistry`, pre-dating this slice and shared by
    ~15 other match arms across S1-S12) — each already-documented as
    intentional pattern-following in its own docblock; extracting a
    shared helper now would be a refactor touching working, already-tested
    code well beyond this review pass's real-bug mandate.
- [x] `docs/PROJECT-STATUS.md`, `HANDOVER.md`, `docs/ROADMAP.md` updated
  to reflect S13 COMPLETE.
- [x] `composer test`: 1206/1207 passing. Same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated and untouched.

---

## Block detail (expand as each block builds; stubs below are the starting checklist)

### B0 — Categories + tags schema/models — done

- [x] Migrations: `categories` (self-referential `parent_id`, `nullOnDelete`),
  `tags`, `course_category` pivot, `course_tag` pivot (both composite
  primary key, no model — matches every other many-to-many in this app).
- [x] `Category`/`Tag` models, `BelongsToTenant`, `$fillable` explicit;
  `Course` gained `categories()`/`tags()` `belongsToMany`.
- [x] Registered in `TenantModelRegistry` (seed/update attributes) — not
  deferred to B16, since `CrossTenantTest`'s own guard test fails the
  suite immediately on any unregistered `BelongsToTenant` model
  (`CLAUDE.md`'s own rule); B16 is a final confirmation sweep, not the
  first registration point.
- [x] `CategoryResource`/`TagResource` built under
  `app/Domains/Content/Filament/Resources/` (mirrors `PlanResource`'s
  minimal CRUD shape) but registered on `AdminPanelProvider` via explicit
  `->resources([...])`, not `->discoverResources()` on the whole Content
  dir — that directory also holds `CourseResource` et al., deliberately
  kept Teach-panel-only (instructor role) per this app's existing
  Admin/Teach split. Taxonomy governance (defining categories/tags) is an
  institute_admin job; assigning them to a course is the instructor's, on
  `CourseResource`'s own form.
- [x] `CourseResource` gained `categories`/`tags` multi-select
  (`->relationship()`, searchable) — picks from existing tenant
  vocabulary, doesn't define new terms.
- [x] `composer test`: 1096/1095 passing (2 new tests from
  `TenantModelRegistry` registration); same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated and untouched.

### B1 — Catalog filtering — done

- [x] New `app/Livewire/CourseCatalog.php` — public (no auth middleware,
  same "published is readable by anyone" posture `ShowSyllabusController`
  already established), lists `status === 'published'` courses.
- [x] Category/tag filter UI (`wire:model.live` selects), query via
  Eloquent `whereHas('categories'|'tags', ...)` — both filters apply as
  AND when both are set.
- [x] Route (`GET /courses`, name `catalog.index`) + view wired into
  `routes/content.php` / `resources/views/content/catalog.blade.php` —
  same page-wrapper-embeds-full-livewire-component shape
  `student/dashboard.blade.php` already uses for `StudentPortal`, per the
  block's own "matches StudentPortal's own routing shape" instruction.
  No pagination — no precedent for it anywhere in this app yet, and a
  per-tenant course catalog isn't large enough to need it now.
- [x] `CourseCatalogTest` (4 tests): published-only listing, category
  filter, tag filter, combined AND filter.
- [x] `composer test`: 1100/1099 passing. Same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated and untouched.

### B2 — Course search — done

- [x] Search box on `CourseCatalog` (`wire:model.live.debounce.300ms`),
  `title`/`description` `LIKE` via Eloquent (`->where(fn($q) =>
  $q->where('title','like',...)->orWhere('description','like',...))`,
  no `whereRaw`/`DB::raw` — `SecurityBaselineTest` enforces this
  repo-wide). Composes with the existing category/tag filters (all AND'd
  together).
- [x] 3 new tests: search-by-title, search-by-description,
  no-match-returns-empty-state.
- [x] `composer test`: 1103/1102 passing. Same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated and untouched.

### B3 — Wishlist — done

- [x] `wishlists` migration + `Wishlist` model (`BelongsToTenant`), unique
  on `(tenant_id, user_id, course_id)`. Registered in
  `TenantModelRegistry`.
- [x] `WishlistService::toggle()` — single find-or-create/delete point
  shared by `CourseCatalog` and `MyWishlist`, avoids duplicating the
  unique-constraint-backed toggle logic across two components.
- [x] Toggle button on `CourseCatalog`; new `app/Livewire/MyWishlist.php`
  + `GET /wishlist` (`wishlist.index`) route/view, same page-wrapper
  shape as B1's catalog page.
- [x] **Deviation from the checklist's own "hard auth gate" instinct**:
  tried `->middleware('auth')` on the wishlist route first: this app has
  no single named `login` route (3 separate Filament panel logins +
  `StudentPortal`, embedded rather than routed) for Laravel's default
  `Authenticate` middleware to redirect a guest to, so it 500'd instead
  of redirecting (`UrlGenerationException: Missing required parameter
  for [Route: login]`). Fixed by dropping the route-level gate — `auth()->
  user() ?: return`/empty-state instead, the same no-op-for-guest posture
  `BatchCatalog`/`CourseCatalog` already established repo-wide.
- [x] 6 new tests (`CourseCatalogTest` +3, `MyWishlistTest` +4 minus 1
  reused): toggle on/off, guest no-op on both pages, own-user-only
  listing, remove-from-wishlist.
- [x] `composer test`: 1110/1109 passing. Same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated and untouched.

### B4 — Recommendations — done

- [x] `RecommendationService` (`app/Domains/Content/Services/`) —
  `forCourse(Course, ?User)` (same-category/tag as one course, for the
  single-course page) and `forViewer(?User)` (same-category/tag as
  everything the viewer is enrolled in, for the catalog page); both
  share one `relatedTo()` query so the "published, shares taxonomy, not
  excluded" logic isn't duplicated. `forViewer()` returns empty for a
  guest or a student with no enrolments yet — deliberately no
  "popular courses" fallback (design doc §2.3, not an invented heuristic).
- [x] Excludes the source course itself and any course the viewer already
  has an `Enrolment` row for (via `batch.course_id`), draft/archived
  courses filtered by `status = 'published'`.
- [x] Wired into `CourseCatalog` ("Recommended for you") and
  `ShowSyllabusController`/`content/syllabus.blade.php` ("You might also
  like").
- [x] 9 new tests across `RecommendationServiceTest` (category match, tag
  match, excludes draft, excludes already-enrolled, empty for
  no-enrolment/guest, catalog-page enrolment-based recs),
  `CourseCatalogTest` (+1), `ShowSyllabusControllerTest` (+2, new file).
- [x] `composer test`: 1119/1118 passing. Same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated and untouched.

### B5 — Course prerequisites — done

- [x] `lesson_prerequisites` pivot migration (self-referential on
  `lessons`, composite primary key, no model — same plain-pivot
  convention as B0's `course_category`/`course_tag`).
  `Lesson::prerequisites()` `belongsToMany`, read-only by convention —
  every write goes through `PrerequisiteGraph`.
- [x] `PrerequisiteGraph` service (`app/Domains/Content/Services/`):
  `addPrerequisite()` (throws `CircularPrerequisiteException`, the single
  write path) and `wouldCreateCycle()` (pure check, reused by both
  `addPrerequisite()` and the Filament form's inline validation). Bounded
  DFS capped at the course's own lesson count (design doc decision 6).
  `CircularPrerequisiteException` registered in `bootstrap/app.php` as a
  409.
- [x] Filament `Select::make('prerequisites')` on `LessonResource`,
  options scoped to the same course, `->rule()` closure calls
  `wouldCreateCycle()` per selected id so a cycle fails inline
  (`$fail()`) rather than mid-save exception+catch.
- [x] 4 new tests (`PrerequisiteGraphTest`): valid 3-lesson chain plus a
  legitimate non-cyclic 4th edge, rejects closing a cycle on top of an
  existing chain, rejects self-reference, rejects a cycle attempted
  through the actual `LessonResource` Filament form (proves the pivot
  row is never written, not just that the service throws in isolation).
- [x] `composer test`: 1123/1122 passing. Same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated and untouched.

### B6 — Sequential unlocking — done

- [x] `LessonCompletion` service (`app/Domains/Content/Services/`) —
  per-type binary heuristic (design doc decision 5): `video` (watched
  past threshold), `quiz` (any non-`in_progress` attempt), `assignment`
  (any submission). `pdf`/`text`/`embed`/`live_session` share a
  presence-based "any engagement record at all" heuristic — the design
  doc names only `text`/`live_session` for this bucket, `pdf`/`embed`
  extended into it for the same "nothing to measure completion of"
  reasoning (documented in the service itself, not silently assumed).
- [x] `config('content.lesson_completion_watch_threshold')` (new
  `config/content.php`), default 90.
- [x] `LessonAccess::canStream()` extended (constructor now also takes
  `LessonCompletion`): a lesson with an incomplete prerequisite is
  denied — same boolean-gate shape every other `canStream()` check
  already uses (free-preview bypass, tenant check, role check), not a
  new exception class. Staff bypass the gate entirely (same posture
  `isVisibleTo()`/`canJoinLive()` already take) since `STREAM_ROLES` minus
  `STAFF_ROLES` is exactly `student`.
- [x] 9 new tests: `LessonCompletionTest` (5 — one per heuristic bucket,
  plus proving one student's completion isn't credited to another),
  `SequentialUnlockingTest` (4 — denies while incomplete, flips to
  allowed once complete, no gate on a lesson with no prerequisites,
  staff bypass).
- [x] `composer test`: 1131/1132 passing. Same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated and untouched. (One run
  also surfaced 4 unrelated flaky failures — Assignments PDF/Excel
  export, a transient Postgres `RoleSeeder` deadlock — confirmed
  non-reproducing on an immediate rerun.)

### B7 — Programs — done

- [x] `programs` (SoftDeletes) + `program_batch` pivot migrations
  (composite primary key, no model — matches every other pivot).
- [x] `Program` model, `BelongsToTenant`, `SanitizesHtml` on
  `description`; `batches()` `belongsToMany(Batch::class,
  'program_batch')`. Registered in `TenantModelRegistry`.
- [x] **Deviation from the checklist's own wording**: the plan text said
  "Super-panel `ProgramResource` (bundle authoring) + Admin-panel read
  view" — but `Program` is `BelongsToTenant` (tenant-scoped) and
  `SuperPanelProvider` resolves no tenant at all (no `->domain()`, no
  `ResolveTenant`), so a Super-panel resource for it literally can't
  create/scope rows correctly. Built `ProgramResource` on the **Admin**
  panel instead (institute_admin authors their own tenant's bundles),
  matching every other tenant-owned Content resource
  (`CategoryResource`/`TagResource`, B0) — registered via the same
  explicit `->resources()` array, not directory discovery (keeps
  `CourseResource` off the Admin panel). No separate read view needed —
  full CRUD is Admin-panel-only, same as `CategoryResource`/`TagResource`.
- [x] Form: title/slug (tenant-scoped uniqueness, soft-delete-aware, same
  pattern as `CourseResource`), description, price, status, and a
  `batches` multi-select scoped to `status = 'published'` batches only —
  selling access to a draft/archived batch has nothing real to enrol
  into.
- [x] Table: `SoftDeletes` browsing (`TrashedFilter`,
  `ForceDeleteAction`/`RestoreAction`), same convention `CourseResource`
  already established.
- [x] 4 new tests (`ProgramTest`): batch bundling + count, slug
  uniqueness per tenant (reusable across tenants), soft-delete/restore
  keeps the bundle intact, and one Admin-panel Filament form test proving
  only published batches are selectable and the created program's
  bundle matches the selection.
- [x] `composer test`: 1136/1137 passing. Same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated and untouched.

### B8 — Program purchase — done

- [x] `fee_invoices.program_id` nullable FK migration (`nullOnDelete`,
  same shape `coupon_id` already uses). **Necessary addition beyond the
  checklist's own wording**: a program-linked invoice has no single
  batch or enrolment (none exist yet — they're created only after
  payment), so `batch_id`/`enrolment_id` (NOT NULL since B1) had to
  relax to nullable too. `doctrine/dbal` isn't installed for Schema's
  `->change()`, so this is raw `ALTER TABLE ... DROP NOT NULL` DDL in the
  migration — same precedent as every other Postgres-specific ALTER
  already in this app's migrations (e.g. `lessons_type_check`, S9 B6);
  `SecurityBaselineTest`'s raw-SQL ban only scans `app/Domains/`, not
  `database/migrations/`.
- [x] `FeeInvoiceService::invoiceForProgram(Program, User)` — one invoice
  for the bundle's whole price (no instalment split), reusing the same
  class's own sequencing/invoice-numbering so a program invoice number
  can never collide with a batch invoice's. Kept on `FeeInvoiceService`
  itself (Fees domain, taking a Content model parameter) rather than
  duplicating the numbering formula elsewhere — the same class of
  data-level cross-domain reference `FeeInvoice.batch_id` already has,
  not a business-logic coupling into another domain's service.
- [x] `ProgramEnrolmentService::enrolFromInvoice(FeeInvoice)` (Content
  domain) — on payment success, one `Enrolment` per bundled batch,
  landed `active` immediately (mirrors
  `BatchEnrolmentService::addManually()`'s own shape, no seat-limit/
  waitlist check — decision 3's own "no new resolution logic needed").
  Idempotent per batch (skips a batch the user's already enrolled in),
  dispatches `StudentEnrolled` per created enrolment. `Enrolment::
  SOURCES` gained `program_purchase`.
- [x] `PaymentReconciler::capture()` branches on `$invoice->program_id`:
  a program invoice calls `ProgramEnrolmentService::enrolFromInvoice()`
  directly instead of dispatching `PaymentCaptured` (that event carries a
  single non-nullable `enrolmentId`, which a program invoice doesn't
  have) — no new webhook surface, the existing Razorpay webhook path.
- [x] 4 new tests (`ProgramPurchaseE2ETest`): bundle invoice shape, one
  enrolment per bundled batch, `enrolFromInvoice()` idempotent when
  called twice directly, and the full webhook → paid invoice →
  enrolments loop idempotent on a retried delivery (Razorpay's own
  retry-until-2xx behavior, matching `PaymentReconciler`'s existing
  idempotency discipline).
- [x] `composer test`: 1140/1141 passing. Same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated and untouched. All 84
  existing Fees-domain tests re-run clean — the schema/`PaymentReconciler`
  changes didn't disturb the existing single-batch purchase flow.

### B9 — Lesson Q&A — done

- [x] `lesson_questions` (SoftDeletes) + `lesson_question_replies`
  (SoftDeletes) migrations. `LessonQuestion`/`LessonQuestionReply`
  models, registered in `TenantModelRegistry`. Bodies are plain-text chat
  content (not `SanitizesHtml`) — matches `DoubtChatMessage`'s own
  precedent, escaped at render time via Blade's default `{{ }}`, not a
  rich-text/WYSIWYG field.
- [x] **Placement deviation**: built as `app/Domains/Content/Livewire/
  LessonQuestions.php` (domain-namespaced, `Livewire::component('content.
  lesson-questions', ...)` alias in `AppServiceProvider`, `content::
  lesson-questions` domain view) rather than adding to `LessonPlayer`
  itself — `LessonPlayer` only renders for `video`/`live_session` lessons
  (`content/watch.blade.php`'s own type branch), but Q&A applies to every
  lesson type per decision 8, so it's its own component embedded outside
  that branch, mirroring `ai.doubt-chat`'s exact registration shape.
- [x] Gated by `LessonAccess::canStream()`'s own verdict — re-checked
  inside `askQuestion()`/`reply()` themselves, not just relied on from
  the page load: Livewire's `/livewire/update` endpoint is a separate,
  unguarded request (`CLAUDE.md`), same reasoning
  `WatchHeartbeatController` already re-checks `canStream()` despite the
  page having gated once.
- [x] 5 new tests (`LessonQuestionsTest`): post a question (on a `text`
  lesson, proving this isn't video-only), guest no-op, `canStream()`
  reuse actually denies posting when a prerequisite (B6) is unmet — not
  just asserted in a comment — reply, guest reply no-op.
- [x] `composer test`: 1147/1148 passing. Same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated and untouched.

### B10 — Q&A upvoting + resolved marking — done

- [x] `lesson_question_votes` migration, unique on `(lesson_question_id,
  user_id)` — DB-level, not an app-level check alone (decision 8).
  `LessonQuestionVote` model + `LessonQuestion::votes()`, registered in
  `TenantModelRegistry`.
- [x] `toggleUpvote()` on `LessonQuestions` — any viewer with access,
  toggled on/off (same on/off shape `WishlistService::toggle()` already
  established, B3), gated by the same `canStream()` re-check every other
  action in this component uses.
- [x] `markResolved()` — instructor/admin-only (`LessonAccess::
  STAFF_ROLES`), mirrors how `AssignmentSubmission` grading is
  instructor/admin-gated. This component has no Filament panel
  role-middleware to lean on (embedded on the student-facing watch page),
  so the role check lives in the action itself.
- [x] 7 new tests (`LessonQuestionUpvotingTest`): toggle on/off, guest
  no-op, denied when `canStream()` denies the lesson (unmet
  prerequisite), instructor resolves, student can't, guest can't.
- [x] `composer test`: 1154/1155 passing. Same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated and untouched.

### B11 — Timestamped notes + bookmarks — done

- [x] `lesson_notes` migration (nullable `body` — decision 7). `LessonNote`
  model, registered in `TenantModelRegistry`.
- [x] **Placement, matching B9/B10's own precedent**: built as
  `App\Domains\Content\Livewire\LessonNotes` (own component, `content.
  lesson-notes` alias), not code stuffed inside `LessonPlayer.php` itself
  — `DoubtChat` is a *separate* component that dispatches the seek-to
  event and `LessonPlayer` merely listens for it globally
  (`x-on:seek-to.window`), so a second, independent component can do the
  same without touching the player's own internals. `LessonPlayer`
  itself only gained one line — `x-on:timeupdate` on the `<video>`
  broadcasts `lesson-time-update` so `LessonNotes` (a sibling component)
  knows the current playhead position to attach a marker to.
- [x] "Bookmark this moment" (body left null) and "Add note" (body set)
  — reuses the exact `x-on:click="$dispatch('seek-to', {seconds: N})"`
  pattern `DoubtChat`'s own citation buttons already established, no new
  player-side mechanism.
- [x] Private, per-user list (a student's own notes/bookmarks) — same
  "my own" scoping `Wishlist` already established (B3), not a shared
  thread like `LessonQuestions`.
- [x] Gated by `LessonAccess::canStream()`, re-checked in the action
  methods themselves, same reasoning B9/B10 already established.
  Embedded only in the video/live_session branch of `content/watch.
  blade.php` — timestamp-based markers have no meaning on a text/pdf/
  embed lesson with no video timeline.
- [x] 5 new tests (`LessonNotesTest`): bookmark (null body), annotated
  note (body set), guest no-op for both, `canStream()` reuse denies on
  an unmet prerequisite, private-per-user scoping.
- [x] `composer test`: 1160/1161 passing. Same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated and untouched.

### B12 — Direct messaging — done

- [x] `direct_messages` + `blocked_users` migrations, in the **Comms**
  domain (design doc §3.2 — "communication between people," not a
  Content concern). `DirectMessage`/`BlockedUser` models, registered in
  `TenantModelRegistry`.
- [x] `App\Domains\Comms\Livewire\DirectMessages` (first Livewire
  component in this domain) — `comms.direct-messages` alias, `comms::
  direct-messages` domain view (new `Views/` folder for this domain).
  Public, no route-level `auth` gate, same "no single named login route"
  reasoning `MyWishlist` already established (B3) — a guest sees a
  log-in prompt, `GET /messages` (`messages.index`) in `routes/comms.php`.
- [x] Conversation list (distinct partners the viewer has exchanged a
  message with) + a "start a new conversation" picker (any other tenant
  user) + a thread view + send form. Selecting a thread marks the
  viewer's own unread messages in it read.
- [x] Symmetric block enforcement at send time (decision 9): one
  directed `blocked_users` row, but `send()` checks both directions —
  either party blocking the other silences the thread. `toggleBlock()`
  toggles on/off, same shape `WishlistService::toggle()` already
  established (B3).
- [x] Recipient must belong to the same tenant (`User::where('tenant_id',
  ...)->find()`) — a cross-tenant id can't be messaged.
- [x] 8 new tests (`DirectMessagesTest`): send, both directions of a
  thread visible, unread marked read on select, guest no-op, blocked in
  either direction silences send, block toggles on/off, cross-tenant
  recipient refused, conversation list shows only real partners.
- [x] `composer test`: 1170/1171 passing. Same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated and untouched.

### B13 — Message reporting + moderation — done

- [x] `message_reports` migration (`status` defaults `open`).
  `MessageReport` model, registered in `TenantModelRegistry`.
  `DirectMessage::reports()` `hasMany`.
- [x] `DirectMessages::reportMessage()` — only the *other* party's
  messages (reporting your own sent message is nonsensical), only inside
  the viewer's own selected thread (can't report an arbitrary id from
  outside it).
- [x] `threadMessages()` excludes a message with an `actioned` report via
  `whereDoesntHave('reports', ...)` — the row itself is untouched, this
  is purely a display-time hide, matching decision 10's own "not an
  automated takedown" framing (an `open` or `dismissed` report leaves the
  message fully visible).
- [x] `MessageReportResource` (Comms domain, Admin panel — already
  directory-discovered there, no explicit `->resources()` entry needed
  unlike B0/B7's Content-domain resources). Browse-only, same shape
  `FeePaymentResource` already established (S12 B13): every real row
  comes from a student's own report action, never a manual admin entry.
  `markActioned`/`dismiss` actions, both visible only while `status ===
  'open'`.
- [x] 9 new tests (`DirectMessageReportingTest` +4,
  `MessageReportResourceTest` +4, `DirectMessagesTest` net +1 from the
  earlier count) covering the report path, the display-time hide, and
  the moderation queue's own two actions plus role gating.
- [x] `composer test`: 1179/1180 passing. Same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated and untouched.

### B14 — Course reviews — done

- [x] `course_reviews` migration, unique on `(course_id, user_id)` +
  a `CHECK (rating BETWEEN 1 AND 5)` constraint (raw DDL, same
  belt-and-suspenders precedent `assignment_submissions.version_number`
  already established, S10 B3) — DB-enforced backstop, real rejection UX
  lives in the Livewire form. `CourseReview` model (plain-text
  body/instructor_response, not `SanitizesHtml` — same precedent
  `LessonQuestion`'s own body already set), registered in
  `TenantModelRegistry`. `Course::reviews()` `hasMany`.
- [x] `CourseReviewPolicy` (`app/Domains/Content/Policies/`, first Policy
  in this domain, mirrors `EnrolmentApprovalPolicy`'s own plain-class
  shape) — `canReview()` checks any `Enrolment` in any batch of the
  course, not scoped to a current batch (decision 12); a past learner
  from an earlier, even now-expired, batch of the same course still
  qualifies.
- [x] `CourseReviews` Livewire component (`content.course-reviews`),
  embedded on the course page (`content/syllabus.blade.php`). Submitting
  again updates the existing row (`updateOrCreate`) rather than erroring
  on the unique constraint — one review per learner per course, editable.
- [x] 6 new tests (`CourseReviewsTest`): enrolled student submits,
  never-enrolled student refused, guest no-op, re-submission updates in
  place, a past (expired) enrolment still qualifies, out-of-range rating
  rejected.
- [x] `composer test`: 1186/1187 passing. Same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated and untouched. One
  `--filter=Content` run also surfaced 2 unrelated flaky failures
  (storage-file race, a `tenants_subdomain_unique` Postgres deadlock) —
  confirmed non-reproducing on an immediate rerun.

### B15 — Instructor response + review moderation — done

- [x] `CourseReviews::respond()` — "course's own instructor" has no
  backing per-course assignment model (same gap
  `EnrolmentApprovalPolicy`'s own docblock already documents), read the
  same way that policy already does: an instructor in the course's own
  tenant. Deliberately instructor-only, not `institute_admin` too —
  responding to feedback and moderating abuse are different jobs, the
  latter being this block's other half.
- [x] `CourseReviewResource` (Content domain, Admin panel via the same
  explicit `->resources()` array B0/B7/B13 already use). Browse-only,
  same shape `FeePaymentResource`/`MessageReportResource` already
  established. `hide`/`show` actions — genuinely reversible both ways
  (decision 11), unlike `MessageReportResource`'s own one-way status
  transitions; each row's current `status` decides which single action
  is visible.
- [x] `CourseReviews::reviews()` already filtered to `status =
  'published'` since B14 — hiding a review via the Admin panel now has a
  real effect on the public list without any change needed there.
- [x] 9 new tests: instructor responds / student can't / guest can't
  (`CourseReviewsTest`), and `CourseReviewResourceTest` proves the
  hide/show round-trip against the actual public `CourseReviews`
  component (not just asserting the DB column flipped), plus role
  gating.
- [x] `composer test`: 1192/1193 passing. Same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated and untouched.

### B16 — TenantModelRegistry sweep — done

- [x] Confirmed all 12 new `BelongsToTenant` models (`Category`, `Tag`,
  `Program`, `Wishlist`, `LessonQuestion`, `LessonQuestionReply`,
  `LessonQuestionVote`, `LessonNote`, `CourseReview`, `DirectMessage`,
  `BlockedUser`, `MessageReport`) are in `TenantModelRegistry::MODELS`
  with working `seedAttributes()`/`updateAttributes()` entries — each
  was registered incrementally as its own block built it (B0, B3, B7,
  B9, B10, B11, B12, B13, B14), so this block was a verification sweep,
  not new registration work.
  `tests/Isolation/CrossTenantTest.php` run directly: 46/46 passing (the
  registry-completeness guard test — fails if any `BelongsToTenant`
  model in `app/Domains/` is missing from the array — plus 45
  parametrized cross-tenant cases, one per registered model). No
  hand-written per-model isolation test exists anywhere in this slice,
  matching `CLAUDE.md`'s own rule.
- [x] `composer test`: 1192/1193 passing, unchanged from B15 (no new
  tests in a confirmation-only block). Same 1 pre-existing
  `CourseSlugUniquenessTest` failure, unrelated and untouched.

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
