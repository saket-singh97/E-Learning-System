# Design: S6 Assessments — AI-Native LMS

Date: 2026-08-11
Status: APPROVED
Scope: Sub-project 6 of 8 in the P1 build (Institute Zero)
Window: 2026-08-11 → 2026-10-31 (demo #6 date)
Depends on: S1 Foundation (`Lesson.type` enum already includes `quiz`), S3 AI Spine (`AiProvider`, `lesson_artifacts.seed_questions` — populated, unread), S4 Batches+Enrolment (roster/enrolment reads), S5 Live Classes (syllabus tree — S6 is its second per-type consumer)
Feeds: S8 Comms (quiz-published / result-ready notifications read from here)

---

## 1. Why this document exists

`docs/PROJECT-STATUS.md`'s roadmap commits S6 to one demo on 2026-10-31: **"A
question paper generated from the teacher's own notes."** `docs/FEATURES.md`
§I (Assessments) and §L (AI Layer) between them already pinned two pieces of
S6's contract before any S6 code existed:

- **`Lesson::TYPES` already includes `'quiz'`** (S1) — schema pinned, zero
  content wired. Same situation `live_session` was in before S5.
- **`lesson_artifacts.seed_questions` already exists and is already
  populated** — `ExtractLessonConcepts` (S3 Block 3) writes it on every
  successful ingestion (`app/Domains/AI/Jobs/ExtractLessonConcepts.php:46`),
  shaped `{type, stem, options, answer, explanation, concept, difficulty}`
  (`FakeAiDriver::fakeValueFor()`, `app/Integrations/Drivers/FakeAiDriver.php:139-147`).
  Nothing has ever read this column. Same shape of gap `attendance_records`
  was in before S5 — built, unused, waiting for its slice.

This document turns those two fragments plus six scope decisions (§2.4) into
a schema, service contracts, and a block-by-block build order, the same way
the S1–S5 design docs did for their slices.

---

## 2. S6 scope

### 2.1 In scope (P1 — required for the 2026-10-31 demo)

| Feature | FEATURES.md ref | What it means concretely |
|---|---|---|
| Question bank | I1 | MCQ, true/false, short-answer, numerical; per-course, manually authored in `/teach` |
| AI question paper generation | L5 | Blueprint (topic/difficulty/count) → `AiProvider::chat()` drafts questions, seeded from `lesson_artifacts.seed_questions` where available |
| Review/edit/approve gate | L6 | A `draft` question never reaches a student; only `approved` rows are ever drawn into an attempt |
| Randomised, non-repeating draw | I2 | Each attempt draws its own set from the approved pool per the quiz's blueprint; excludes the student's immediately-previous attempt's set where the pool allows it |
| Time + attempt limits, auto-submit | I3 | 1–180 min time limit, 1–10 attempts; a scheduled sweep force-submits anything still `in_progress` past `expires_at` |
| Auto-grading | I4 | MCQ/true-false/numerical graded instantly on submit; explanation shown with the result |
| Short-answer review queue | I5 | Graded `pending_review`, not auto-scored; instructor assigns partial credit |
| Numerical questions | I7 | Answer + tolerance, same auto-grade path as MCQ |
| Quiz performance trend | M9 | A student's own score sequence across their attempts on one quiz — raw history, not a dashboard |

### 2.2 Out of scope, and where each goes

| Feature | FEATURES.md ref | Why deferred | Lands in |
|---|---|---|---|
| Extended question types (multi-select, fill-blank, matching, ordering) | I6 | P2 in FEATURES.md | Fast-follow |
| Proctoring (tab-switch, fullscreen, copy-paste block) | I8, I9 | P3 | Fast-follow |
| Test series, All-India rank, topper comparison | I10 | P2, needs a cross-institute leaderboard this app has no infra for | Fast-follow |
| OMR scanning for offline tests | I11 | P3, needs image-processing infra | Fast-follow |
| Assignments + gradebook (§J), certificates (§K) | J1-J8, K1-K5 | Not on the 8-slice P1 roadmap at all — no S-number owns them | Beyond Institute Zero, unscheduled |
| Instructor-facing dashboards (score/coverage sortable roster) | M11 | Broader analytics surface, same "basic only" call S5 made for H8 | Fast-follow (candidate: S8) |
| Real AI vendor for question generation | — | No vendor account exists yet — same posture every prior slice shipped with (`FakeAiDriver` only) | Fast-follow, once an account exists |

### 2.3 Reviewed before writing this: what S1–S5 already promised toward S6

- **`Lesson::TYPES` includes `'quiz'`** (`app/Domains/Content/Models/Lesson.php:19`,
  S1) — verified 2026-08-11: no quiz-specific code exists anywhere in
  `app/Domains/Content/` beyond the enum value.
- **`lesson_artifacts.seed_questions` is real, populated, unread** (verified
  above, §1) — S6 is its first consumer.
- **`AiProvider::chat()` already supports structured output**
  (`responseSchema` param, `app/Integrations/AiProvider.php:20`) — the same
  mechanism `ExtractLessonConcepts` already uses to produce `seed_questions`.
  S6's paper-generation call reuses this verbatim; no new AI plumbing.
- **The syllabus tree already branches per `Lesson::type`** (S5 B2,
  `app/Livewire/SyllabusTree.php`) — currently one branch (`live_session`)
  plus a generic fallback. S6 is its second per-type consumer (`quiz`), not
  a new tree.
- **Cross-domain model reads are established precedent** (S4 §3.0, reused by
  every slice since) — a new `Assessments` domain reading `Content\Lesson`
  and `Batches\Enrolment` directly is the same shape `BatchSession belongsTo
  Lesson` and `LessonAccess` reading `Enrolment` already are, not a new
  pattern.
- **Scheduled, tenant-unbound daily jobs are established precedent** (S4 B5
  `ExpirePendingEnrolments`, S5 B7 `RecordVideoCoverageAttendance`) — S6's
  auto-submit sweep is a third instance of the same shape, including the
  same `withoutEvents()` requirement if it ever needs to *create* a
  tenant-scoped row with no `CurrentTenant` bound (it doesn't — it only
  updates existing `quiz_attempts` rows, so no new wrinkle here).

### 2.4 Decided (2026-08-11, before Block 0)

1. **New `app/Domains/Assessments/` domain.** `Question`/`Quiz`/`QuizAttempt`/
   `QuizAnswer` are a new aggregate-root family, not a natural extension of
   Content or Batches — same justification S4 used for introducing
   `Batches`. No domain currently owns "a graded attempt at a set of
   questions."
2. **A quiz anchors to a `quiz`-type `Lesson`** (`Quiz.lesson_id`, not
   `Lesson.quiz_id`) — same topic-anchor shape S5 chose for
   `BatchSession.lesson_id`, for the same reason: the question bank and
   quiz belong to a course generally, but a specific *quiz instance* is one
   topic node in the syllabus tree.
3. **A quiz attempt's question set is drawn fresh per attempt, not fixed on
   the quiz.** `Quiz` stores a blueprint (topic/difficulty/type/count);
   `QuizAttempt.question_ids` (JSON, ordered) is the actual drawn set,
   decided at attempt-start. This is what I2's "no two consecutive
   identical sets" actually requires — a fixed quiz→question list can't
   satisfy it.
4. **AI generation reuses `seed_questions` first, falls back to a fresh
   `chat()` call.** Cheaper and grounded in the lesson's own transcript
   (already extracted); a fresh call only fires when the teacher's
   blueprint asks for more/different questions than the seeded seed_questions
   already covers.
5. **Auto-submit is a daily scheduled sweep, not a per-attempt queued job.**
   Matches `ExpirePendingEnrolments`/`RecordVideoCoverageAttendance`
   exactly — no new infra shape introduced for S6.
6. **Assignments/gradebook/certificates (§J, §K) are not S6.** They don't
   appear anywhere in the 8-slice P1 roadmap; conflating them with
   Assessments would blow S6's scope past what the milestone commits to.

---

## 3. Architecture

### 3.0 Where this lands

```
app/Domains/Assessments/
  Models/
    Question.php              type, stem, options, correct_answer, explanation,
                               difficulty, concept, status (draft|approved), source (manual|ai_generated)
    Quiz.php                  lesson_id, blueprint (json), time_limit_minutes, max_attempts, status
    QuizAttempt.php            quiz_id, user_id, question_ids (json), status, started_at, expires_at, submitted_at, score
    QuizAnswer.php             quiz_attempt_id, question_id, response, is_correct, score, status, reviewed_by
  Services/
    QuestionGenerator.php      AiProvider::chat() wrapper — seed_questions-first, blueprint-driven
    QuizAttemptService.php     start/draw, submit, auto-grade
  Filament/
    Resources/Questions/       /teach question bank CRUD + AI-generate action + approve gate
    Resources/Quizzes/         /teach quiz (blueprint) CRUD
  Jobs/
    AutoSubmitExpiredQuizAttempts   daily sweep, same shape as ExpirePendingEnrolments

app/Livewire/
  QuizRunner.php               student-facing attempt UI — reached from the syllabus tree's quiz rows

app/Http/Controllers/Assessments/
  ShowQuizController.php       gate + render, same shape as ShowSyllabusController
```

`Question`/`Quiz` read `Content\Lesson` directly (course scoping, topic
anchor) and `Batches\Enrolment` directly (attempt eligibility) — both
already-established cross-domain-model-read precedent, no new pattern.
`SyllabusTree` gains a third branch (`quiz`) alongside `live_session` and the
generic fallback.

### 3.1 Schema

```sql
CREATE TABLE questions (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT NOT NULL REFERENCES tenants(id),
  course_id BIGINT NOT NULL REFERENCES courses(id),
  lesson_id BIGINT NULL REFERENCES lessons(id) ON DELETE SET NULL,  -- which topic seeded it, if any
  type VARCHAR NOT NULL,             -- mcq | true_false | short_answer | numerical
  stem TEXT NOT NULL,
  options JSON NULL,                 -- mcq/true_false only
  correct_answer JSON NOT NULL,      -- string, or {value, tolerance} for numerical
  explanation TEXT NULL,
  difficulty VARCHAR NOT NULL,       -- easy | medium | hard
  concept VARCHAR NULL,
  status VARCHAR NOT NULL DEFAULT 'draft',   -- draft | approved
  source VARCHAR NOT NULL,           -- manual | ai_generated
  created_by BIGINT NULL REFERENCES users(id),
  timestamps, soft deletes
);

CREATE TABLE quizzes (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT NOT NULL REFERENCES tenants(id),
  lesson_id BIGINT NOT NULL REFERENCES lessons(id),   -- the quiz-type topic anchor
  title VARCHAR NOT NULL,
  blueprint JSON NOT NULL,           -- [{concept|difficulty|type, count}, ...]
  time_limit_minutes INT NOT NULL,   -- 15-480 range, validated 1-180 per I3
  max_attempts INT NOT NULL,         -- 1-10
  status VARCHAR NOT NULL DEFAULT 'draft',   -- draft | published
  timestamps
);

CREATE TABLE quiz_attempts (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT NOT NULL REFERENCES tenants(id),
  quiz_id BIGINT NOT NULL REFERENCES quizzes(id),
  user_id BIGINT NOT NULL REFERENCES users(id),
  question_ids JSON NOT NULL,        -- ordered array, drawn at start
  status VARCHAR NOT NULL,           -- in_progress | submitted | auto_submitted
  started_at TIMESTAMP NOT NULL,
  expires_at TIMESTAMP NOT NULL,     -- started_at + quiz.time_limit_minutes
  submitted_at TIMESTAMP NULL,
  score DECIMAL NULL,                -- null until every answer is graded
  max_score DECIMAL NOT NULL,
  timestamps
);

CREATE TABLE quiz_answers (
  id BIGINT PRIMARY KEY,
  tenant_id BIGINT NOT NULL REFERENCES tenants(id),
  quiz_attempt_id BIGINT NOT NULL REFERENCES quiz_attempts(id),
  question_id BIGINT NOT NULL REFERENCES questions(id),
  response JSON NULL,                -- null = unanswered
  is_correct BOOLEAN NULL,
  score DECIMAL NULL,
  status VARCHAR NOT NULL,           -- auto_graded | pending_review | reviewed
  reviewed_by BIGINT NULL REFERENCES users(id),
  reviewed_at TIMESTAMP NULL,
  timestamps
);
```

`quiz_answers` is a real table, not JSON on the attempt — same reasoning S5
used for `attendance_records` over a JSON blob: I5's review queue needs to
be independently queryable ("show me every `pending_review` answer across
my courses"), which a JSON column can't index.

### 3.2 `QuestionGenerator` contract

```php
namespace App\Domains\Assessments\Services;

class QuestionGenerator
{
    /** Promote a lesson's already-extracted seed_questions into draft Question rows. */
    public function fromSeedQuestions(Lesson $lesson): Collection;

    /** Blueprint-driven fresh generation when seed_questions don't cover the ask. */
    public function generate(Course $course, array $blueprint): Collection;
}
```

Both paths write `status = 'draft'`, `source = 'ai_generated'` — decision 4's
review gate (L6) is enforced by `Question` never being drawable into an
attempt while `status = 'draft'`, not by trusting the generator to only
produce good output.

### 3.3 Draw + auto-submit + grade — data flow

```
Teacher authors/approves questions (manual, or AI-generated then approved)
    → Question.status = 'approved', course-scoped question bank grows

Teacher builds a Quiz against a quiz-type Lesson, sets blueprint/time/attempts
    → Quiz.status = 'published'

Student opens the quiz from the syllabus tree, starts an attempt
    → QuizAttemptService draws question_ids per blueprint from the approved
      pool, excluding the student's immediately-previous attempt's set where
      the pool is large enough to avoid it (I2)
    → QuizAttempt.status = 'in_progress', expires_at = now() + time_limit

Student answers, submits (or expires)
    → MCQ/true_false/numerical: graded instantly, QuizAnswer.status = 'auto_graded'
    → short_answer: QuizAnswer.status = 'pending_review', score stays null
    → QuizAttempt.score = sum of graded answers; stays partial until every
      pending_review answer is reviewed

Independently, daily:
    → AutoSubmitExpiredQuizAttempts force-submits any 'in_progress' attempt
      past its expires_at, grading whatever was answered
```

---

## 4. Testing

| Layer | What | Notes |
|---|---|---|
| Unit | Draw logic — blueprint satisfied, previous-attempt exclusion, pool-too-small fallback | Deterministic fixture pools |
| Unit | Auto-grading — MCQ/true_false exact match, numerical tolerance boundary | Boundary-tested like S5's coverage threshold |
| Integration | Full attempt lifecycle: start → answer → submit → score | Mirrors S5's join-flow integration tests |
| Integration | Time-limit expiry → sweep force-submits, grades partial | Same shape as S4 B5 / S5 B7's scheduled-job tests |
| Integration | AI generation → draft → edit → approve → drawable; a draft question is never drawn | The L6 gate, tested as a real path not just a status check |
| Isolation | `Question`/`Quiz`/`QuizAttempt`/`QuizAnswer` registered in `TenantModelRegistry` | New models — first registration, not an extension |
| E2E | Demo script: generate paper from notes → approve → publish → student attempts → auto-graded result shown | One test, mirrors S5 B2's tree E2E |

---

## 5. What's not real yet, on purpose

No real AI vendor (same `FakeAiDriver`-only posture as every prior slice).
Extended question types, proctoring, ranked test series, and OMR scanning
are all P2/P3 in FEATURES.md and deliberately absent. Instructor-facing
score/coverage dashboards are "basic roster" scope at most, same call S5
made for attendance dashboards — cross-course analytics is later, unscheduled
work (candidate: S8, which already builds parent/dashboard surfaces).

**Demo #6 target: 2026-10-31** — a teacher generates a question paper from
their own lecture notes (via `seed_questions`/`chat()`), reviews and approves
it, publishes the quiz to a topic node in the syllabus tree; a student opens
it, attempts it under the time limit, and sees an auto-graded result with
explanations the moment they submit.
