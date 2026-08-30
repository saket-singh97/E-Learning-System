# Design: S23 Coaching-Market Differentiators

## 1. Why this document exists

`docs/ROADMAP.md` already pins S23's scope: 10 items, `U9-U18`, drawn from
`docs/FEATURES.md`. This document turns that pinned scope into concrete
schema/service/domain decisions before any block is built — same discipline
S13-S22 each applied. Build log lives in the sibling `...-plan.md`.

## 2. Scope

### 2.1 In scope — 10 items (U9-U18), all of them

Roadmap's own "Items: 10 | Draws from U9-U18" already settled this — no
further cutting needed, unlike slices where the headline undercounted or
double-counted (S18, S20). All 10 build this slice:

| Item | What | Tier |
|---|---|---|
| U9 | Confusion signal — rewatch heatmap + doubt volume + quiz failure rate → one ranked "re-teach this" list per batch | P2 |
| U10 | Syllabus tracker — lessons mapped to board/exam syllabus, "Physics 62% complete" | P1 |
| U11 | PYQ bank — previous-year questions tagged to topic | P2 |
| U12 | Scholarship test module — guest-facing acquisition test | P2 |
| U13 | Free demo class access for prospects + conversion tracking | P2 |
| U14 | Hall ticket / admit card generation | P3 |
| U15 | Rank card PDF — score/rank/percentile/subject breakdown | P2 |
| U16 | Backlog/catch-up mode — auto catch-up plan for a student behind schedule | P2 |
| U17 | Dual-language content tracks (Hindi/English) | P3 |
| U18 | Attendance-linked access rules (opt-in per institute) | P3 |

### 2.2 Verified before writing this design (checked, not assumed)

- `TestSeriesRankingService::rankingsFor(TestSeries, User)` (S16) already
  computes rank + percentile per participant — U15 reuses it directly, no
  new ranking math.
