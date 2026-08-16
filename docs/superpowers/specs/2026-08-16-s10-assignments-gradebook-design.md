# Design: S10 Assignments + Gradebook + Certificates — AI-Native LMS

## 1. Why this document exists

P1 "Institute Zero" (S1-S8) and S9 (Hardening & Backlog) are both complete —
707/708 tests green, every roadmap slice shipped. `docs/FEATURES.md` still
carries ~75 P2 items with zero coverage. S10 is the next owner-picked slice:
**Assignments & Gradebook (J1-J7) + Certificates (K1-K4)**, chosen over three
other candidates (account-security round 2, plan-tier billing, engagement
analytics) because it is the single largest *structural* gap — a real
institute cannot run without assignments or a gradebook, and nothing in
S1-S9 built either. Full candidate comparison lives in the D1 decision the
owner answered; not repeated here.

## 2. S10 scope

### 2.1 In scope

| Feature | FEATURES.md ref | What it means |
|---|---|---|
| Assignment CRUD | J1 | Due date, max marks, allowed file types, size limits |
| Submission | J2 | Up to 5 files, late flag computed at submit time |
| Grading | J3 | Marks, written feedback, rubric scoring |
| Resubmission | J4 | ≤3 versions, full history kept, not overwritten |
| Consolidated gradebook | J5 | Weighted scheme (quiz % + assignment % = 100), per student per course |
| At-risk flagging | J6 | Below-threshold gradebook score flagged, separate metric from S8's attendance-based one |
| Gradebook export | J7 | CSV, Excel, PDF |
| Certificate auto-issue | K1 | PDF on course completion + passing threshold crossed |
| Certificate branding | K2 | Tenant logo/signature/background, reuses `BrandingService` |
| Certificate verification | K3 | Unique ID + QR → public verify page |
| LinkedIn share | K4 | Add-to-profile link + OG metadata, no OAuth needed |

### 2.2 Out of scope, and where each goes

- **J8 (course evaluators + scheduling)** — P3, no institute has asked for
  it yet, genuinely separate feature (external evaluator role).
- **K5 (certificate request + evaluation workflow)** — P3, assumes a
  manual-review certification model this app doesn't have.
- **Rubric-criteria templates reused across assignments** — first pass
  stores a rubric per-assignment (`Assignment.rubric` jsonb); a shared
  rubric-library table is a fast-follow once ≥2 institutes ask for reuse.
- **Gradebook weighting below course level** (e.g. per-section weights) —
  `Course.gradebook_weights` is course-wide; per-section overrides are not
  requested anywhere in FEATURES.md.
- **Real-time certificate issuance** — a daily sweep (§3.3), not a
  same-second trigger off the grading action. See decision 6.

### 2.3 Reviewed before writing this: what S1-S9 already promised toward S10

- `Lesson.TYPES` (Content, S1) is exactly `['video', 'pdf', 'text', 'embed',
  'quiz', 'live_session']` today (confirmed by reading the file, not
  assumed) — no `'assignment'` entry. S10 B0 adds it, same topic-anchor
  pattern S5 (`live_session`) and S6 (`quiz`) both used.
- `Course` (Content) has no `passing_threshold` or `gradebook_weights`
  column (confirmed by reading `$fillable`) — both are new in S10 B0's
  migration, following S7 B0's own precedent of a Fees-domain migration
  adding columns to `Batch`/`Tenant`.
- `StudentMetrics::AT_RISK_THRESHOLD` (Comms, S9 B13) is an
  **attendance**-based 60% flag. J6 is a **gradebook**-score flag — a
  different metric answering a different question ("is this student
  passing" vs. "is this student showing up"). S10 gets its own constant,
  not a shared one; conflating them would silently change S9's existing
  attendance-alert behaviour.
- No Excel or PDF-generation package exists in `composer.json` (confirmed).
  Both J7 (PDF/Excel export) and K1 (certificate PDF) need one — see
  decision 4.

### 2.4 Decided (2026-08-16, before Block 0)

1. **One new domain, `app/Domains/Assignments/`**, houses `Assignment`,
   `AssignmentSubmission`, and `Certificate` — same "one domain, several
   related models" shape S7 (Fees: `FeeInvoice`/`FeePayment`/`FeeCoupon`)
   already established. Certificates depend on gradebook completion, which
   depends on assignments/quizzes — splitting Certificates into its own
   domain would need a new cross-domain event for zero benefit.
2. **Grading columns live on `AssignmentSubmission`, not a separate `Grade`
   model** — same shape `QuizAnswer` (S6) already uses (score columns on
   the answer row itself). `rubric_scores` is `jsonb`, keyed by the
   criterion id from `Assignment.rubric`.
3. **Resubmission is multiple rows, not a JSON history array on one row** —
   `AssignmentSubmission` gets a `version_number` (1-3, DB-checked), unique
   per `(assignment_id, user_id, version_number)`. "Full version history"
   (J4) means every version stays queryable and gradeable independently,
   not squashed into a blob a future report can't easily read.
4. **PDF via `barryvdh/laravel-dompdf`, Excel via `maatwebsite/excel`** —
   first use of either in this app. Dompdf serves both J7's PDF gradebook
   export and K1's certificate PDF with one dependency, extending the
   "printable HTML" posture S7 B5 already used for invoices into a real
   rendered PDF, scoped to this slice's own needs only (S7's invoices stay
   HTML, not retrofitted).
