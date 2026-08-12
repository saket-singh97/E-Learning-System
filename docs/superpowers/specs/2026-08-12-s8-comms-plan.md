# Implementation Plan: S8 Comms

Full design: `2026-08-12-s8-comms-design.md`. Block-by-block build log — update
the PROGRESS table as each block lands, same as S1-S7.

---

## Per-block build command

```
Read docs/superpowers/specs/2026-08-12-s8-comms-design.md and
docs/superpowers/specs/2026-08-12-s8-comms-plan.md, then build
Block 0 of the S8 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | Domain groundwork — `users.phone`, `parent_student_links` migration + model, `parent` role, `CommsProvider` + `FakeWhatsAppDriver` + `AiSensyProvider`, `WhatsAppChannel`, tenant registry | ✅ done |
| B1 | Parent accounts + linkage (O1) — admin creates + links a parent in `/admin`, parent login route | ✅ done |
| B2 | Parent portal (O2) — read-only attendance/coverage/quiz/fee view per linked student | ✅ done |
| B3 | Absence WhatsApp alert (H9/O3) — attendance write -> `AbsenceAlertDue` to linked parent(s), same-day, deduped | ✅ done |
| B4 | Fee WhatsApp reminder (O4) — `FeeReminderDue` gains the `whatsapp` channel, completing S7's pinned contract | ✅ done |
| B5 | Staff dashboards (H8/M11/M12) — instructor Filament page, admin widgets | ✅ done |
| B6 | Attendance CSV report (S3, partial) — queued export job + `/admin` download | ✅ done |
| B7 | In-app notification centre (N3) — bell/list over the existing `notifications` table | ✅ done |
| B8 | Full test coverage, isolation registration, review pass, `PROJECT-STATUS.md`/`HANDOVER.md` update | ✅ done |

---

## Block notes (fill in as each block builds — deviations, gotchas, decisions made mid-build)

### B0 — Domain groundwork ✅

- Scaffolded `app/Domains/Comms/` with all 8 standard subfolders. Only
  `Models/` is populated so far (`ParentGuardianLink`) — the rest stay
  empty until the block that needs them, same posture Fees' B0 set (it
  only populated 5 of 8).
- Migrations, in FK-dependency order:
  `2026_08_12_090000_add_phone_to_users_table.php` (nullable string,
  `after('email')`), `2026_08_12_090100_create_parent_student_links_table.php`
  (`tenant_id`, `parent_user_id`/`student_user_id` both
  `constrained('users')->cascadeOnDelete()`, nullable `relationship`,
  `unique(['parent_user_id', 'student_user_id'])`) — matches design doc
  §3.1 exactly.
- `ParentGuardianLink` model (`app/Domains/Comms/Models/`), `BelongsToTenant`,
  registered in `TenantModelRegistry::MODELS` with `seedAttributes()`/
  `updateAttributes()` in this block. Relation methods are `parentUser()`/
  `student()`, not `parent()` — `parent` is a legal-but-confusing method
  name in PHP (collides visually with the `parent::` keyword), avoided on
  purpose rather than found as a bug.
  - **Gotcha found by the test suite, not by reading**: the model class name
    `ParentGuardianLink` makes Eloquent guess the table
    `parent_guardian_links`, but the design doc pins the table to
    `parent_student_links`. `CrossTenantTest` and the model's own tests
    both failed with "relation parent_guardian_links does not exist" until
    `protected $table = 'parent_student_links';` was added explicitly.
- **`RoleHierarchy::LEVELS` correction, not addition**: the design doc's
  own §1 claim ("no `parent` role exists in code today... exactly four
  keys") was stale — `'parent' => 4` (tied with `student`) has existed
  since S1, confirmed by reading the live file before editing. What this
  block actually did was change the value from `4` to `5` and rewrite the
  docblock that explained the old tie, rather than add a new key. Flagging
  here since both the design doc and this plan's own B0 bullet assumed the
  key was missing — future blocks referencing "S8 added the parent role"
  should say "S8 split parent from student's tier" instead.
  `CAN_ASSIGN_ROLES` needed no change — confirmed, not assumed:
  `canAssign()` only checks `LEVELS[$target] > LEVELS[$actor]`, true at
  both 4 and 5 for an admin-tier actor.
- `app/Integrations/CommsProvider.php` interface:
  `sendTemplate(string $toE164, string $template, array $params):
  array{message_id: string, status: string}` — built exactly as specified.
- `FakeWhatsAppDriver` — deterministic-shaped, hash-seeded per call, never
  repeats a `message_id` (same non-idempotency reasoning as
  `FakeRazorpayDriver`'s `order_id`).
- `AiSensyProvider` — real, built, unbound (`AppServiceProvider` still
  binds `FakeWhatsAppDriver`). Checked AiSensy's actual v2 Campaign API
  before writing this, not guessed: `POST
  https://backend.aisensy.com/campaign/t1/api/v2` with
  `apiKey`/`campaignName`/`destination`/`userName`/`templateParams` —
  `$template` maps to `campaignName` (a "campaign" in AiSensy's dashboard
  *is* the pre-approved WhatsApp template). Throws `RuntimeException`
  naming `AISENSY_API_KEY` when unset, same shape as
  `RazorpayProvider`/`ZoomVideoSdkProvider`.
- `config/services.php` gained an `aisensy.api_key` entry; `.env.example`
  gained `AISENSY_API_KEY=`, matching the `ZOOM_SDK_KEY`/`RAZORPAY_KEY_ID`
  grouped-blank-line precedent. `FakeWhatsAppDriver` bound to
  `CommsProvider` in `AppServiceProvider`, same pattern as the four
  existing driver bindings.