- `Question.concept` (S3, reused by S17's weak-topic detection) is the only
  topic taxonomy that exists — U11's "tagged to topics" and U15's "subject
  breakdown" both key off it. There is no separate `subject`/`board`/`exam`
  table anywhere in the schema.
- `CourseInterestSignup` (S20 R6, `app/Domains/Growth/Models/`) already
  captures a prospect (`name`/`email`/`phone`/`course_id`, no `user_id` —
  they aren't a `User` yet) with no attendance or demo concept. U13 extends
  it rather than building a second prospect-capture table.
- `RewatchHeatmapService::heatmapFor(Lesson)` (S18 A2), `DropOffService`
  (S18 A3), and `WeakTopicDetectionService` (S17 L13) are pure read-only
  query services over existing tables (`video_watch_events`, quiz answers)
  — U9 composes these three plus a `DoubtChatMessage` count-per-lesson
  query, adds no new table itself.
- `LessonCompletion` (S13 C8) is the per-lesson binary "done" signal already
  wired into `LessonAccess::canStream()` — U16's "behind schedule" reads
  the same signal against a batch's taught-lesson timeline (the same
  "taught so far" definition `DailyPracticeSetGenerator`, S22 B3, already
  established: a `BatchSession` with a non-null `lesson_id` on/before a
  reference date).
- `LessonAccess` (`app/Domains/Content/Services/LessonAccess.php`) is
  confirmed the single chokepoint for all lesson-visibility rules
  (CLAUDE.md's own documented rule) — U13's demo grant and U18's
  attendance gate both add a check *inside* this class, never inline in a
  controller.
- `IdCardGenerator` (S15) and `CertificateService` (S10) are the two
  existing `dompdf`-via-`Pdf::loadView()->save()` PDF generators with a
  persisted record + signed/gated download — U14 and U15 copy this exact
  shape rather than inventing a third PDF pipeline.
- No `subject`/`board`/`exam` taxonomy, no guest-quiz-attempt path (every
  existing `QuizAttempt` requires an authenticated enrolled `User`), no
  `language` column on `Course`, no attendance-based access gate anywhere
  today — all four are real, verified gaps, not assumptions.

### 2.3 Out of scope, and why

- **A second recommendation/ranking algorithm for U9.** It's a composition
  of three existing services' output, sorted — not a new model. If it
  under- or over-weights one signal vs another, that's a tuning pass after
  real usage, not a day-one requirement.
- **A real `subject`/`board`/`exam` taxonomy table.** `Question.concept`
  already does topic-tagging for every other AI/assessment feature in this
  codebase (S17 weak-topic, S22 DPP). Building a second, parallel taxonomy
  for PYQ/rank-card "subject breakdown" would fork topic-tagging into two
  incompatible systems for no stated requirement beyond a label. `concept`
  is reused as the subject axis everywhere in this slice.
- **AI-generated catch-up plans (U16).** The feature line says "auto-
  generated," not "AI-generated" — a deterministic ordered list of
  incomplete lessons (oldest-taught-first) is a catch-up plan. No AI call,
  no new provider interface, for a plan whose only job is "watch these,
  in this order."
- **A full guest-checkout/payment path off the scholarship test (U12).**
  The item is the acquisition test itself — score, rank, conversion
  *tracking*. Actually enrolling a scholarship winner still goes through
  the existing `EnquiryConversionService`/checkout paths (S15/S7); U12
  hands off to them, it doesn't reimplement them.

## 3. Architecture

### 3.1 Domain ownership — no new domain

Every item extends an existing domain; none needs a domain of its own
(same "no new domain" call S17/S18 made for a similarly domain-shaped
batch of items):

| Domain | Items | Why |
|---|---|---|
| `Content` | U10, U16, U17, U18 | Course/lesson mapping, completion tracking, `LessonAccess` gates — all already this domain's job |
| `Assessments` | U11, U14, U15 | `Question`/`TestSeries` extensions and their PDF outputs |
| `Growth` | U12, U13 | Prospect acquisition — same domain as S20's affiliate program and `CourseInterestSignup` |
| `Analytics` | U9 | Composes existing Analytics query services (S18), matches their pure-read-service shape |

### 3.2 New schema

```sql
-- U10 syllabus tracker (Content)
CREATE TABLE syllabus_topics (
    id BIGINT PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    course_id BIGINT NOT NULL,
    board_or_exam VARCHAR(255) NOT NULL,   -- e.g. "CBSE Class 10", "JEE Main"
    title VARCHAR(255) NOT NULL,
    sequence INT NOT NULL,
    created_at, updated_at
);
CREATE TABLE lesson_syllabus_topic (        -- plain pivot, no model
    lesson_id BIGINT NOT NULL,
    syllabus_topic_id BIGINT NOT NULL,
    PRIMARY KEY (lesson_id, syllabus_topic_id)
);

-- U11 PYQ bank (Assessments) — columns on the existing table, no new table
ALTER TABLE questions ADD COLUMN is_pyq BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE questions ADD COLUMN pyq_exam_name VARCHAR(255) NULL;
ALTER TABLE questions ADD COLUMN pyq_exam_year SMALLINT NULL;

-- U12 scholarship test (Growth)
CREATE TABLE scholarship_tests (
    id BIGINT PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    course_id BIGINT NULL,                  -- optional: test tied to a specific course's catalog page
    title VARCHAR(255) NOT NULL,
    quiz_id BIGINT NOT NULL,                -- reuses existing Quiz/Question engine
    opens_at, closes_at TIMESTAMP NOT NULL,
    created_at, updated_at
);
CREATE TABLE scholarship_test_registrations (
    id BIGINT PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    scholarship_test_id BIGINT NOT NULL,
    name, email, phone VARCHAR(255) NOT NULL,   -- prospect, no user_id — same shape as CourseInterestSignup
    score DECIMAL(6,2) NULL,
    converted_enrolment_id BIGINT NULL,          -- set by U13's conversion check, nullable
    created_at, updated_at,
    UNIQUE (scholarship_test_id, email)
);

-- U13 demo class + conversion tracking
ALTER TABLE lessons ADD COLUMN is_demo BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE course_interest_signups ADD COLUMN demo_attended_at TIMESTAMP NULL;

-- U14 hall ticket (Assessments) — persisted like Certificate/IdCard
CREATE TABLE hall_tickets (
    id BIGINT PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    scholarship_test_registration_id BIGINT NOT NULL,
    roll_number VARCHAR(20) NOT NULL,
    pdf_path VARCHAR(255) NOT NULL,
    created_at, updated_at,
    UNIQUE (scholarship_test_registration_id)
);

-- U15 rank card (Assessments)
CREATE TABLE rank_cards (
    id BIGINT PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    test_series_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    pdf_path VARCHAR(255) NOT NULL,
    created_at, updated_at,
    UNIQUE (test_series_id, user_id)
);

-- U17 dual-language tracks (Content)
ALTER TABLE courses ADD COLUMN language VARCHAR(10) NOT NULL DEFAULT 'en';
ALTER TABLE courses ADD COLUMN translation_of_course_id BIGINT NULL;  -- self-referencing, nullable

-- U18 attendance-linked access (Content/Tenancy)
ALTER TABLE tenants ADD COLUMN attendance_linked_access_enabled BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE batches ADD COLUMN min_attendance_percent SMALLINT NULL;  -- null = no threshold set
```

U16 (backlog/catch-up) and U9 (confusion signal) add **no schema** — both
are pure query services over existing tables, matching `RewatchHeatmapService`/
`DropOffService`'s own no-new-table precedent.

### 3.3 New `BelongsToTenant` models (all registered in `TenantModelRegistry`)

`SyllabusTopic`, `ScholarshipTest`, `ScholarshipTestRegistration`,
`HallTicket`, `RankCard` — 5 new models. `lesson_syllabus_topic` stays a
bare pivot table (no model), matching `Category`/`Tag` pivots (S13).

## 4. Design decisions

1. **U11's "topic" and U15's "subject breakdown" both key off `Question.concept`.**
   One taxonomy, reused a third time (S17 weak-topic, S22 DPP, now this) —
   not a new `subjects` table. A rank card's subject breakdown groups a
   test series's questions by `concept` and shows per-concept score.

2. **U12's scholarship test reuses the `Quiz`/`Question` attempt engine but
   needs a genuinely new "no `User` row yet" attempt path.** Every existing
   `QuizAttempt` FK's to an authenticated enrolled `User`. Rather than
   relaxing that FK (used by every other assessment feature and its own
   grading/analytics), `ScholarshipTestRegistration` stores the score
   directly (`DECIMAL(6,2)`, no attempt-row breakdown) computed by a
   `ScholarshipTestGradingService` that grades the submitted answers
   in-memory against the linked `Quiz`'s questions without ever creating a
   `QuizAttempt`. Keeps the trusted enrolled-attempt path untouched.

3. **U13's conversion tracking is a query, not an event.** "Did this
   prospect convert" is answered on read (`CourseInterestSignup::email`
   matched against `Enrolment` via the enrolled `User`'s email, scoped to
   the same course/tenant) inside a `DemoConversionService`, not by a
   listener writing a `converted_at` column — a prospect can convert weeks
   later through any of this app's several enrolment paths (checkout,
   admissions-CRM `EnquiryConversionService`, bulk CSV import), and wiring
   a listener onto all of them for one derived boolean is the kind of
   "three copies of a check" S20's own deferred-debt list already warns
   against repeating.

