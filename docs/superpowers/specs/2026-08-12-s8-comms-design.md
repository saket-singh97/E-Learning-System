# Design: S8 Comms — AI-Native LMS

Date: 2026-08-12
Status: DRAFT — pending owner review before Block 0
Scope: Sub-project 8 of 8 in the P1 build (Institute Zero) — the last slice on the roadmap
Window: 2026-08-12 → 2026-11-28 (demo #8 date)
Depends on: S1 Tenancy (roles, `User`), S5 Live Classes/Batches (`attendance_records`, three writers), S7 Fees (`FeeInvoice`, `FeeReminderDue`'s pinned-but-unused WhatsApp slot), S2/S6 (watch coverage, quiz scores) for dashboard reads
Feeds: nothing further on the 8-slice roadmap — this is the last one. Fast-follow items are named in §2.2 and §5.

---

## 1. Why this document exists

`docs/PROJECT-STATUS.md`'s roadmap commits S8 to one demo on 2026-11-28:
**"A parent's phone receiving an absence message."** The one-line roadmap
description is "WhatsApp BSP, parent portal, dashboards, reports" — four
named areas, not one feature. Two contracts are already pinned, unused, from
earlier slices — the same opening shape every slice since S3 has had:

- **`FeeReminderDue::via()` already returns `['mail', 'database']`, with the
  file's own docblock pinning the gap**
  (`app/Notifications/FeeReminderDue.php:19-20`): *"WhatsApp channel is
  explicitly not this block's job (S8's BSP integration) — mail is the only
  real channel until then."* Verified by reading the file, not assumed.
- **No `parent` role exists in code today.** `RoleHierarchy::LEVELS`
  (`app/Domains/Tenancy/Services/RoleHierarchy.php:18-21`) has exactly four
  keys — `super_admin`, `institute_admin`, `instructor`, `student`. S1's own
  HANDOVER summary describes "student/parent" as one level, but that was
  shorthand for the target model in `docs/FEATURES.md`, not a description of
  what got built. Parent is genuinely new work for S8, not a stale-doc
  correction.

A third gap, not previously flagged anywhere: **`users` has no `phone`
column.** Checked across all three migrations that touch the table
(`0001_01_01_000000_create_users_table.php`,
`2026_08_09_060339_add_tenant_id_to_users_table.php`,
`2026_08_09_105111_add_soft_deletes_to_users_table.php`) — none add one. A
WhatsApp message needs a phone number to send to; this has to land in
Block 0.

## 2. Scope

`docs/FEATURES.md` tags roughly 17 features **P1** across its Communication
(§N), Parent/Guardian (§O), Attendance (§H8/H9), and Progress/Analytics
(§M10-M13/M16) sections — nearly triple the P1 density any single prior
slice carried (S7 shipped 6 P1 fee features across 8 blocks). Read literally,
that's not one 2-week slice, it's three. Every prior slice narrowed to what
its own roadmap row and demo script actually need, even within P1 (S4 shipped
manual attendance and left the marking UI to S5 despite attendance being
P1-tagged since S1). S8 does the same: the cut below is scoped to make each
of the roadmap's four named nouns — WhatsApp BSP, parent portal, dashboards,
reports — real, not stubbed, while deferring everything not load-bearing for
the demo script.

### 2.1 In scope (required for the 2026-11-28 demo)

| Feature | FEATURES.md ref | What it means concretely |
|---|---|---|
| WhatsApp BSP integration | N1 (P1) | `CommsProvider` interface + `FakeWhatsAppDriver`, real `AiSensyProvider` built but unbound (no account yet) |
| Parent role + student linkage | O1 (P1) | 5th role (`parent`), admin-created link to ≥1 student |
| Parent portal | O2 (P1) | Read-only: attendance %, coverage/quiz average, fee status, per linked student |
| Same-day WhatsApp on absence | O3 / H9 (P1) | Attendance write → listener → `AbsenceAlertDue` notification to linked parent(s) |
| Fee WhatsApp reminders | O4 (P1) | Completes `FeeReminderDue`'s pinned WhatsApp channel from S7 |
| Attendance + progress dashboards | H8 / M11 / M12 (P1) | One instructor-facing Filament page, one admin-facing widget set |
| One report export | S3 (P2, partial) | Attendance register, CSV, queued as a background job |
| In-app notification centre | N3 (P1) | Minimal bell/list reading the `notifications` table every channel already writes to |

### 2.2 Out of scope, and where each goes

| Feature | FEATURES.md ref | Why deferred | Lands in |
|---|---|---|---|
| Self-service parent linking (invite code) | O1 (partial) | Admin-created link is this app's standing P1 pattern (F2's manual-add shipped before self-enrol did) | Fast-follow |
| Monthly WhatsApp report card (PDF) | M14 (P2) | Needs a new PDF dependency — against this app's "no new library until the demo needs it" posture (invoices are still printable HTML, not PDF) | Fast-follow |
| Automated nudges (inactivity, weak-topic) | M15 (P2) | Not on the demo script | Fast-follow |
| Live-class T-30 reminders | N4 (P1, not demo-critical) | Cheap once B0's channel exists, but not the roadmap's own demo | Fast-follow |
| Course/batch announcements | N6 (P1, not demo-critical) | Same reasoning as N4 | Fast-follow |
| Lesson Q&A, notes/bookmarks, DMs, ratings, calendar, forums, gamification | N7-N18 | P2/P3, unrelated to Comms' own demo | Beyond Institute Zero |
| Low-attendance threshold alerts | H10 (P2) | Builds on B3's alert plumbing once it exists | Fast-follow |
| Geofenced/biometric attendance | H11-H13 (P2/P3) | Different problem (device integration), not a Comms concern | Beyond Institute Zero |
| Parent-teacher message thread | O6 (P3) | Not on the roadmap | Beyond Institute Zero |
| Full report catalog (gradebook/revenue/engagement/completion) | S3 (P2, remainder) | Reuses B6's export scaffolding directly — cheap once one report is real | Fast-follow |
| Real AiSensy/Interakt account | — | No account exists yet — same posture every prior vendor integration shipped with (Bunny, Zoom, Razorpay) | Fast-follow |