5. **Gradebook weighting lives on `Course.gradebook_weights`** (jsonb,
   `{"quiz": 40, "assignment": 60}`, admin-validated to sum to 100) — course
   is the syllabus container both quizzes and assignments anchor into via
   their own `lesson_id`, so it's the natural scope, not batch (a course
   can run across several batches with the same weighting) or tenant (two
   courses at one institute can reasonably weight differently, e.g. a lab
   course weighting assignments higher than a lecture course).
6. **Certificate issuance is a daily scheduled sweep, not an inline hook
   off quiz/assignment grading** — `QuizAttempt::submit()` (Assessments) and
   `AssignmentSubmission` grading (this domain) are two separate write
   paths in two different domains; wiring a real-time completion check into
   both means either a new cross-domain event fired from Assessments (a
   change to a domain S10 doesn't otherwise touch) or an inline call from
   Assignments into Assessments' services (forbidden — domains talk through
   `Events/` only). A `CheckCourseCompletionAndIssueCertificates` job,
   matching S9 B10's own "cross-tenant daily sweep" shape, reads both
   domains' models directly (a read, not a service call — same posture
   `GradebookService` itself already uses) with no new domain coupling.
   Same-day latency is an acceptable tradeoff for a certificate, unlike a
   live gradebook number a student expects to see instantly.
7. **Completion is defined as: every gradeable item (quiz + assignment)
   anchored to the course's published lessons has been attempted at least
   once by the student, AND the weighted gradebook score is ≥
   `Course.passing_threshold`.** No generic lesson/chapter completion
   tracking exists anywhere in this app (S5's own note: only a
   `live_session`-scoped flag exists, read by nothing) — building one from
   scratch is a materially bigger feature than "issue a certificate."
   Anchoring completion to the gradebook instead reuses schema this slice
   is already building, and matches how a real institute already thinks
   about "did the student finish the course" (graded, not just watched).

## 3. Architecture

### 3.0 Where this lands

```
app/Domains/Assignments/
  Models/        Assignment, AssignmentSubmission, Certificate
  Services/      GradebookService, CertificateService
  Filament/      Resources/Assignments/, Resources/GradebookReview/ (teach)
  Livewire/      AssignmentSubmit, GradebookTable
  Jobs/          ExportGradebookCsv, ExportGradebookExcel, ExportGradebookPdf,
                 CheckCourseCompletionAndIssueCertificates
  Views/         certificate-pdf.blade.php, verify.blade.php
```

Cross-domain reads (established precedent, not new): `Assignment`/
`AssignmentSubmission` → `Content\Lesson`/`Content\Course`;
`GradebookService` → `Assessments\Quiz`/`QuizAttempt` (read-only, no service
call). No new cross-domain event — every write this slice needs stays
inside its own domain.

### 3.1 Schema