- `App\Notifications\Channels\WhatsAppChannel` — plain class with a
  `send($notifiable, Notification $notification)` method (this codebase's
  first hand-rolled notification channel; Laravel has no base class to
  extend). Checks `$notifiable->phone` **before** calling `toWhatsApp()`
  and returns `null` (skips silently) when it's blank, rather than calling
  the vendor with an empty destination. Also added
  `App\Notifications\Messages\WhatsAppMessage` (`template` + `params`
  readonly DTO) as the return type every future `toWhatsApp()` hands the
  channel — same role `MailMessage` plays for `toMail()`, sized to exactly
  what `CommsProvider::sendTemplate()` needs. Neither of these two classes
  was named explicitly in the design doc's file list; both are small,
  directly required by "dispatches through CommsProvider," and follow an
  existing in-codebase convention (`MailMessage`), so this wasn't treated
  as scope creep.
- Added `phone` to `User`'s `#[Fillable(...)]` attribute (this model uses
  the PHP-attribute fillable style, not a `$fillable` property — different
  from every domain model, confirmed by reading `User.php` before editing
  rather than pattern-matching from `FeeInvoice`).
- Tests added (`tests/Feature/Integrations/FakeWhatsAppDriverTest.php`,
  `AiSensyProviderTest.php`, `tests/Feature/Notifications/WhatsAppChannelTest.php`,
  `tests/Feature/Comms/ParentGuardianLinkTest.php`,
  `tests/Feature/Tenancy/RoleHierarchyTest.php` — the last one is new,
  `RoleHierarchy` had no dedicated test file before this block despite
  being live since S1). 457/458 green — the one failure is the
  pre-existing `CourseSlugUniquenessTest` case carried since S1/S2, out of
  scope (see HANDOVER.md).
- **Not done in this block, deliberately**: the "verify before building
  B3" check (does any of S5's three attendance writers fire an event
  today?) is B3's own precondition, not B0's — left for B3 to confirm
  against the actual writer code before assuming either way.

### B1 — Parent accounts + linkage (O1) ✅

