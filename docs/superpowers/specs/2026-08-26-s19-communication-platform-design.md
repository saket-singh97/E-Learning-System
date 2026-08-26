# Design: S19 Communication Platform

## 1. Why this document exists

Locks scope and architecture for S19 before any block is built, same role
S9-S18's own design docs played. Full block-by-block build log lives in the
companion `2026-08-26-s19-communication-platform-plan.md`.

S19 is the first "planned-future" slice (`docs/ROADMAP.md`'s post-S18 table)
to get a real design. Roadmap draws it from `docs/FEATURES.md` §N11-N18 — 8
items as counted there. Same "verify before building" discipline as every
prior slice: **real build scope is 6 items**, not 8 (§2.2).

## 2. Scope

### 2.1 In scope — 6 items

| # | Item | Source | Phase |
|---|---|---|---|
| N11 | Real-time delivery via WebSockets, private channels, reconnect with catch-up | REQ-25 | P2 |
| N12 | Study planner + calendar + iCal export | REQ-42 | P2 |
| N14 | General forum (beyond batch discussion) | INF | P3 |
| N15 | Feedback surveys / custom forms with aggregation + CSV export | REQ-55, INF | P2 |
| N16 | Help desk / support tickets with SLA tracking | REQ-57, INF | P2 |
| N18 | Gamification: XP, badges, streaks, levels, leaderboards | REQ-23, FRP | P3 |

`docs/ROADMAP.md`'s own S19 headline names exactly these 6 ("real-time
WebSockets, study planner + calendar, general forum, feedback surveys, help
desk, gamification") — same "headline explicitly names it, it stays"
precedent S16 used to keep P3 items in scope.

### 2.2 Out of scope, and why

- **N13 (Google Calendar sync)** — not named in the roadmap headline, P3.
  Same "not in the headline, not in scope" boundary S16/S17/S18 all drew.
  N12 ships iCal *export* (a subscribable feed any calendar app, including
  Google Calendar, can consume read-only) — two-way OAuth sync is a
  materially bigger integration (token storage, write-back conflicts) for a
  feature nobody named in this slice's own headline.
- **N17 (1-on-1 doubt appointment booking)** — same reasoning, not in the
  headline, P3. The actual messaging once booked would reuse the existing
  `DirectMessage` model (Comms); only the booking/scheduling layer is new,
  and nothing in this slice needs it as a prerequisite.

### 2.3 Verified before writing this design (grepped/read, not assumed)

- **No broadcasting driver is configured anywhere.** `.env.example` has
  `BROADCAST_CONNECTION=log` (Laravel's no-op driver — logs the event,
  delivers nothing). No `routes/channels.php` exists. No
  `laravel/reverb`/`pusher`/`ably` package in `composer.json`. N11 is
  genuinely greenfield infrastructure, not a misconfigured existing one.
- **A forum already exists, but it's hard-scoped to one batch.**
  `app/Domains/Comms/Models/ForumThread.php` / `ForumPost.php` (S13) — the
  migration's `batch_id` is `foreignId(...)->constrained()->cascadeOnDelete()`,
  **not nullable**. `ForumPostComposer` (Comms) is the single write chokepoint
  enforcing the existing depth-3 reply limit. N14 ("beyond batch discussion")
  needs a genuinely institute-wide thread, which the current schema cannot
  express — confirmed by reading the migration directly, not assumed from the
  model.
- **No survey, help-desk/ticket, or gamification concept exists anywhere in
  `app/`** — each grepped individually (`survey`, `helpdesk|SupportTicket`,
  `gamification|badge|streak|leaderboard`); all three return zero real
  matches. Genuinely greenfield, same as S16's geofence/OMR/proctoring
  research pass.
- **The `Analytics` domain already exists (S18)** and already computes
  per-student engagement signals — `EngagementMetricsService` (engagement
  ratio + speed), and per-student aggregates already feed
  `MonthlyReportCard`. N18's XP/streaks are a natural extension of "how
  engaged is this student," not a reason to invent a second domain that
  recomputes the same underlying signals a different way.
- **No student-facing personal calendar/planner concept exists.** Grepped
  `calendar|iCal` — the handful of substring hits are false positives inside
  unrelated words (`physical`, `vertical`); nothing calendar-shaped is wired
  today. Session/due-date data *does* already exist and is queryable
  (`BatchSession.starts_at`, `Assignment.due_at`, quiz open/close windows) —
  N12 aggregates these at read time rather than duplicating them (§4
  decision 3).
- **This app's dev environment is native Windows** (per `HANDOVER.md`'s own
  environment notes: native PHP 8.4, `ext-pcntl` faked). Laravel Reverb (the
  first-party WebSocket server this design picks, §4 decision 1) runs as a
  long-lived `artisan reverb:start` process — officially Linux/Docker-first,
  works but needs its own terminal/process on Windows dev, same class of
  "flagged, not solved" dev-environment friction `SETUP.md` already documents
  for Redis/Docker DB. Doesn't block the design; noted so B0 isn't a
  surprise.