### 2.3 Reviewed before writing

- `FeeReminderDue::via()` returns `['mail', 'database']` today, with an
  explicit docblock pinning the WhatsApp gap to S8
  (`app/Notifications/FeeReminderDue.php:19-20`) — confirmed by reading the
  file.
- `RoleHierarchy::LEVELS` has no `parent` key
  (`app/Domains/Tenancy/Services/RoleHierarchy.php:18-21`) — confirmed by
  reading the file, not inferred from HANDOVER's prose summary.
- `users` has no `phone` column in any of its three migrations — confirmed
  by reading all three, not assumed absent.
- `attendance_records` (S5 B0 migration,
  `database/migrations/2026_08_10_140000_create_attendance_records_table.php`)
  is polymorphic (`attendable_type`/`attendable_id`), carries `status`,
  `source`, `marked_by`, `recorded_at`, tenant-scoped. This is the row S8's
  absence alert reads from. Whether any event fires today when a row is
  written is **not yet confirmed** — Block 3 verifies this against the
  actual S5 writer code before assuming an event exists to hook.
- `Integrations/{AiProvider,VideoProvider,LiveClassProvider,PaymentProvider}`
  + `Fake*Driver` + a real-but-unbound driver is this app's standing shape
  for every vendor (S2/S3/S5/S7) — `CommsProvider` follows it exactly.
- `routes/student.php` is two plain closures returning Blade views
  (`student.dashboard`, `student.batches`), no controller, no Filament
  panel — matches `ARCHITECTURE.md`'s surface table ("student portal —
  Livewire, not a Filament panel"). The parent portal follows the same
  shape.
- Cross-domain contact is `Events/`-only for *service* calls; cross-domain
  *model* reads are established and fine (`CLAUDE.md`, HANDOVER's "Avoid"
  list — `Question`/`Quiz belongsTo Lesson` is the standing precedent). The
  parent portal and dashboards read `AttendanceRecord`/`VideoWatchEvent`/
  `QuizAttempt`/`FeeInvoice` directly; they don't call `LessonAccess` or
  `BatchEnrolmentService`.