```sql
-- assignments (Content: Lesson gains 'assignment' to its TYPES const, no schema change there)
CREATE TABLE assignments (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    lesson_id BIGINT NOT NULL REFERENCES lessons(id) ON DELETE CASCADE, -- type='assignment'
    title VARCHAR(255) NOT NULL,
    instructions TEXT,
    due_at TIMESTAMP NOT NULL,
    max_marks NUMERIC(6,2) NOT NULL,
    allowed_file_types JSONB NOT NULL DEFAULT '["pdf","doc","docx","jpg","png"]',
    max_file_size_kb INT NOT NULL DEFAULT 10240,
    rubric JSONB, -- [{id, criterion, max_points}], null = marks-only grading
    created_at TIMESTAMP, updated_at TIMESTAMP
);

CREATE TABLE assignment_submissions (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    assignment_id BIGINT NOT NULL REFERENCES assignments(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    version_number SMALLINT NOT NULL CHECK (version_number BETWEEN 1 AND 3),
    files JSONB NOT NULL, -- [{disk_path, original_name, size_kb}], up to 5
    submitted_at TIMESTAMP NOT NULL,
    is_late BOOLEAN NOT NULL DEFAULT FALSE,
    marks_awarded NUMERIC(6,2),
    feedback TEXT,
    rubric_scores JSONB, -- {criterion_id: points_awarded}
    graded_by_user_id BIGINT REFERENCES users(id),
    graded_at TIMESTAMP,
    UNIQUE (assignment_id, user_id, version_number)
);

-- Content domain migration (touched from this slice, same precedent as S7 B0)
ALTER TABLE courses ADD COLUMN gradebook_weights JSONB NOT NULL DEFAULT '{"quiz":50,"assignment":50}';
ALTER TABLE courses ADD COLUMN passing_threshold NUMERIC(5,2) NOT NULL DEFAULT 40.00;

CREATE TABLE certificates (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    course_id BIGINT NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
    verification_id VARCHAR(40) NOT NULL UNIQUE, -- short opaque code, not sequential
    final_score NUMERIC(5,2) NOT NULL,
    pdf_disk_path VARCHAR(255) NOT NULL,
    issued_at TIMESTAMP NOT NULL,
    UNIQUE (tenant_id, user_id, course_id)
);
```

`assignment_submissions.tenant_id` and `certificates.tenant_id` both
`ON DELETE CASCADE`, matching the 19-of-20 convention (S8 B8 found and
fixed the one deviation; this slice starts correct).

### 3.2 `GradebookService` contract

```php
GradebookService::scoreFor(User $student, Course $course): array{
    quiz_percent: ?float, assignment_percent: ?float,
    weighted_percent: float, is_at_risk: bool, is_passing: bool,
}
GradebookService::isComplete(User $student, Course $course): bool
```

Quiz percent: average of the student's best `QuizAttempt` per `Quiz`
anchored to the course's lessons (read-only cross-domain query, same shape
`StudentMetrics::quizAveragePercent()` already established). Assignment
percent: latest graded `AssignmentSubmission` version per `Assignment`
anchored to the course's lessons. A component with zero gradeable items
(e.g. a course with quizzes but no assignments yet) drops out of the
weighted average entirely rather than counting as zero — matches how a
real gradebook behaves when a category isn't used yet.

### 3.3 Certificate issuance — daily sweep data flow

```
CheckCourseCompletionAndIssueCertificates (ShouldQueue, 'scheduled' queue,
daily, same shape S9 B10 established)
  for each tenant:
    CurrentTenant::clear() (S9 B10's own lesson — stale binding leaks across tenants)
    for each active enrolment with no existing Certificate for that course:
      if GradebookService::isComplete(...):
        CertificateService::issue() -> renders dompdf template with
        BrandingService's tenant logo/colours, stores PDF, writes Certificate row
```

### 3.4 Certificate verification + LinkedIn share

`GET /certificates/verify/{verification_id}` — public, host-agnostic (same
posture as S9 B5's invite-redemption route), renders student name, course,
tenant branding, issued date; 404 on an unknown id (no enumeration hint).
QR code encodes that same URL (a small server-side QR lib, no new vendor
account). LinkedIn "Add to profile" is a plain link with LinkedIn's
documented `certUrl`/`certId`/`organizationName`/`name` query parameters —
zero OAuth, zero vendor account, same "real, zero-setup" posture S5's
`ExternalLinkProvider` used for live classes.

## 4. Testing

| Layer | What | Approx count |
|---|---|---|
| Unit | `GradebookService::scoreFor()` weighting math, edge cases (zero quizzes, zero assignments, both zero) | 8 |
| Unit | Resubmission version cap, late-flag computation | 4 |
| Feature | Assignment CRUD, submission upload, grading, rubric scoring | 12 |
| Feature | Gradebook export (CSV formula-injection guard, Excel, PDF) | 6 |
| Feature | Certificate sweep — issues once, not duplicated on a second run; respects passing threshold | 6 |
| Feature | Certificate verification page — real id renders, unknown id 404s, cross-tenant id doesn't leak | 4 |
| Cross-tenant isolation | Generated suite (`TenantModelRegistry`) — `Assignment`, `AssignmentSubmission`, `Certificate` | 9 (3 models × 3 generated cases) |

Roughly 50 new tests, in line with S6-S9's own per-slice counts (60-100).

## 5. What's not real yet, on purpose

Rubric templates aren't shared across assignments (§2.2). Certificate
issuance has up to 24h latency by design (decision 6). No plagiarism
detection on submitted files (not in FEATURES.md at all). Gradebook
weighting is course-wide only, no per-section override. LinkedIn share
needs no vendor account and stays that way — no "verified by LinkedIn"
badge, just a working add-to-profile link.