## 3. Architecture

### 3.1 Domain ownership — one new domain

- **`Comms`** (extended, no new domain) — N11's broadcasting/channel-auth
  plumbing (it's fundamentally "how does a user get told something
  changed," this domain's existing charter via `NoticeBoard`/
  `DirectMessages`/`MessageReportResource`); N14's general-forum thread
  (extends `ForumThread`, doesn't duplicate it); N15's survey builder +
  responses; N16's help-desk tickets + replies (same
  workflow-with-status-actions shape `MessageReportResource` already
  established).
- **`Analytics`** (extended, from S18) — N18's gamification profile/XP/
  streak/badges, built as listeners on existing domain events
  (`AttendanceRecorded`, quiz submission, assignment submission, lesson
  watch) the same way `EngagementMetricsService` already reads them.
- **`Planner`** (**new domain**) — N12's calendar/planner. Genuinely new
  business surface (a personal, cross-domain aggregated view + user-owned
  custom events) that doesn't fit any existing domain's charter — same bar
  `Operations` (S15) and `Billing` (S11) cleared when they were introduced.
  Read-only aggregator over `Batches`/`Assignments`/`Assessments`, plus one
  small owned table for custom events.

### 3.2 New schema

```
-- Comms: N11 (no new table — broadcasting config + routes/channels.php only)

-- Comms: N14 (extends existing table, no new one)
forum_threads
  batch_id: constrained NOT NULL -> nullable, cascadeOnDelete -> nullOnDelete
  -- null batch_id = general, institute-wide thread (opt-in per thread,
  -- same "absence of a value turns a feature off" shape batch_geofences
  -- (S16) already established)

-- Comms: N15
surveys (Comms — N15)
  id, tenant_id, title, description (nullable), status (draft|published|closed),
  created_by_user_id, timestamps

survey_questions (Comms — N15)
  id, tenant_id, survey_id, prompt, type (text|single_choice|multi_choice|rating),
  options (jsonb, nullable — choice/rating configs), position, timestamps

survey_responses (Comms — N15)
  id, tenant_id, survey_id, respondent_id, submitted_at, timestamps
  -- unique (survey_id, respondent_id): one response per respondent

survey_answers (Comms — N15)
  id, tenant_id, survey_response_id, survey_question_id, value (jsonb), timestamps

-- Comms: N16
help_desk_tickets (Comms — N16)
  id, tenant_id, requester_id, subject, body, status
  (open|in_progress|resolved|closed), priority (low|normal|high),
  assigned_to_user_id (nullable), sla_due_at, resolved_at (nullable), timestamps

help_desk_ticket_replies (Comms — N16)
  id, tenant_id, ticket_id, author_id, body, is_internal_note (boolean,
  default false — staff-only note vs. a reply the requester also sees),
  timestamps

-- Planner: N12
planner_events (Planner — N12)
  id, tenant_id, user_id, title, description (nullable), starts_at,
  ends_at (nullable), timestamps
  -- custom events only; batch sessions/assignment due dates/quiz windows
  -- are read at query time from their own tables, never copied in here
  -- (§4 decision 3)

-- Analytics: N18
gamification_profiles (Analytics — N18)
  id, tenant_id, user_id (unique), xp_total (int, default 0), level (int,
  default 1), current_streak_days (int, default 0), longest_streak_days
  (int, default 0), last_activity_date (date, nullable), timestamps

badges (Analytics — N18)
  id, tenant_id, key, name, description, icon, xp_threshold (nullable —
  auto-awarded at this XP total) OR criteria (jsonb — e.g. streak length),
  timestamps

student_badges (Analytics — N18)
  id, tenant_id, user_id, badge_id, awarded_at, timestamps
  -- unique (user_id, badge_id): a badge is awarded once
```