- `ParentGuardianResource` (`app/Domains/Comms/Filament/Resources/ParentGuardians/`),
  registered in `AdminPanelProvider` via a new `discoverResources()` line
  (resource discovery in this codebase is per-domain and explicit — a
  resource isn't picked up just by living under `Domains/*/Filament/Resources/`,
  confirmed by reading `AdminPanelProvider` before assuming otherwise).
  Backed by `User::class`, not a new model — a "parent" is a `User` row with
  the `parent` role, same as `EnrolmentsRelationManager` treats students as
  `User::role('student')` rather than a distinct model.
  `getEloquentQuery()` scopes to `role('parent')` + the current tenant (User
  has no `BelongsToTenant` global scope, so the tenant filter is by hand,
  same as `EnrolmentsRelationManager`'s own student `Select` does it).
  - **"Create + link ≥1 student in one action" (design doc §2.4 decision 2)
    had no direct precedent to copy.** This codebase's only existing
    extra-column-pivot pattern (`Enrolment`, `EnrolmentsRelationManager`)
    manages linkage via a `RelationManager`, which requires the owner
    record's model to expose a real Eloquent relationship method — adding
    one to the shared `App\Models\User` for a single domain's benefit would
    be the wrong direction (`User` already has `tenant()` because tenancy
    is foundational; no other domain has added a relation onto `User` for
    its own FKs, e.g. `FeeInvoice`/`Enrolment` both `belongsTo(User)` but
    `User` has no `feeInvoices()`/`enrolments()` back). Built instead as a
    `Repeater::make('links')` (student `Select` + free-text `relationship`)
    on the resource's own `form()`, pulled out of `$data` and turned into
    `ParentGuardianLink::create()` calls inside `CreateParentGuardian::handleRecordCreation()`
    — genuinely one form submission, no relation added to `User`.
    `EditParentGuardian` mirrors this with `mutateFormDataBeforeFill()` (hydrates
    `links` from existing rows) + `handleRecordUpdate()` (diffs: deletes
    dropped links, `updateOrCreate()`s the rest).
  - Role grant goes through `RoleAssignmentPolicy::assign()`, not
    `syncRoles()` directly — the only path in this codebase that writes the
    audit trail (`RoleAssignmentAudit`) and enforces `RoleHierarchy::canAssign()`;
    `institute_admin → parent` was already proven allowed by
    `RoleAssignmentTest` before this block started.
  - Password is a plain required field on create (`hiddenOn('edit')`) — no
    invite-email flow exists anywhere in this app yet (self-service is a
    named fast-follow, design doc §2.2), so the admin sets the initial
    password directly, consistent with "admin-created, not self-service."
- `App\Livewire\ParentPortal` + `resources/views/livewire/parent-portal.blade.php`
  + `resources/views/parent/dashboard.blade.php` — mirrors `StudentPortal`
  almost exactly (same `TenantAuthenticator::attempt()` call, same session
  regenerate-on-login/invalidate-on-logout). Actual linked-student content
  (attendance/coverage/quiz/fee) is a `@auth` placeholder for now — that's
  B2's job.
  - **Deviation from this plan's own bullet, found by reading the actual
    reference implementation before copying it**: this plan said the route
    shape would be "`/parent/login`, `/parent/dashboard`, following
    `routes/student.php`'s existing two-closures shape" — but
    `routes/student.php` doesn't have a login route at all; it's `/dashboard`
    + `/batches`, and `StudentPortal` handles login and the authed view in
    one component mounted at a single route. Built `routes/comms.php` with
    one route (`GET /parent/dashboard`) to actually match the cited
    reference, not the plan's own paraphrase of it — same "verify a spec
    doc's claim before trusting it" discipline B0 applied to the
    `RoleHierarchy` claim.
  - **Tenant cross-check, added because the plan required it, not found by
    a test failure**: `SESSION_DOMAIN` is a shared parent domain, so a
    parent's session cookie from tenant A stays valid on tenant B's
    subdomain. `ParentPortal::mount()` now force-logs-out + invalidates the
    session when `Auth::user()->tenant_id !== CurrentTenant::id()`, mirroring
    `DownloadInvoiceController`'s hand-rolled check (S7 B7).
    **`StudentPortal` itself still has this exact gap and was deliberately
    left unfixed** — out of this block's scope, added to HANDOVER's "Avoid"
    list instead of silently patched.
- `routes/comms.php` wired into `routes/web.php` with the same
  `Route::domain('{subdomain}.lms.test')->middleware(['web', 'resolve.tenant'])`
  block every other domain route file uses (strict mode, not `:optional` —
  confirmed `student.php`/`content.php`/etc. all use strict mode before
  copying it).
- Tests added (`tests/Feature/Comms/ParentGuardianResourceTest.php`:
  create+link in one action, linking 2 students, `links` required,
  edit-diffs-links; `tests/Feature/Comms/ParentPortalTest.php`: guest shell,
  login, wrong-password, lockout, session regenerate/invalidate, and the
  new cross-tenant-session-logout case StudentPortalTest's own template
  doesn't have). 467/468 green — same pre-existing `CourseSlugUniquenessTest`
  failure, out of scope.

### B2 — Parent portal (O2) ✅

- **Kept the single flat `App\Livewire\ParentPortal` class from B1**, not a
  `ParentPortal\...` namespace of sub-components as this plan's own bullet
  sketched — one component was enough for login + switcher + metrics, and
  splitting it would have been designing for a future need this block
  doesn't have (same "don't design for hypothetical requirements" instinct
  as every other block's own deviations).
- New `App\Domains\Comms\Services\StudentMetrics` (`summarize(User
  $student): array`) is the actual metrics engine — `ParentPortal` just
  calls it. Extracted into `Services/` rather than inlined in the Livewire
  component because the design doc's own B5 note expects the staff
  dashboard to show the same four numbers; B5 can call this directly
  instead of re-deriving it.
  - **Attendance %** — a genuine judgment call, nothing pinned anywhere
    else in the app: `AttendanceRecord` has two sources, `manual`
    (`BatchSession`-typed, written both `present` *and* `absent` for every
    enrolled student by the teacher's bulk-mark action) and
    `video_coverage` (`Lesson`-typed, written **only** as `present` once
    the ≥80% threshold trips — never an `absent` row). Counting both types
    together would inflate the numerator with no matching denominator, so
    `attendancePercent()` counts `BatchSession`-typed rows only — the only
    source with a true present/absent denominator. Documented inline as a
    decision, not a silently-picked default.
  - **Coverage %** — reuses `RecordVideoCoverageAttendance`'s exact
    merge-interval formula (`mergedCoveredSeconds()`: sort watched
    `[start_s, end_s]` intervals, union-merge overlaps, sum the lengths,
    divide by `Lesson.video_duration_seconds`) but **duplicates the
    algorithm rather than calling the Batches-domain Job class** — same
    reasoning HANDOVER's "Avoid" list already documents for
    `Quiz::isVisibleTo()` not calling `LessonAccess` (S6 B5): a Job class
    is a service, not a model, so calling it directly would be exactly the
    cross-domain service call CLAUDE.md rules out. The portal's own
    "coverage average" is the mean of each watched lesson's own ratio
    (not seconds-summed-over-durations-summed), so one long lecture can't
    drown out several short, fully-watched ones — a second judgment call,
    also documented inline.
  - **Quiz average** — mean of each completed attempt's own
    `score/max_score` ratio (not `sum(score)/sum(max_score)`), matching
    how `QuizRunner` already shows every attempt as its own fraction
    rather than pooling raw points across quizzes of different sizes.
    Excludes `in_progress` attempts and any attempt with a null `score`
    (pending short-answer review) — nothing to average yet.
  - **Fee status** — no existing "good standing" flag anywhere to reuse
    (confirmed by reading `FlagOverdueInvoices`/`SendFeeReminders` — both
    filter raw invoice rows, never roll one up per student). Worst-status-
    wins: any `overdue` invoice makes the summary `'overdue'` even if
    others are `paid`; `'pending'` if nothing's overdue; else
    `'up_to_date'`. `outstanding_invoices` (pending + overdue, ordered by
    due date) rides alongside the status string for the actual invoice
    list.
- Student-switcher: `ParentPortal::selectStudent(int $studentId)` sets
  `$selectedStudentId` with **no ownership check inside the method
  itself** — it doesn't need one. `render()`'s own `linkedStudents()`
  query is already scoped to `parent_user_id = Auth::id()`, so an
  unlinked/arbitrary id passed to `selectStudent()` (e.g. a tampered
  Livewire request) simply fails to match anything in
  `$linkedStudents->firstWhere('id', ...)` — self-securing by data
  scoping, the same pattern this app's own `BelongsToTenant` global scope
  already relies on, rather than a second explicit authorization check.
  Covered by a dedicated test (`ParentPortalDashboardTest`'s "cannot
  select a student it is not linked to").
- Read-only throughout — no mutating action anywhere in this block; the
  only writes are the pre-existing `login()`/`logout()` session calls
  from B1.
- Tests added: `tests/Feature/Comms/StudentMetricsTest.php` (12 cases —
  each metric's formula, including the BatchSession-vs-video_coverage
  attendance decision, multi-lesson coverage averaging, and fee-status
  priority) and `tests/Feature/Comms/ParentPortalDashboardTest.php` (4
  cases — empty state, metrics render after login, switcher default +
  switch, and the unlinked-student-selection security case). 482/483
  green — same pre-existing `CourseSlugUniquenessTest` failure, out of
  scope.

### B3 — Absence WhatsApp alert (H9/O3) ✅

- **B0's deferred verification, resolved**: neither of S5's attendance
  writers fires any event today, confirmed by reading both writer methods
  in full (not assumed either way). Also found: **"S5's three attendance
  writers" (design doc §2.3, plan doc's own B0/B3 wording) is stale** —
  only two writers ever shipped (`RecordVideoCoverageAttendance`,
  `BatchSessionsRelationManager`'s `markAttendance` action); the third
  (live-webhook) was deferred to fast-follow back in S5 and never
  reconsidered here. `AttendanceRecord`'s own docblock already said this
  ("two writers ship in S5; a third is fast-follow") — the design doc's
  §3.2 data-flow diagram just hadn't been updated to match. Built
  `App\Domains\Batches\Events\AttendanceRecorded` (`userId`, `tenantId`,
  `status` — primitive ids, one-way-ignorance shape matching
  `EnrolmentAwaitingPayment`/`PaymentCaptured`) and fired it from both real
  writers, not three.
  - `RecordVideoCoverageAttendance` fires it **outside** its own
    `AttendanceRecord::withoutEvents()` block — that call only suppresses
    Eloquent's own model events, not a manually dispatched Laravel event,
    so the tenant-unbound posture that block exists for doesn't apply to
    `event(new AttendanceRecorded(...))` itself.
  - This writer only ever produces `status = 'present'`, so its own fired
    event is a no-op for the absence listener — kept anyway so
    `AttendanceRecorded` stays a reliable "attendance was written" signal
    for any future consumer, not just this one.
- New `App\Domains\Comms\Listeners\SendAbsenceAlert`, registered in
  `AppServiceProvider::boot()` alongside the other cross-domain
  `Event::listen()` calls. Resolves `ParentGuardianLink::where('student_user_id',
  ...)` for the absent student, then sends `AbsenceAlertDue` to each
  linked parent found, unless already sent today.
  - **Dedupe scope, narrowed from the design doc's own wording**: §2.4
    decision 5 literally says "same notifiable, same type, same day" —
    taken literally, that would block a parent's **second** linked
    child's absence alert on the same day too (same notifiable=parent,
    same type=`AbsenceAlertDue`, same day, already sent for child #1).
    `SendAbsenceAlert::alreadySentToday()` additionally matches which
    student the alert is about, read back from the stored notification's
    own `data['student_user_id']` — a deliberate refinement caught by
    reasoning through the multi-child case before writing the query, not
    a bug found after the fact. Covered by its own test ("does not let
    one linked student's same-day alert suppress a second linked
    student's own alert").
  - This is **the first dedupe-via-notifications-table query in the
    codebase** — confirmed nothing like it existed before (`SendFeeReminders`
    is explicitly stateless with no dedupe guard at all, by its own
    docblock). Filters in PHP after fetching the day's same-type
    notifications rather than a JSON `where` on the `data` column — the
    `notifications.data` column is plain `text`, not `json`/`jsonb`, so a
    `whereJsonContains`-style query wouldn't reliably work against it.
- New `App\Notifications\AbsenceAlertDue` (`app/Notifications/`, matching
  `FeeReminderDue`'s precedent — not a per-domain `Notifications/`
  subfolder). `via(): [WhatsAppChannel::class, 'database']` — the fully-
  qualified class name, not the string `'whatsapp'`, matching B0's own
  `WhatsAppChannelTest` convention. `toWhatsApp()` returns a
  `WhatsAppMessage` naming the student and today's date; `toArray()`
  stores `student_user_id` specifically so the listener's own dedupe
  query above can read it back.
- Stays **synchronous** (`use Queueable;`, no `ShouldQueue`) — matches
  every existing Notification class in this codebase (`FeeReminderDue`,
  `EnrolmentRequestExpired`); `ShouldQueue` usage in this app is confined
  entirely to the AI domain's ingestion pipeline, not notifications.
- Same-day only — no batching, no digest, matches the roadmap's own demo
  script ("a parent's phone receiving an absence message" the day it
  happens).
- Tests added: event-dispatch assertions folded into the two existing
  writer test files (`BatchSessionAttendanceTest.php`,
  `RecordVideoCoverageAttendanceTest.php`, `Event::fake()` +
  `assertDispatched`, matching this codebase's existing convention from
  `BatchEnrolmentServiceTest`) plus two new files:
  `tests/Feature/Comms/AbsenceAlertDueTest.php` (notification shape) and
  `tests/Feature/Comms/SendAbsenceAlertTest.php` (7 cases — notifies
  linked parent, silent on present, doesn't notify an unlinked parent,
  same-day dedupe, multi-child dedupe scoping, and a deleted-user guard).
  493/494 green — same pre-existing `CourseSlugUniquenessTest` failure,
  out of scope.

### B4 — Fee WhatsApp reminder (O4) ✅

- `FeeReminderDue::via()` now returns `['mail', 'database',
  WhatsAppChannel::class]` — the FQCN, matching B0's own
  `WhatsAppChannelTest`/B3's `AbsenceAlertDue::via()` convention, not the
  string `'whatsapp'`. New `toWhatsApp()` renders a `fee_reminder`
  template with `stage`/`amount`/`description`/`due_date` params. No
  change to `SendFeeReminders` (S7 B6) — same T-3/T-0/T+3 cadence, same
  notifiable, one more channel on the same notification, exactly as this
  plan's own bullet specified.
- **Found and flagged, not silently "fixed": this channel will no-op on
  every real send today.** `FeeReminderDue`'s notifiable has always been
  `$invoice->user` — the enrolled student (unchanged since S7) — and
  nowhere in this app does a student's `phone` ever get set (grepped
  `'phone'` across `app/`: the only write site is `ParentGuardianResource`,
  S8 B1, which only ever sets it on a *parent*). `WhatsAppChannel`'s own
  blank-phone guard (B0) means every student-bound `FeeReminderDue` will
  silently skip the WhatsApp send, forever, unless something else starts
  setting student phone numbers. Worth noting: `docs/FEATURES.md` files
  O4 under its Parent/Guardian section, which reads as intent for this to
  eventually reach a linked parent — but the design doc's own §3.2
  data-flow for this notification has no `parent_student_links`
  resolution step the way B3's absence alert explicitly has one, and this
  block's plan bullet explicitly says "no change to the sweep." Re-routing
  the recipient would have been a real scope decision this block was
  never asked to make, so it wasn't guessed at — documented in the file's
  own docblock instead, the same way S7 B7 flagged-without-fixing the
  `LessonAccess`/`ShowQuizController` tenant gap.
- Docblock rewritten (design doc's own instruction) — no longer says
  "WhatsApp is explicitly not this block's job," now explains the above
  gap directly in the file itself so the next block/session sees it
  without needing this plan doc open.
- Tests added to the existing `tests/Feature/Fees/SendFeeRemindersTest.php`
  (matching this file's own established depth — the pre-existing mail
  test only checks rendered content, not real SMTP delivery, so the new
  WhatsApp tests match that same depth rather than trying to prove an
  actual vendor call): `via()` includes the WhatsApp channel, and
  `toWhatsApp()` renders the right template/params. 495/496 green — same
  pre-existing `CourseSlugUniquenessTest` failure, out of scope.

### B5 — Staff dashboards (H8/M11/M12) ✅

- **"An instructor sees only their own batches' students, same as every
  other `/teach` surface" (this plan's own B5 bullet) doesn't describe
  anything that exists.** Read every `Batch`/`Course`-related model and
  policy before building: there is no instructor-batch/course pivot
  anywhere in this codebase, and no `/teach` resource (`CourseResource`,
  `QuestionResource`) scopes by instructor today — they only apply the
  standard tenant scope. `EnrolmentApprovalPolicy`'s own docblock already
  documents this exact gap (S4) and reads "assigned" as "same tenant as
  the batch" for lack of real per-instructor data. `StudentProgress`
  follows that same established reading rather than inventing a stricter
  scope with no schema to enforce it: its roster is every actively
  enrolled student in the instructor's own tenant.
- New `App\Filament\Teach\Pages\StudentProgress` — **this codebase's first
  standalone Filament `Page` with a table not tied to a Resource's CRUD.**
  No in-repo precedent existed; built from
  `Filament\Resources\Pages\ManageRelatedRecords`'s own use of
  `EmbeddedTable::make()` inside `content(Schema $schema)` (read from the
  `filament/filament` vendor source directly, since no example lived in
  `app/`) — a `Page implements HasTable`, `use InteractsWithTable;`,
  `table(Table $table): Table` configures the query/columns, `content()`
  embeds it. Auto-discovered at `app/Filament/Teach/Pages` (the directory
  didn't exist; `TeachPanelProvider`'s own `discoverPages()` root, no
  provider change needed).
  - Columns for attendance/coverage/quiz-average read `StudentMetrics`
    (S8 B2) per row via `->state(fn (User $record) => ...)`, matching
    `LessonResource`'s own established computed-column pattern. Direct
    per-row queries, no caching — bounded by Filament's own pagination
    (one page of students per request), matching this plan's own
    "document the shortcut" allowance rather than building a new
    aggregation layer nothing has asked for yet.
  - **Gotcha the test suite caught**: a `TextColumn` with `formatStateUsing()`
    never runs that callback when `state()` returns `null` — Filament's
    own `TextColumn` render path short-circuits on `blank($state)` and
    renders `getPlaceholder()` instead, never touching
    `formatStateUsing()` at all (confirmed by reading `TextColumn.php`
    directly after a test asserting a hand-rolled "—" fallback inside
    `formatStateUsing()` failed silently). Fixed by using Filament's own
    `->placeholder('—')` instead of a null-check inside the format
    callback — the correct mechanism, not a workaround.
- New `App\Filament\Admin\Widgets\InstituteOverviewWidget`
  (`StatsOverviewWidget`, three `Stat::make()` entries) — auto-discovered
  at `app/Filament/Admin/Widgets` (also didn't exist; `AdminPanelProvider`'s
  own `discoverWidgets()` root), unlike `TokenSpendWidget` which needed
  explicit registration because it lives outside that root.
  - Enrolments: `Enrolment::where('status', 'active')->count()` —
    tenant-scoped for free via `BelongsToTenant`'s global scope.
  - Revenue: `FeeInvoice::where('status', 'paid')->sum('total_amount')` —
    confirmed no existing revenue-summing query anywhere in the app to
    reuse; this is the first one.
  - **At-risk count has no definition anywhere in this codebase or its
    docs** — design/plan both say "students below a coverage/attendance
    threshold" with no number. **60% is this block's own judgment call**,
    same posture `StudentMetrics` (S8 B2) already set for `fee_status`'s
    worst-status-wins rule when it hit the same kind of gap. A student
    with no attendance/coverage data yet (both `null`) is not counted
    at-risk — absence of evidence isn't evidence of struggling.
- Tests added: `tests/Feature/Comms/StudentProgressPageTest.php` (5 cases
  — roster scoping by active enrolment and tenant, placeholder rendering,
  fee-status display) and
  `tests/Feature/Comms/InstituteOverviewWidgetTest.php` (3 cases —
  tenant-scoped enrolment count, paid-only revenue sum, at-risk threshold
  including the "no data yet ≠ at risk" case). 503/504 green — same
  pre-existing `CourseSlugUniquenessTest` failure, out of scope.

### B6 — Attendance CSV report (S3, partial) ✅

- **This plan's own B6 bullet bundles "video ingestion, quiz auto-submit,
  fee reminders" as if they're the same job-queue precedent — they
  aren't.** Read every scheduled job class (`bootstrap/app.php`'s
  `withSchedule`) before assuming otherwise: `AutoSubmitExpiredQuizAttempts`
  and `SendFeeReminders` are plain classes, run synchronously in-process
  by the cron scheduler, never touching the real Redis queue.
  **`docs/FEATURES.md`'s T2 row ("background job queues by priority,
  retry with backoff") is only actually exercised by the AI ingestion
  chain** (`TranscribeLesson` → `ChunkTranscript` → `ExtractLessonConcepts`
  → `EmbedLessonChunks`, dispatched via `Bus::chain(...)->dispatch()`).
  `App\Domains\Comms\Jobs\ExportAttendanceRegister` copies that chain's
  actual shape — `implements ShouldQueue`, `use Dispatchable,
  InteractsWithQueue, Queueable, SerializesModels`, a nullable `tenant_id`
  constructor property, and `App\Support\Tenancy\RebindsTenant` as job
  middleware (`middleware(): [new RebindsTenant]`) rather than an inline
  `CurrentTenant::set()` call — not the scheduled-jobs' pattern, which
  this block's own citation pointed at by mistake.
- CSV built with plain `fputcsv()` against a `php://temp` stream — no new
  library. `league/csv` is physically present in `vendor/` (pulled in
  transitively by `filament/actions`, which also ships its own
  `Exporter`/export-job scaffolding) but isn't a direct dependency and
  isn't used anywhere in this app; reaching for either would have meant a
  new dependency and, for Filament's own exporter, new bookkeeping tables
  — directly against this block's own "no new schema" instruction.
  Confirmed nothing else in the app writes CSV before building this.
- **No new schema tracks export readiness, per this block's own
  instruction** — `AttendanceExport` (new `App\Filament\Admin\Pages`)
  checks `Storage::disk('local')->exists(ExportAttendanceRegister::pathFor(...))`
  directly rather than a status column. Matches how this app already
  surfaces an async result with zero polling anywhere (confirmed by
  reading `IngestionStatusUiTest` before assuming polling infrastructure
  existed): the AI ingestion status badge is just a computed column
  re-read on the next render, no `wire:poll` anywhere in the codebase.
  Same posture here — click Generate, refresh to see Download appear.
- Storage: `local` disk (`storage_path('app/private')`, not web-
  accessible), not `public` — this CSV carries student names and emails.
  `App\Http\Controllers\Comms\DownloadAttendanceExportController` is the
  only way to read it back, gated exactly like `DownloadInvoiceController`
  (S7 B7): no `auth` middleware at the route level, one `abort_unless`
  doing auth + role + tenant cross-check together. Route lives in
  `routes/comms.php` (`GET /exports/attendance-register`), not nested
  under `/admin` — that path belongs to the Filament panel's own routing,
  not this plain route file.
- **Two real bugs found by the test suite, both fixed before marking this
  done**:
  1. `Storage::disk('local')->download()` returns a Symfony
     `StreamedResponse`, not `Illuminate\Http\Response` — the controller's
     original return-type hint (`Illuminate\Http\Response`) caused a
     `TypeError` on every real download. Fixed by widening to
     `Symfony\Component\HttpFoundation\Response`, the actual common
     parent.
  2. The CSV's "context" column originally read a `BatchSession`'s own
     `title` only — but that column is frequently blank
     (`BatchSessionsRelationManager`'s own table already shows "Untitled
     session" as its placeholder), while the session's *linked lesson*
     title (the live-class topic) is what a reader actually wants. Fixed
     to prefer `$session->lesson?->title` when the session's own title is
     blank, caught by a test asserting the topic name ("Live Q&A")
     actually appeared in the row.
- Tests added: `tests/Feature/Comms/ExportAttendanceRegisterTest.php` (3
  cases — CSV row shape, video-coverage label, tenant scoping),
  `tests/Feature/Comms/DownloadAttendanceExportControllerTest.php` (5
  cases — 404-before-generation, successful download, guest/wrong-role/
  cross-tenant 403s), `tests/Feature/Comms/AttendanceExportPageTest.php`
  (2 cases — download action hidden until generated, then visible).
  513/514 green — same pre-existing `CourseSlugUniquenessTest` failure,
  out of scope.

### B7 — In-app notification centre (N3) ✅

- **`docs/FEATURES.md`'s own N3 row asks for more than this block builds**
  — its literal wording is "mark read / mark all read, *per-category
  preferences*," but design doc §2.4 decision 8 already narrowed this
  before B7 started to a plain read view, no preferences UI. Built to
  that narrowed decision, not FEATURES.md's fuller wording — same
  "the design doc gates scope, FEATURES.md is the aspirational master
  list" posture every prior slice has used.
- New `App\Livewire\NotificationCentre` — one component, not three
  panel-specific ones. Confirmed nothing in this app already turns on
  Filament's own built-in `->databaseNotifications()` bell (grepped all
  three `PanelProvider`s) — could have flipped that on for the 3 staff
  panels and built a second, separate component for the 2 portals, but
  the plan's own wording ("shared component, mounted on... staff panels
  alike") reads as one component reused everywhere, not two different
  mechanisms, so that's what got built.
  - Portals (`student.dashboard`/`parent.dashboard`): `<livewire:notification-centre />`
    added directly to each layout, next to the existing `<livewire:student-portal
    />`/`<livewire:parent-portal />` mount points — no shared header
    partial existed to hook into (confirmed neither portal Blade file has
    ever had one), and building one just to carry a bell would be more
    machinery than the two-small-duplicated-templates posture these two
    pages already use.
  - Staff panels (`/admin`, `/teach`, `/super`): **this codebase's first
    use of a Filament render hook** — one unscoped
    `FilamentView::registerRenderHook(PanelsRenderHook::TOPBAR_END, fn ()
    => Blade::render('<livewire:notification-centre />'))` call in
    `AppServiceProvider::boot()`. Unscoped (no third `$scopes` argument)
    applies to every panel Filament renders, confirmed by reading
    `ViewManager::registerRenderHook()`'s own default (`$scopes ??=
    ['']`, the global scope) — one registration site, not three
    near-identical calls per `PanelProvider`.
- Ownership is enforced at the query layer, not by a separate
  authorization check: `markAsRead($id)` resolves the target row through
  `Auth::user()->notifications()->whereKey($id)->first()`, never
  `DatabaseNotification::find($id)` directly — an id belonging to another
  user's notification simply matches nothing and silently no-ops. Same
  self-securing-by-scoping pattern `ParentPortal::selectStudent()` (S8
  B2) already established. `markAllAsRead()` goes through
  `Auth::user()->unreadNotifications` (Laravel's own scoped relation),
  never a raw `DatabaseNotification::where(...)->update(...)`.
- `summarize()` renders a short human line per known notification type
  (`AbsenceAlertDue`, `FeeReminderDue`, `EnrolmentRequestExpired` — the
  three that currently write to the `database` channel), falling back to
  the bare class basename for anything else — new types added later (a
  future `AttendanceRecorded`-adjacent alert, say) degrade to a readable
  label automatically rather than needing this file touched every time.
- Kept the shared view's own Blade template equally bare (no CSS classes,
  no styling) — confirmed neither portal layout loads Tailwind/`@vite`
  at all (only `@livewireStyles`/`@livewireScripts`), so styling it would
  create a false expectation there while doing nothing visually; the
  Filament-panel rendering picks up Filament's own theme for free
  regardless of markup, so no styling was needed there either.
- **Real bug found by the test suite, fixed before marking this done**:
  the first version of `NotificationCentreMountedTest`'s shared
  `beforeEach` bound `CurrentTenant` to a `physics` tenant for every test
  in the file, including the super-panel one — `/super` sits on the bare
  `lms.test` domain with no `ResolveTenant` middleware to rebind or clear
  it, so the stale `physics` binding leaked into `EnsureRole`'s
  cross-tenant check against a `tenant_id => null` super admin and 403'd.
  Fixed by not pre-binding `CurrentTenant` in that file at all — none of
  its fixtures actually need it (every `User::factory()->create()` call
  already passes `tenant_id` explicitly), matching `PanelAccessTest`'s
  own established pattern of leaving tenant resolution entirely to the
  real `ResolveTenant` middleware during each HTTP request.
- Tests added: `tests/Feature/Comms/NotificationCentreTest.php` (6 cases
  — per-type summaries, unknown-type fallback, guest renders nothing,
  mark-one-read, cross-user mark-read is a no-op, mark-all-read scoped to
  the caller only) and
  `tests/Feature/Comms/NotificationCentreMountedTest.php` (5 cases — one
  per surface: student portal, parent portal, `/admin`, `/teach`,
  `/super`). 524/525 green — same pre-existing `CourseSlugUniquenessTest`
  failure, out of scope.

### B8 — Full test coverage, isolation registration, review pass ✅

- Confirmed `ParentGuardianLink` in `TenantModelRegistry` — `use` import,
  `MODELS` array, `seedAttributes()`, `updateAttributes()`, all present
  since B0 (checked by grep, not re-added).
- `composer test` green throughout; `CrossTenantTest`'s generated suite
  already covers `ParentGuardianLink` (confirmed passing, part of the
  Isolation suite in every full run).
- Full read-through review pass: dispatched a fresh-eyes agent (no memory
  of having built this slice) to reread every file B0-B7 touched or
  created — models, migrations, jobs, listeners, notifications, Filament
  resources/pages/widgets, Livewire components, routes, and every test
  file — against `CLAUDE.md`'s two most load-bearing rules for this slice
  (tenant isolation, domains-talk-through-Events-only), plus 10 specific
  risk areas informed by this slice's own known-tricky decisions. Full
  findings in "Post-S8 review" below.

---

## Post-S8 review

Fresh-eyes review pass (one agent, no memory of having built S8) found 5
findings, ranked by severity. Two were fixed with regression tests; two
were flagged and deliberately left, matching this codebase's own
established "flag, don't fix" posture for out-of-scope-but-real gaps;
one was investigated further and turned out to be a **false positive**
— fixed for the wrong reason, then corrected once a failing test proved
the original code was already safe.

**1. Fixed — `ParentGuardianResource` cross-tenant student link, then
corrected once verified as a false positive.** The review's first pass
claimed Filament's `Select` component doesn't validate a submitted value
against its own `options()` closure, and that a tampered Livewire
request could link a parent to another tenant's student past the
UI-only dropdown filter. A server-side `ParentGuardianResource::tenantStudentIds()`
re-check was added to both `CreateParentGuardian`/`EditParentGuardian`,
with a regression test simulating the tampered submission. **The
regression test itself then failed** — `assertHasNoFormErrors()` didn't
hold, because Filament's `Select` *does* validate a submitted value
against a dynamic `options()` closure's result, producing "The selected
student is invalid." before `handleRecordCreation()`/`handleRecordUpdate()`
ever run. The extra server-side check was dead code defending against a
scenario Filament's own framework guarantee already closes — reverted
(matches CLAUDE.md's "don't add validation for scenarios that can't
happen"), and the two regression tests were corrected to assert the
actual, already-correct behavior (`assertHasErrors()`, zero rows
created/original link untouched) instead of the wrong one the initial
fix assumed. Recorded directly in `ParentGuardianResource::form()`'s own
docblock so a future reader doesn't reach for the same fix a second
time. This is the review process itself practicing the same "verify,
don't assume" discipline applied to every design-doc claim across B0-B7
— including a claim this review pass made about itself.

**2. Fixed — `parent_student_links.tenant_id` was missing
`cascadeOnDelete()`.** The design doc's own §3.1 SQL specifies `ON
DELETE CASCADE`; the B0 migration omitted it (18 of this codebase's 20
other `tenant_id` foreign keys cascade — the only other exception,
`role_assignment_audits`, is presumably intentional for an audit trail).
Currently unreachable (`Tenant` isn't deletable anywhere in this app
yet), but a one-line fix matching both the spec and the established
convention, so fixed directly in the B0 migration rather than left for
whenever tenant deletion eventually ships.

**3. Fixed — CSV formula injection in the attendance export.**
`ExportAttendanceRegister::buildCsv()` wrote student names/emails/lesson
titles into CSV cells unescaped — a name starting with `=`, `+`, `-`, or
`@` becomes a live formula the moment an institute_admin opens the file
in Excel/Sheets (CWE-1236). This is the app's first CSV export (nothing
existing to have copied the gap from), and students can already
self-register their own name, so this was realistically triggerable,
not hypothetical. Fixed with a `csvSafe()` helper (leading apostrophe on
any cell starting with those four characters) applied to every
user-supplied cell, covered by a regression test using a `=SUM(1+1)`
student name.

**4. Flagged, not fixed — `NotificationCentre` could briefly render a
cross-tenant parent's own notifications before `ParentPortal`'s
tenant-mismatch logout took effect.** Both components mount
independently on `parent/dashboard.blade.php`; Livewire renders root
components in template order on a full page load, and
`<livewire:notification-centre />` was listed *before*
`<livewire:parent-portal />` — so on a cross-tenant session hop (the
`SESSION_DOMAIN`-shared-cookie scenario `ParentPortal::mount()` exists
to guard against), `NotificationCentre::render()` executed and produced
HTML before that same request's `ParentPortal::mount()` invalidated the
session. Not a cross-*user* leak (`notifications` is scoped by
`notifiable_id`, so it's still that user's own data), and no exploitable
mutation results (any follow-up mark-read rides the now-invalidated
cookie) — but the intended "this session shouldn't be here at all"
guarantee wasn't actually uniform across the two sibling components.
**Fixed by reordering**, not left flagged: swapped the two `<livewire:>`
tags in both `parent/dashboard.blade.php` and (for consistency, though
`StudentPortal` has no equivalent check yet) `student/dashboard.blade.php`,
so the tenant-mismatch check always runs first. Covered by a new
regression test in `NotificationCentreMountedTest.php` asserting a
cross-tenant parent session sees no notification content and ends up
logged out.

**5. Flagged, deliberately deferred — narrow TOCTOU on concurrent
attendance-export writes.** `Storage::disk('local')->put()` isn't
atomic on the local Flysystem adapter; two "Generate" clicks for the
same tenant close together dispatch two jobs writing the same path, and
a `download()` racing exactly against an in-flight `put()` could in
theory read a torn file. The whole CSV is built fully in memory before
one `put()` call (not streamed incrementally), so the window is narrow,
and nothing currently makes concurrent generation likely (a single
institute_admin clicking a button once). Fixing this properly (atomic
temp-file-then-rename writes) would be a bigger change to B6's
"no new schema, storage-based" design than this pass's own scope — left
as a known, narrow gap rather than fixed speculatively, matching this
codebase's posture for every other out-of-scope-but-real finding in
prior slices' own review passes.

**Checked and ruled out** (re-verified fresh, not just re-asserted from
each block's own build-time notes): `RecordVideoCoverageAttendance`
truly never produces `status='absent'`, so `SendAbsenceAlert`'s early
return is airtight against it; `RoleHierarchy::LEVELS['parent']`'s B0
split affects no other call site (`RoleAssignmentPolicy` only, already
tested); the Filament render hook (`TOPBAR_END`) never renders on any
panel's login page, so `NotificationCentre` can't error there;
`StudentMetrics`'s two staff-facing call sites (`StudentProgress`,
`InstituteOverviewWidget`) both filter their own `User::role('student')`
query by tenant before ever calling it — the only gap was finding #1's
own (ultimately false-positive) concern about the parent-facing path.

Tests green: **528/529** (same 1 pre-existing `CourseSlugUniquenessTest`
failure carried since S1/S2, still out of scope, never touched by this
slice).

**Demo #8 target: 2026-11-28** — a parent (admin-created, linked to a
student in one action) logs in, sees that student's attendance/coverage/
quiz/fee summary; the student is marked absent in a live session and the
linked parent receives a (fake) WhatsApp alert same-day, deduped against
a second same-day mark; a fee reminder now also carries a WhatsApp
channel (real send is a no-op today — no student has a phone number
anywhere in this app, flagged in B4, not fixed); an instructor sees a
per-student progress table and an admin sees enrolment/revenue/at-risk
at-risk counts, both reading `StudentMetrics` directly; an admin generates
and downloads a real attendance-register CSV; every surface (2 portals +
3 staff panels) carries the same minimal notification bell. This is the
eighth and final slice of the P1 "Institute Zero" roadmap —
`docs/PROJECT-STATUS.md`'s "What's left" section closes out next.