4. **U18's attendance gate is tenant-opt-in, batch-configured, and lives in
   `LessonAccess::canStream()`.** Same two-key shape S20's fee-based
   restriction used (`tenants.fee_grace_period_days` unlocks the feature,
   the enrolment/batch carries the actual number) — a tenant that never
   flips `attendance_linked_access_enabled` sees zero behavior change, and
   a batch with no `min_attendance_percent` set is exempt even when the
   tenant has it on. Attendance percent is computed from the existing
   `AttendanceRecord` table, no new counter.

5. **U17's dual-language track is a self-reference, not a locale system.**
   `translation_of_course_id` links two independently-authored `Course`
   rows (their own sections/lessons, not shared/translated content rows) —
   this app has no i18n string infrastructure, and building one for course
   *content* (which is instructor-authored per language anyway) is a
   different, much larger project than "let two courses point at each
   other and let the catalog filter/switch between them." `CourseCatalog`
   (S13) gains a `language` filter; a course detail page shows a
   "Available in: Hindi / English" switcher when `translation_of_course_id`
   is set either direction.

6. **U9's confusion signal is a per-batch-per-lesson ranked list, computed
   on request, not swept/cached.** Same posture `RewatchHeatmapService`/
   `DropOffService` already take — these are teacher-facing widgets loaded
   a handful of times a day per batch, not a hot path. Add caching later
   if a real teacher-panel load pattern demands it (S12's `AggregateCache`
   is the existing mechanism if so — not built preemptively here).

7. **U16's catch-up plan is deterministic, ordered by the batch's own
   taught sequence.** "Behind schedule" = lessons already taught to the
   student's batch (`BatchSession.lesson_id` on/before today, same
   definition as S22 B3) where `LessonCompletion` has no row for that
   student. The plan is that list, oldest-first. No pacing algorithm, no
   AI call — reopens as AI-assisted pacing only if plain "watch these in
   order" proves insufficient in practice.

8. **U14/U15 PDF generation copies `IdCardGenerator`'s exact shape**:
   `dompdf` via `Pdf::loadView()->save()`, one persisted row keyed unique
   on its natural parent (`scholarship_test_registration_id` /
   `(test_series_id, user_id)`), generated on demand not auto-swept, a
   `DB::transaction()`+`lockForUpdate()` guard against the same
   check-then-write double-click race `IdCardGenerator` was hardened
   against in S15 B21 — not re-discovering that bug a second time.

## 5. Testing strategy

Same shape every S13-S22 slice used: one feature test per new service/
generator (happy path + the one real edge case each design decision above
implies — e.g. U18's "tenant on, batch threshold unset" exemption; U13's
"prospect enrols under a different email" non-match), a `TenantModelRegistry`
reverse-sweep block, and a closing lifecycle E2E test chaining at least:
scholarship test registration → score → rank card + hall ticket generated →
conversion tracked once a real enrolment lands; syllabus topic mapped →
tracker % updates as lessons complete; attendance-gated batch blocks a
low-attendance student, ungated batch does not.