### 3.3 New `BelongsToTenant` models (all registered in `TenantModelRegistry`)

`Survey`, `SurveyQuestion`, `SurveyResponse`, `SurveyAnswer`,
`HelpDeskTicket`, `HelpDeskTicketReply`, `PlannerEvent`,
`GamificationProfile`, `Badge`, `StudentBadge` — **10 new models**.
`ForumThread` is not new (extended in place).

## 4. Design decisions

1. **Laravel Reverb, not Pusher/Ably/a third-party SaaS.** First-party,
   open-source, no per-message billing, same "reuse the framework's own
   ecosystem before reaching for a vendor" posture `IdCardGenerator` (S15,
   reusing `dompdf`) and `OmrScanner` (S16, reusing bundled `ext-gd`) both
   already took. N11's own scope (reconnect-with-catch-up) is Reverb's own
   `presence`/private-channel replay behavior, not a bespoke queue this app
   has to build.
2. **N11 ships as infrastructure plus a small number of concrete
   integrations, not a blanket retrofit of every existing polling feature.**
   `DirectMessages`, the new general forum (N14), and help-desk ticket
   replies (N16) get real push in this slice; existing poll-based surfaces
   (`NoticeBoard`, `PhotoDoubtUpload`) keep polling — retrofitting every one
   is a separate, unrequested scope expansion, same "smallest version that
   delivers the value" cut this design applies elsewhere.
3. **N12's calendar is computed at read time, not materialized.** A
   `PlannerAggregator` service reads `planner_events` (custom, user-owned)
   alongside `BatchSession.starts_at` (for batches the viewer is enrolled
   in), `Assignment.due_at`, and quiz open/close windows, merging them into
   one ordered view — same "no materialized aggregate column, computed at
   read time" posture `TestSeriesRankingService` (S16) already established
   for a differently-shaped but equally read-heavy aggregate. Nothing
   duplicates an existing due-date into `planner_events`.
4. **iCal export is a signed, per-user feed URL, not an authenticated
   endpoint.** Calendar apps (Google Calendar, Apple Calendar, Outlook) poll
   a plain URL on their own schedule with no session/auth header support —
   same signed-URL shape `MonthlyReportCard`'s WhatsApp download link (S18)
   already uses for the identical "an external, unauthenticated client needs
   to fetch this" problem. A leaked feed URL exposes only that one user's
   own aggregated schedule, never another tenant's or student's.
