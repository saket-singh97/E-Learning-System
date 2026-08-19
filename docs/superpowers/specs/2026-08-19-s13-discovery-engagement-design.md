# Design: S13 Discovery & Content Engagement — AI-Native LMS

## 1. Why this document exists

Locks scope and architecture for S13 before any block is built, same role
S9-S12's own design docs played. Full block-by-block build log lives in the
companion `2026-08-19-s13-discovery-engagement-plan.md`.

S13 is the fifth P2-backlog slice (after S9 hardening, S10 assignments/
gradebook/certificates, S11 billing, S12 security/resilience). Scope per
`docs/ROADMAP.md`'s own S13 row: `docs/FEATURES.md` items C7-C10 (catalog/
discovery) + N7-N10 (engagement) — 8 items total, matching the roadmap's
own count.

---

## 2. S13 scope

### 2.1 In scope

| # | Feature | Source |
|---|---|---|
| C7 | Categories, subcategories, tags; catalog filtering | REQ-48, FRP |
| C8 | Course prerequisites + sequential lesson unlocking + circular-dependency check | REQ-49 |
| C9 | **Programs** — multi-course bundles | FRP |
| C10 | Course catalog, search, wishlist, recommendations | REQ-41 |
| N7 | Lesson-level Q&A with upvoting and resolved marking | REQ-39 |
| N8 | Timestamped notes + bookmarks on video | REQ-38, FRP |
| N9 | Direct messaging (1:1, tenant-scoped, block/report) | REQ-53 |
| N10 | Course ratings + reviews with instructor response + moderation | REQ-40, FRP |

### 2.2 Verified current state (read before writing this, not assumed)