- The `notifications` table (Laravel's built-in polymorphic table) has been
  populated since S4's `EnrolmentRequestExpired` — every `database`-channel
  send already lands there. B7's notification centre and B3's idempotency
  check both reuse it instead of adding new schema.

### 2.4 Decided (2026-08-12, before Block 0)

1. **New `app/Domains/Comms/` domain** — same per-slice domain precedent as
   Assessments (S6) and Fees (S7). Owns `ParentGuardianLink`, the WhatsApp
   integration, the `whatsapp` notification channel, absence-alert and
   fee-reminder wiring, the parent-portal Livewire components, and the two
   staff dashboards.
2. **Parent-student linkage is admin-created only for S8, not self-service.**
   An institute admin adds a parent (name, email, phone) and links them to
   one or more existing students in one `/admin` action. Matches this app's
   default P1 pattern: manual add shipped before self-enrol variants did
   (F2, S4). Self-service invite-code linking is a fast-follow (§2.2).
3. **`CommsProvider` interface** (`sendTemplate(string $toE164, string
   $template, array $params): array{message_id, status}`) **+
   `FakeWhatsAppDriver`** (deterministic, hash-seeded, same shape as
   `FakeRazorpayDriver`) **+ a real `AiSensyProvider`, built but unbound** —
   exact same posture as `RazorpayProvider`/`ZoomVideoSdkProvider`: throws
   `RuntimeException` naming the missing env var until credentials exist.
4. **WhatsApp delivery is a new Laravel notification channel**
   (`App\Notifications\Channels\WhatsAppChannel`), not a parallel messaging
   system. Every notification that wants it adds a `toWhatsApp()` method and
   lists `'whatsapp'` in `via()`. `FeeReminderDue` gains it in this slice
   (completing S7's pinned contract); a new `AbsenceAlertDue` ships with it
   from day one.
5. **Idempotency (N5) reuses the existing `notifications` table** as the
   dedupe check — query for an existing same-type notification for the same
   notifiable on the same date before sending, rather than a new log table.
   No new table until a real need outgrows this, matching this app's
   standing "no new library/table until the demo needs it" posture.
6. **Dashboards are Filament, not hand-rolled Livewire routes.** An
   instructor-facing Page (table: coverage %, quiz average, attendance %,
   fee status per student, scoped to the instructor's own batches) and
   admin-facing widgets on the existing `/admin` dashboard (enrolments,
   revenue, at-risk count). Matches every prior staff-facing surface's
   panel-first posture.
7. **One CSV report ships for real in S8: the attendance register**, queued
   as a background job (matches `T2`'s established job-queue precedent).
   Every other FEATURES.md §S3 report (gradebook/revenue/engagement/
   completion) reuses the same export scaffolding as a cheap fast-follow,
   not built now.
8. **In-app notification centre (N3) is a minimal read view over the
   existing `notifications` table** — no new schema, shared across student/
   parent/staff surfaces, mark-read / mark-all-read only.
9. **Live-class T-30 reminders (N4) and announcements (N6) are deferred**,
   not built in S8. Both become cheap once B0's channel and B7's centre
   exist, but neither is the roadmap's own demo script for this slice.

---

## 3. Architecture

### 3.0 Where this lands

New domain: `app/Domains/Comms/` (`Models/Services/Filament/Livewire/Views/
Jobs/Policies/Events`, same subfolders as every other domain). Touches two
existing files outside it: `RoleHierarchy::LEVELS` (Tenancy) gains a 5th
role, and `FeeReminderDue` (root `app/Notifications/`) gains a channel. A new
`routes/comms.php` carries the parent portal's plain routes, following
`routes/student.php`'s existing shape.

### 3.1 Schema

**`users` — additive column**

```
phone (string, nullable) — E.164 format, no existing row has one
```

**`parent_student_links` — new table**

```sql
CREATE TABLE parent_student_links (
    id BIGINT PRIMARY KEY,
    tenant_id BIGINT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    parent_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    student_user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    relationship VARCHAR(255) NULL,  -- e.g. 'father', 'mother', 'guardian' — free text, not an enum
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE (parent_user_id, student_user_id)
);
```

No other new tables. Attendance, video coverage, quiz scores, and fee status
are all read directly from `AttendanceRecord`, `VideoWatchEvent`,
`QuizAttempt`, and `FeeInvoice` — no duplication, no new read-model tables.

### 3.2 Data flow

**Absence alert (H9/O3):**
```
Attendance written (any of S5's three writers, status=absent)
  -> AttendanceRecorded event (new, if B3 confirms none exists today)
  -> listener resolves parent_student_links for that student
  -> dedupe check against notifications table (same day, same type)
  -> AbsenceAlertDue::via() = ['whatsapp', 'database']
  -> WhatsAppChannel -> CommsProvider::sendTemplate()
```

**Fee reminder (O4), extending S7's existing sweep:**
```
FeeReminderDue sweep (existing, S7 B6) fires on schedule
  -> FeeReminderDue::via() now returns ['mail', 'database', 'whatsapp']
  -> WhatsAppChannel -> CommsProvider::sendTemplate()
```

**Parent portal read (O2):**
```
Parent logs in (TenantAuthenticator, same as StudentPortal)
  -> ParentGuardianLink resolves linked student(s)
  -> Livewire component reads AttendanceRecord / VideoWatchEvent /
     QuizAttempt / FeeInvoice directly for each linked student
  -> no domain service calls, no LessonAccess/BatchEnrolmentService
```

## 4. Testing

| Layer | What | Count |
|---|---|---|
| Unit | `FakeWhatsAppDriver`, `CommsProvider` contract, `AiSensyProvider` (credential-gated `RuntimeException`), `WhatsAppChannel` | +4 |
| Unit | `ParentGuardianLink` model, `RoleHierarchy` gains `parent` | +2 |
| Integration | Admin creates parent + links student -> parent can log in and see only linked student(s) | +2 |
| Integration | Absence write -> `AbsenceAlertDue` sent to linked parent, not to an unlinked one; dedupe skips a same-day repeat | +3 |
| Integration | `FeeReminderDue` now dispatches on `whatsapp` channel alongside mail | +1 |
| Integration | Instructor dashboard scoped to own batches only; admin widgets tenant-scoped | +2 |
| Integration | Attendance CSV export job produces correct rows, tenant-scoped | +1 |
| E2E | Mirrors `DemoScriptE2ETest` (S7) — student marked absent -> parent receives (fake) WhatsApp -> parent portal shows the absence -> admin dashboard reflects it | +1 |
| Cross-tenant | `ParentGuardianLink` registered in `TenantModelRegistry` — generated suite covers it | (generated) |

## 5. What's not real yet, on purpose

No real AiSensy/Interakt account exists — `FakeWhatsAppDriver` is the only
bound driver, same posture every prior vendor integration shipped with
(Bunny, Zoom, Razorpay). Parent linkage is admin-created only; a parent
cannot self-register and claim a student via invite code yet. The monthly
WhatsApp report card is a PDF-shaped feature (M14) deliberately deferred
against this app's "no new library until the demo needs it" posture — same
reasoning that's kept invoices as printable HTML through S7. Live-class T-30
reminders and course announcements are not built, despite being P1-tagged in
`docs/FEATURES.md` §N, because neither is this slice's own demo script — both
become cheap additions once this slice's channel and notification centre
exist. Only one report (attendance CSV) is real; the rest of §S3's catalog
reuses the same export scaffolding as a fast-follow. Low-attendance threshold
alerts (H10), geofenced/biometric attendance (H11-H13), and the
parent-teacher message thread (O6) are all out of scope, same P2/P3 reasoning
every prior slice has used to keep its own scope table honest.

---

This is the eighth and final slice of the P1 "Institute Zero" roadmap. Once
built and reviewed, `docs/PROJECT-STATUS.md`'s "What's left" section closes
out, matching S1-S7's own post-completion format.