5. **N14's general forum reuses `ForumThread`/`ForumPost`/`ForumPostComposer`
   wholesale — a nullable `batch_id`, not a parallel table set.** The
   depth-3 reply limit, the single-writer-service chokepoint, and every
   existing batch-forum test's own assumptions about post structure carry
   over unchanged; only thread *visibility* differs (every tenant user
   instead of one batch's enrolled roster).
6. **N15's surveys are a lighter, parallel shape to `Question`/`Quiz`, not a
   reuse of the Assessments tables.** A survey response isn't graded, has
   no `correct_answer`, and needs an `is_internal_note`-free open/closed
   lifecycle a quiz attempt doesn't have — forcing it through
   `Question`/`QuizAttempt` would mean nullable-everything contortions on a
   grading-shaped table for a fundamentally non-graded concept. Structurally
   similar (question types, position-ordered, jsonb options) by
   convention, not by inheritance.
7. **N16's SLA is a computed `sla_due_at` timestamp set once at ticket
   creation, not a live-recalculated countdown.** `sla_due_at = created_at +
   config('comms.help_desk_sla_hours')[priority]` (new `config/comms.php`
   entry, tenant-independent for this slice — a per-tenant SLA override is
   a real but unrequested future refinement, same "single hardcoded value,
   no per-institute override yet" posture H10's *starting* point took before
   S16 gave it one). A background sweep (same "unscoped daily job" shape
   `TeacherAttendanceSweep` (S16) already established) flags tickets past
   `sla_due_at` still `open`/`in_progress`, notifying the assigned staff
   member (or every institute_admin if unassigned).
8. **N18's XP/streaks are driven by existing domain events via listeners,
   not a rewrite of the domains that raise them.** `AttendanceRecorded`,
   quiz-submission, and assignment-submission events each get one new
   listener in `Analytics` that awards a fixed XP amount and updates
   `last_activity_date`/streak — no existing service gains gamification
   logic inline, same "domains talk through events only" rule this
   CLAUDE.md already states as load-bearing. Badges are awarded by a
   `BadgeEvaluator` run after every XP award, checking each tenant's own
   badge catalog against the updated profile — not one giant if/else per
   badge type.
9. **Leaderboards are computed at read time, scoped per-batch by default,
   with a tenant-level opt-out.** `tenants.gamification_leaderboard_enabled`
   (boolean, default true) — an institute that considers peer-ranking
   visibility inappropriate for its own students can turn it off tenant-wide
   without losing XP/badges themselves (those stay private-profile-only).
   No materialized rank column, same read-time-computation precedent as
   decision 3 and `TestSeriesRankingService` (S16).

## 5. Testing strategy

- **Unit/feature per service**: `PlannerAggregator`, `BadgeEvaluator`, the
  XP-award listeners, survey aggregation/CSV export, the SLA sweep — each
  gets a dedicated test file.
- **Cross-tenant isolation**: all 10 new `BelongsToTenant` models register
  in `TenantModelRegistry`, auto-generating `CrossTenantTest` coverage.
- **Broadcasting**: private-channel authorization callbacks tested via
  Laravel's own broadcasting test helpers (a user not enrolled in a batch,
  not the ticket's requester/assignee, etc. is refused the channel) —
  channel auth is the actual security boundary here, same weight this app's
  policy tests already give `Policies/`.
- **Forum**: an existing batch-forum test suite must stay green unchanged
  (regression guard on the nullable-`batch_id` migration); new tests cover
  a general thread visible tenant-wide and invisible cross-tenant.
- **Calendar**: aggregator merges custom events + batch sessions +
  assignment due dates correctly for one user, excludes another tenant's/
  student's data; iCal feed round-trips through a real `.ics` parser to the
  expected event set; a leaked feed URL for user A never resolves to user
  B's events.
- **Gamification**: XP awarded exactly once per qualifying event (no
  double-award on a retried job), streak increments on consecutive-day
  activity and resets on a missed day, a badge auto-awards exactly once
  when its criteria is first met, leaderboard respects the tenant-level
  opt-out.
- Rough anchor, not a target, same posture every prior slice's own estimate
  undershot — expect 70-100 new tests across the slice by its own final
  block (larger than S16-S18 given N11's channel-auth surface + N15/N16's
  two full new CRUD-with-workflow subsystems).