All 8 items are **fully greenfield** — grepped the whole app for every
plausible existing hook (`categor`, `tag`, `prerequisite`, `program`,
`wishlist`, `bookmark`, `rating`, `review`, a `message`/chat model distinct
from WhatsApp sending) and found nothing but false positives (see this
design's own build notes for the exact grep results). Specifically:

- **`courses` table today**: `id`, `tenant_id`, `title`, `slug`,
  `description`, `status`, `gradebook_weights`, `passing_threshold`,
  timestamps, `deleted_at`. No category/tag/prerequisite column of any
  kind.
- **`LessonAccess`** (`app/Domains/Content/Services/LessonAccess.php`) —
  the one place lesson visibility is decided (per `CLAUDE.md`) — gates
  purely on enrolment/role, publish status + drip date, and content
  readiness. No ordering/unlock/prerequisite concept exists anywhere on
  `Lesson`/`CourseSection`/`Course`.
- **No student-facing catalog page exists at all.** `routes/content.php`
  only exposes single-resource routes (`/watch/{lesson}`,
  `/lessons/{lesson}`, `/courses/{course}` — a single course's syllabus,
  `/live-sessions/{session}/join`). No listing/index route, no search, no
  wishlist. `app/Livewire/StudentPortal.php` is a login component only.
- **`DoubtChatMessage` (S3) is AI-only, not human Q&A.** One user's
  question + one AI answer, `cited_chunk_idxs`, `token_cost` — no
  upvote/resolved/second-human-party concept. A genuinely separate
  feature, not an extension of it.
- **No notes/bookmark model or UI anywhere** — `LessonPlayer.php` has zero
  matches for either term today.
- **`app/Domains/Comms/Models/`** holds only `ParentGuardianLink` and
  `ParentInviteCode` — no user-to-user chat model. Existing "message"
  hits are WhatsApp/notification *sending* (`SendAbsenceAlert`), not a
  chat surface.
- **No Rating/Review model anywhere.**

Nothing to reconcile with prior work — every block below is new schema,
new models, new UI, built directly against the current, verified-empty
state above.

### 2.3 Out of scope, and where each goes

- **Recommendations beyond same-category heuristics** — a real
  behavioural recommender ("students who enrolled in X also enrolled in
  Y") needs enrolment-pattern volume this platform doesn't have yet with
  zero production traffic; C10's own recommendation slice here is
  same-category / same-tag published courses, excluding ones the viewer
  is already enrolled in. Flagged explicitly as an MVP heuristic, not
  glossed over as "smart" — a real ML-backed recommender is a later,
  separate item once there's real usage data to train on.
- **Full-text search infra (Postgres `tsvector`/Elasticsearch/Scout)** —
  V5's own "Eloquent-only, no raw SQL" baseline (`SecurityBaselineTest`)
  is a standing constraint on every domain, not just S12's own scope; C10
  search is `LIKE`/`orWhere` across `title`/`description` via Eloquent,
  not `whereRaw`/`DB::raw`. A real trigram/full-text index is a
  perf-driven upgrade for whenever query volume actually demands it — no
  signal for that yet.
- **Group/multi-party messaging** — N9 says "1:1" explicitly; a forum/
  group-chat surface is S19's own "Communication Platform" slice
  (`docs/ROADMAP.md`), not duplicated here.
- **A dedicated moderation dashboard as a dashboard-shaped Filament
  resource beyond the two review queues this slice needs** (reported
  messages, hidden reviews) — matches S12's own "a status widget is
  enough, don't over-build a CRUD surface for visibility alone" posture
  where it applies; here the queues *are* the CRUD surface (an admin
  genuinely acts on each row), so this is the minimum, not a shortcut.
- **Real-time delivery (WebSockets/Pusher/Reverb) for direct messaging or
  Q&A** — S19's own "real-time WebSockets" line item is the general
  real-time infra slice; N9/N7 here ship as ordinary Livewire
  polling/page-load reads, same posture the existing `DoubtChat` UI
  already uses successfully.

---

## 3. Schema

### 3.1 Extends the existing `Content` domain

Every model below except direct messaging lives in
`app/Domains/Content/{Models,Services,Filament,Livewire}/` — all of it is
fundamentally "how a student finds and engages with a course/lesson,"
the same concern `Course`/`Lesson`/`VideoWatchEvent`/`AttendanceRecord`
already live under. Deliberately **not** a new domain: unlike `Billing`
(S11) or `Security` (S12), which are cross-cutting enforcement layers
genuinely orthogonal to any one domain's own business logic, discovery
and engagement *are* Content's own business logic — they don't watch or
gate Content from the outside, they extend what a course/lesson visibly
offers.

```
categories
  id, tenant_id, name, slug, parent_id (nullable, self-referential —
  subcategories), timestamps

course_category (pivot)
  course_id, category_id

tags
  id, tenant_id, name, slug, timestamps

course_tag (pivot)
  course_id, tag_id

lesson_prerequisites (pivot, self-referential on lessons)
  lesson_id, prerequisite_lesson_id

programs
  id, tenant_id, title, slug, description (nullable), price,
  status (draft|published), timestamps, deleted_at (SoftDeletes —
  matches V3's own posture on anything a learner has paid into)

program_batch (pivot — bundles specific sellable Batches, not raw
  Courses; see decision 3 for why)
  program_id, batch_id

wishlists
  id, tenant_id, user_id, course_id, timestamps

lesson_questions
  id, tenant_id, lesson_id, user_id, body, resolved_at (nullable),
  timestamps, deleted_at (SoftDeletes — a learner can retract a question)

lesson_question_replies
  id, tenant_id, lesson_question_id, user_id, body, timestamps,
  deleted_at

lesson_question_votes
  id, tenant_id, lesson_question_id, user_id, timestamps
  (unique on lesson_question_id + user_id — one upvote per user per
  question)

lesson_notes
  id, tenant_id, user_id, lesson_id, timestamp_s (int), body (nullable —
  null means a pure bookmark marker, non-null means an annotated note;
  see decision 6 for why one table, not two), timestamps

course_reviews
  id, tenant_id, course_id, user_id, rating (tinyint 1-5),
  body (nullable), instructor_response (nullable),
  instructor_response_at (nullable), status (published|hidden — default
  published, moderation flag), timestamps
  (unique on course_id + user_id — one review per learner per course)
```

### 3.2 New `direct_messages` surface, in the existing `Comms` domain

```
direct_messages
  id, tenant_id, sender_id, recipient_id, body, read_at (nullable),
  timestamps

blocked_users
  id, tenant_id, user_id, blocked_user_id, timestamps
  (unique on user_id + blocked_user_id)

message_reports
  id, tenant_id, reporter_id, direct_message_id, reason, status
  (open|actioned|dismissed — default open), timestamps
```

Lives in `Comms`, not `Content` — matches that domain's own existing
job ("communication between people"), not a course/lesson concern the
way every 3.1 model is.

### 3.3 Modified existing tables

- No columns added to `courses`/`lessons`/`users` directly — every new
  concern here is its own table or a pivot, matching `HANDOVER.md`'s own
  standing "don't bolt domain-specific concerns onto shared models" note
  (the same rule S12 §3.2 already followed for `User`).

### 3.4 New `BelongsToTenant` models (all registered in `TenantModelRegistry`)

`Category`, `Tag`, `Program`, `Wishlist`, `LessonQuestion`,
`LessonQuestionReply`, `LessonQuestionVote`, `LessonNote`,
`CourseReview`, `DirectMessage`, `BlockedUser`, `MessageReport` — 12
new tenant-scoped models, the largest single-slice count so far (S12 had
5). `course_category`/`course_tag`/`lesson_prerequisites`/`program_batch`
are plain pivots, no model needed (matches how every other many-to-many
in this app is wired — `assignment_submissions` aside, which is a real
model for other reasons).

---

## 4. Design decisions

1. **Categories are self-referential (`parent_id`), not a separate
   `subcategories` table.** One table answers both C7 requirements
   ("categories, subcategories") with one extra nullable column, same
   shape `RoleHierarchy`-adjacent trees in this app already avoid
   over-modelling. A category with `parent_id === null` is top-level; a
   catalog filter can match either the exact category or (optionally)
   roll up to its parent.
2. **Tags are freeform, per-tenant, not a fixed enum.** Matches the
   "each institute defines its own catalog" posture this whole app
   already takes for `Category`/`Plan`/every other institute-configurable
   list — no platform-wide tag vocabulary to govern.
3. **Programs bundle `Batch` rows, not `Course` rows.** This app's real
   sellable, enrollable unit is already `Batch` (that's what
   `Enrolment`/`FeeInvoice` attach to, not `Course` directly — confirmed
   by re-reading S4's own design). A "buy this bundle" purchase has to
   resolve to real batch enrolments on payment success; bundling
   `Course` IDs would leave "which batch of that course?" unanswered at
   checkout. `program_batch` names the exact sellable batches a program
   grants access to, so B8's purchase flow is a straight loop creating
   one `Enrolment` per bundled batch — no new resolution logic needed.
4. **Program purchase reuses the existing Fees/Razorpay flow, not a new
   payment surface.** One `FeeInvoice` for the bundle price (a new
   nullable `program_id` FK on `fee_invoices`, matching how every other
   optional linkage in this schema is modelled — nullable FK, not a
   polymorphic column); on payment success, `ProgramEnrolmentService`
   loops the bundle's batches and creates one `Enrolment` each, mirroring
   `EnrolmentService`'s own single-batch path but batched. Explicitly
   **not** a partial-refund/partial-bundle-cancel feature — decision 12
   (S11's own precedent) already establishes `cancelSubscription`-style
   destructive actions get their own dedicated design later; this slice
   ships purchase, not bundle-level cancellation.
5. **Prerequisite completion is a per-lesson-type heuristic, not a
   unified progress percentage.** No existing "is this lesson complete"
   concept spans all 4 lesson types today; building a real weighted
   progress engine is a bigger, separate item. This slice's own
   `LessonCompletion` check is type-dispatched and binary: `video` — at
   least one `VideoWatchEvent` reaching within 90% of
   `video_duration_seconds`; `quiz` — at least one non-`in_progress`
   `QuizAttempt`; `assignment` — at least one `AssignmentSubmission`;
   `text`/`live_session` — has a `VideoWatchEvent`/`AttendanceRecord` row
   at all (attendance-based, since there's nothing to "finish"
   otherwise). Documented as a real, deliberate simplification, not
   silently approximate.
6. **Circular-dependency check runs at write time, not read time.**
   Setting Lesson A's prerequisite to Lesson B walks B's own prerequisite
   chain (a bounded DFS, capped at the course's own lesson count so it
   can't infinite-loop even on bad data) before the pivot row is written;
   a cycle throws `CircularPrerequisiteException` rather than silently
   saving. Cheaper to reject the one bad edit than to detect and untangle
   a cycle later during unlock evaluation on every single page load.
7. **One `lesson_notes` table serves both notes and bookmarks** — a
   `body === null` row is a pure timestamp marker ("bookmark this
   moment"), a non-null `body` is an annotated note. Splitting into two
   tables would duplicate the exact same `user_id`/`lesson_id`/
   `timestamp_s` shape for zero real behavioural difference; the
   `LessonPlayer` timeline renders both as clickable seek-to markers
   (reuses the exact Alpine.js `dispatch`-based seek pattern `DoubtChat`
   already established for "click a citation, jump the video there" —
   no new player-side mechanism needed).
8. **Lesson Q&A is visible to the same audience `LessonAccess` already
   gates the lesson to** — no separate visibility rule invented; a
   question thread reuses `LessonAccess::canStream()`'s own verdict
   rather than re-deriving who can see a lesson (the exact "one place
   answers this" rule `CLAUDE.md` already states for `LessonAccess`).
   Resolved-marking is instructor/admin-only (mirrors how `AssignmentSubmission`
   grading is instructor/admin-gated); upvoting is any viewer with
   access, one vote per user per question (unique constraint, not an
   app-level check alone — the same defense-in-depth
   `AssignmentSubmission`'s own idempotency key already models).
9. **Direct messaging blocks are symmetric at read time, not
   write-enforced both directions.** `blocked_users` stores one directed
   row (blocker → blocked); the send-path checks both directions (either
   party blocking the other silences the thread) rather than writing two
   rows per block action — one row, one source of truth for "who did the
   blocking," still fully bidirectional in effect.
10. **Message reports feed a Admin-panel moderation queue, not an
    automated takedown.** A reported message doesn't auto-hide — matches
    this app's existing "flag, don't auto-act" posture for anything a
    false positive would wrongly punish a real user for
    (`AccountSharingFlag`, S12 B4, forces re-auth rather than locking the
    account on a mere flag). An admin actions the report
    (`status: actioned` hides the message / `dismissed` clears the flag).
11. **Course reviews default to visible; moderation is hide-not-delete.**
    `status: hidden` is a soft moderation flag, not a destructive delete —
    same reasoning `SoftDeletes` already uses everywhere in this app: an
    admin's moderation call should be reversible, not a hard loss of the
    learner's original review text.
12. **A review requires (past or current) enrolment in the course** —
    same "genuine participant only" gate `AssignmentSubmission`/
    `QuizAttempt` already imply implicitly (you can't submit what you're
    not enrolled to see); `CourseReviewPolicy` checks `Enrolment::query()`
    exists for that user + any batch of that course before allowing
    `store()`, not scoped to only the *current* batch (a past learner
    from an earlier batch of the same course is still a real reviewer).

---

## 5. Testing strategy

- **Unit/feature per service**: `CatalogSearchService`, `PrerequisiteGraph`
  (the circular-dependency check gets its own dedicated test file, same
  "state-machine logic gets isolated tests" posture `CircuitBreaker`
  (S12) already modelled), `LessonCompletion`, `ProgramEnrolmentService`,
  `DirectMessageService` each get a dedicated test file, one-file-per-unit
  convention every prior slice used.
- **Cross-tenant isolation**: all 12 new `BelongsToTenant` models register
  in `TenantModelRegistry`, auto-generating `CrossTenantTest` coverage —
  no hand-written per-model isolation tests (`CLAUDE.md`'s own rule).
- **Circular-dependency check**: a dedicated test builds a 3-lesson chain
  (A→B→C) then asserts attempting C→A throws, and a legitimate
  non-cyclic 4th edge still saves — proving the DFS rejects only real
  cycles, not just any second prerequisite.
- **Prerequisite unlock gate**: one test per lesson type proving
  `LessonCompletion`'s own binary heuristic (watched-90%/attempted/
  submitted/attended) actually flips `LessonAccess::canStream()` on the
  *next* lesson from denied to allowed — not just that the completion
  flag itself is correct in isolation.
- **Program purchase**: an end-to-end test (webhook → `FeeInvoice` paid →
  `ProgramEnrolmentService` fires) asserting every bundled batch gets
  exactly one `Enrolment`, not zero and not a duplicate on a retried
  webhook (idempotency, same discipline `PaymentReconciler`/
  `SubscriptionReconciler` already established).
- **Moderation**: a reported message stays visible until an admin
  actions it (`status: actioned` only then hides it) — proving decision
  10's "flag, don't auto-act" isn't just asserted in a comment.
- **Block symmetry**: one test proving a message send is silenced
  regardless of *which* of the two parties placed the block — decision
  9's own read-time symmetry, not just the write path.
- Design doc §5 estimate (a rough anchor, not a target, same posture
  every prior slice's own estimate takes): ~90-110 new tests across
  ~18 blocks — the largest single-slice model count so far (12 new
  tenant-scoped models vs S12's 5) makes this the widest surface yet,
  even though most individual pieces are small.

---

## 6. Open questions, locked with a default rather than left blocking

- **Program bundle discount vs. sum-of-batch-prices** — defaulted to a
  single, independently-set `programs.price` field (an admin sets the
  bundle price directly, not a computed discount off the sum) — simplest
  model, matches `Plan.price_inr_per_month`'s own "admin sets the number"
  precedent rather than inventing a discount-percentage engine nobody
  asked for yet.
- **90% watched-threshold for video-lesson completion** — defaulted to
  90% (a config value, `content.lesson_completion_watch_threshold`, not
  hardcoded) since a learner who watched to 100% but paused 3 seconds
  before the credits shouldn't be blocked from the next lesson; tunable
  without a redeploy, same posture every other threshold in this app
  (`security.device_limit`, etc.) already takes.
- **Wishlist limit** — defaulted to unlimited (no cap) — a wishlist is a
  low-stakes, low-abuse-risk feature; a cap would need its own
  justification this slice doesn't have one for.
