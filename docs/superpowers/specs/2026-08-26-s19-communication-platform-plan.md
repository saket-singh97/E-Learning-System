# Implementation Plan: S19 Communication Platform

Full design: `2026-08-26-s19-communication-platform-design.md`. This is the
block-by-block build log — update the PROGRESS table as each block lands,
same as every S1-S18 plan did. 17 blocks (B0-B16), finer-grained than the
6-item real build scope since N14/N15/N16/N12/N18 each split into
independent blocks (general forum split in two, surveys split in three,
help desk split in three, planner split in three, gamification split in
two), plus one shared broadcasting-foundation block, a registry sweep, and
a final review block.

---

## Per-block build command

Use this for every block, swap `0` for block number:

```
Read docs/superpowers/specs/2026-08-26-s19-communication-platform-design.md and
docs/superpowers/specs/2026-08-26-s19-communication-platform-plan.md, then build
Block 0 of the S19 plan. Update the PROGRESS table and check off that
block's tasks when done, run composer test before marking it complete, then
stop and report before starting the next block.
```

---

## PROGRESS — read this first, update it last

| Block | What | Status |
|---|---|---|
| B0 | N11 pt.1 — `laravel/reverb` install + broadcasting config + `routes/channels.php` foundation + one proven private channel | ✅ done |
| B1 | N11 pt.2 — real-time push wired into `DirectMessages` (first concrete integration, proves the channel-auth pattern) | ✅ done |
| B2 | N14 pt.1 — `forum_threads.batch_id` nullable migration + `GeneralForum` Livewire component (list/create institute-wide threads) | ✅ done |
| B3 | N14 pt.2 — real-time new-post push for both batch and general forum threads (reuses B0/B1 infra) | not started |
| B4 | N15 pt.1 — `surveys`/`survey_questions` schema + Admin-panel survey builder (mirrors `QuestionResource`'s shape) | not started |
| B5 | N15 pt.2 — `survey_responses`/`survey_answers` schema + student-facing `SurveyRunner` Livewire + submission | not started |
| B6 | N15 pt.3 — per-question aggregation view + CSV export (reuses the existing export-job pattern) | not started |
| B7 | N16 pt.1 — `help_desk_tickets`/`help_desk_ticket_replies` schema + student-facing "raise a ticket" flow | not started |
| B8 | N16 pt.2 — staff ticket queue Filament resource (assign/reply/resolve, internal notes) | not started |
| B9 | N16 pt.3 — SLA sweep (flags breached tickets, notifies) + real-time push for new replies | not started |
| B10 | N12 pt.1 — `Planner` domain scaffold + `planner_events` schema + `PlannerAggregator` service | not started |
| B11 | N12 pt.2 — student-facing calendar Livewire view + custom event CRUD | not started |
| B12 | N12 pt.3 — signed iCal feed export | not started |
| B13 | N18 pt.1 — gamification schema + XP-award listeners on existing events + streak computation | not started |
| B14 | N18 pt.2 — student-facing XP/level/badges/streak widget + per-batch leaderboard + tenant opt-out | not started |
| B15 | `TenantModelRegistry` sweep — all 10 new `BelongsToTenant` models confirmed registered | not started |
| B16 | Full test coverage, lifecycle E2E, review pass, `PROJECT-STATUS.md`/`HANDOVER.md`/`ROADMAP.md` update | not started |

**Test suite baseline:** 1517+/1518+ passing at slice start per S16/S17/S18's
own final counts (1 pre-existing, unrelated `CourseSlugUniquenessTest`
failure carried since S1/S2, out of scope here same as every prior slice).
Design doc §5 estimates ~70-100 new tests by B16 — a rough anchor, not a
target.

---

## Block detail (starting checklist — expand into a build narrative as each block actually lands, same as every prior plan file)

### B0 — N11 pt.1: broadcasting foundation — done

- `composer require laravel/reverb` (v1.11.1, pulls in `pusher/pusher-php-server`
  + `react/*` + `ratchet/rfc6455`). `php artisan reverb:install` published
  `config/broadcasting.php` + `config/reverb.php`, generated
  `routes/channels.php`, and added `channels: __DIR__.'/../routes/channels.php'`
  to `bootstrap/app.php`'s `withRouting()` — Laravel 13's own no-provider
  wiring (`ApplicationBuilder::withRouting()` forwards a `channels` string
  straight to `withBroadcasting()`, which registers `/broadcasting/auth`
  under a plain `'web'`-middleware route group with no `Route::domain()`
  wrapper — same "reachable on any host, tenant-lenient" shape Livewire's
  own `/livewire/update` endpoint already established (CLAUDE.md), so no
  extra tenant-routing work was needed here).
- `.env`'s `BROADCAST_CONNECTION` reverted `reverb` → `log` after install
  (the installer sets it live, but design doc §2.3/plan checklist both
  call for `log` as the local/test default — a queued broadcast job would
  otherwise try to reach a Reverb server that isn't running by default).
  `.env.example` gained the `REVERB_*` variable stubs (empty, documented)
  so other devs know they exist without forcing the driver switch.
  `SETUP.md` gained a `§6a` note on running `artisan reverb:start` in its
  own terminal when real-time push actually needs exercising locally.
- `routes/channels.php` — kept the installer's own default
  `App.Models.User.{id}` channel (Laravel's own broadcast-notification
  convention, harmless to leave) and added the plan's own `user.{id}`
  private channel, authorizing `(int) $user->id === (int) $id` — every
  later real-time block (B1, B3, B9) authorizes against this one.
- New tests (3, `tests/Feature/Comms/BroadcastingChannelAuthTest.php`):
  matching user authorized, a different user's channel refused, an
  unauthenticated request refused. Real gotcha hit writing these:
  `BroadcastManager` only requires `routes/channels.php` **once, at boot**,
  against whatever the *then*-default driver was (`null`, under
  `phpunit.xml`'s own test override) — `LogBroadcaster`/`NullBroadcaster`'s
  `auth()` are both complete no-ops, so a test overriding
  `broadcasting.default` to `reverb` *after* boot got an empty channel
  registry on the freshly-resolved `PusherBroadcaster`-backed driver
  instance (Reverb reuses `PusherBroadcaster` wholesale — confirmed by
  reading `BroadcastManager::createReverbDriver()`, which just calls
  `createPusherDriver()`) and every request 403'd on the "no channel
  pattern matched" fallthrough, not the intended id-mismatch path. Fixed by
  re-`require`-ing `routes/channels.php` in the test's own `beforeEach`
  after the config switch — a test-only quirk (production never switches
  this at runtime), traced by forcing the channel closure itself to throw
  and observing it never even ran.
- `composer test`: 1629/1631 pass. Two failures, both pre-existing/
  environmental, neither caused by this block: the standing
  `CourseSlugUniquenessTest` (carried since S1/S2, every prior slice's own
  sole failure) and `BackupConfigurationTest`'s archive-size assertion —
  `storage/app/private` had grown to 8.6MB/444 files from this session's
  own repeated full-suite reruns (same accumulated-stray-export-file class
  logged repeatedly in S16 B7/B11/B17 and `HANDOVER.md`'s own `## Avoid`
  entry); a bulk-delete of those gitignored files was blocked by this
  session's own safety classifier, so it's left for the user to clear
  (`storage/app/private`, keep `.gitignore`) or wave through explicitly,
  rather than reported as a regression this block introduced.

### B1 — N11 pt.2: real-time push for DirectMessages — done

- `App\Domains\Comms\Events\DirectMessageSent` (`ShouldBroadcastNow`, not
  the queued `ShouldBroadcast` — a chat message shouldn't depend on a queue
  worker being alive): `broadcastOn()` → `PrivateChannel('user.'.$recipientId)`
  (B0's channel), `broadcastAs()` → `'message.sent'`, `broadcastWith()` →
  a minimal `{id, sender_id, sender_name, body, created_at}` payload.
  `DirectMessages::send()` fires it right after `DirectMessage::create()`,
  `setRelation('sender', $viewer)` first so `broadcastWith()` doesn't
  re-query a sender it already has in memory.
- `laravel-echo` + `pusher-js` installed (`npm install`, `resources/js/echo.js`
  new — Reverb speaks Pusher's protocol, so `broadcaster: 'reverb'` reuses
  Echo's dedicated driver for it, imported from `resources/js/app.js`).
  `resources/views/comms/messages.blade.php` (the one real page hosting this
  component) gained the `@vite(...)` tag it never had — without it
  `window.Echo` would never exist on that page at all, silently no-op-ing
  every listener below.
- `direct-messages.blade.php` gained a `@script`/`@endscript` block (guarded
  on `window.Echo` existing, so a host page without the Vite bundle degrades
  to pre-S19 behaviour instead of throwing): `channel.listen('.message.sent', ...)`
  calls a new `receiveMessage()` Livewire method for the live-push path;
  `channel.subscribed(...)` calls a new `catchUp($wire.lastSeenMessageId)`
  for the reconnect path — Echo/Pusher's own `subscribed` hook fires on
  every (re)subscribe, not just the first page load, so a socket that drops
  mid-session and reconnects still recovers.
- `catchUp(int $sinceId)` returns exactly the thread's own messages with
  `id > $sinceId` into a `catchUpMessages` public property (Livewire-testable
  directly, no browser needed). `lastSeenMessageId` (new public property) is
  set in `render()` to the open thread's own newest message id, so the
  client always reads a value that's fresh after any round trip.
  `receiveMessage()` itself is an intentionally empty method body — any
  Livewire call already re-renders `threadMessages()` fresh from the
  database, which is all the live-push path needs.
- New tests (5, `tests/Feature/Comms/DirectMessageBroadcastTest.php`):
  `send()` broadcasts on the exact recipient's own channel with the right
  event name/payload; the channel name is never a different (cross-tenant)
  user's; `lastSeenMessageId` matches the open thread's newest message;
  `receiveMessage()` accepts a call without error. Real bug caught and
  fixed by the catch-up test itself: `catchUp()`'s first draft chained
  `->where('id','>',$sinceId)->where(fn...)->orWhere(fn...)` — the
  `orWhere` branch (the reversed sender/recipient direction) wasn't
  actually ANDed with the id filter at all (SQL's own AND-before-OR
  precedence splits it into `(id>N AND branch1) OR branch2`), so every
  message in that direction leaked past `catchUp()` regardless of
  `$sinceId` — caught by a failing assertion (expected 2 ids, got 3), fixed
  by wrapping the whole thread-membership OR in one outer group so the id
  filter applies to both directions. Noted, not touched: `threadMessages()`
  has the exact same `where(fn)->orWhere(fn)->whereDoesntHave(...)` shape
  and the same latent precedence gap, but `reportMessage()` only ever lets
  a viewer report the *other* party's own messages, so a message the
  viewer sent can never carry an `actioned` report in the first place —
  the gap is real but structurally unreachable through this app's own
  write path, out of this block's scope to touch.
- `composer test`: 1635/1636 pass (5 new tests; sole failure the
  pre-existing unrelated `CourseSlugUniquenessTest`).

### B2 — N14 pt.1: general forum schema + component — done

- Migration `2026_08_26_100000_make_batch_id_nullable_on_forum_threads_table.php`:
  drops the existing FK, `ALTER COLUMN batch_id DROP NOT NULL` (raw DDL —
  doctrine/dbal isn't installed for Schema's `->change()`, same precedent
  `add_program_id_to_fee_invoices_table` already established), re-adds the
  FK with `nullOnDelete()` instead of the original `cascadeOnDelete()` — a
  general thread has no batch to cascade from, and a batch-scoped thread
  should outlive its own batch being deleted rather than vanish with it.
  `ForumThread`'s class docblock updated to document the new meaning of a
  null `batch_id`; no code change needed in the model itself.
- `App\Domains\Comms\Livewire\GeneralForum` — same shape `BatchForum`
  already established (`threads()`/`openThread()`/`createThread()`/
  `reply()`/`postTree()`/`attachReplies()`), scoped to `whereNull('batch_id')`
  instead of one batch, with the enrolment/instructor gate removed
  (`canParticipate()` is just "any authenticated user" — `BelongsToTenant`'s
  own global scope is what actually keeps this tenant-isolated, same as
  every other query in this component). `render()` gates
  `threads()`/`postTree()` behind `canParticipate` from this block's first
  draft, not left for a later review pass to catch the way `BatchForum`'s
  own B21 fix had to.
- Reuses `ForumPost`/`ForumPostComposer` completely unchanged — depth-3
  reply limit, single-write-chokepoint, everything carries over.
- New host page `resources/views/comms/general-forum.blade.php` +
  domain view `general-forum.blade.php`, routed `GET /forum` (`routes/comms.php`,
  no `auth` middleware, same "guest sees a log-in prompt" shape `/messages`
  already takes), registered as `comms.general-forum`
  (`AppServiceProvider`). No `@vite` tag yet — B2 has no real-time piece
  (that's B3); adding it now would be dead weight this block doesn't need.
- New tests (4, `tests/Feature/Comms/GeneralForumTest.php`): visible to any
  authenticated tenant user, not just one who created it; a guest sees no
  thread/post content; a general thread from a different tenant never
  leaks in; the shared depth-limited reply path works through the real
  component. Existing `BatchForumTest`/`ForumPostComposerTest` suites (11
  tests) stay green unchanged — confirmed by running them alongside the
  new file, not assumed. `CrossTenantTest`'s own generated `ForumThread`
  isolation case re-run explicitly to confirm the nullable-column migration
  didn't disturb it.
- `composer test`: 1639/1640 pass (4 new tests; sole failure the
  pre-existing unrelated `CourseSlugUniquenessTest`).

### B3 — N14 pt.2: real-time forum push

- [ ] New `ForumPost` broadcasts on a per-thread channel
      (`private-forum-thread.{threadId}`), authorized by the same
      visibility rule `BatchForum`/`GeneralForum` already enforce (batch
      enrolment, or any tenant user for a general thread).
- [ ] Both `BatchForum` and `GeneralForum` listen and append new posts live.
- [ ] Tests: a viewer not enrolled in the thread's batch is refused the
      channel; a general thread's channel accepts any tenant user, refuses
      cross-tenant.

### B4 — N15 pt.1: survey schema + builder

- [ ] `surveys`/`survey_questions` migrations + models, registered in
      `TenantModelRegistry`.
- [ ] `SurveyResource` (Admin panel) — create/edit a survey and its
      questions, `status` draft→published→closed, same
      institute-authored-CRUD shape `ProgramResource`/`TestSeriesResource`
      already established.
- [ ] Tests: creates a survey with 3 question types, publishes it, a draft
      survey isn't listed for respondents (checked properly in B5).

### B5 — N15 pt.2: response collection

- [ ] `survey_responses`/`survey_answers` migrations + models, registered.
- [ ] `SurveyRunner` Livewire component — lists published surveys the
      viewer hasn't yet responded to, submits answers, enforces the
      `unique(survey_id, respondent_id)` one-response rule.
- [ ] Tests: submits a full response, a second submission attempt is
      refused/updates rather than duplicates (pick one, document it), a
      draft/closed survey isn't offered.

### B6 — N15 pt.3: aggregation + export

- [ ] Aggregation view (Admin panel) — per-question tally (choice types)
      or average (rating type), reusing the same "computed at read time,
      no materialized column" posture as the design doc's other read-heavy
      aggregates.
- [ ] CSV export job, same shape `ExportGradebookCsv`/`GradebookExport`
      already established (one row per respondent).
- [ ] Tests: aggregation produces correct tallies/averages against known
      seeded responses; CSV export contains every response with the right
      columns; a different tenant's responses never appear.

### B7 — N16 pt.1: ticket schema + raise-a-ticket flow

- [ ] `help_desk_tickets`/`help_desk_ticket_replies` migrations + models,
      registered in `TenantModelRegistry`.
- [ ] `config/comms.php` — `help_desk_sla_hours` per priority.
- [ ] Student/staff-facing "raise a ticket" Livewire component — sets
      `sla_due_at` at creation per design doc §4 decision 7.
- [ ] Tests: creates a ticket with the correct computed `sla_due_at` per
      priority, requester can view their own ticket's replies.

### B8 — N16 pt.2: staff queue

- [ ] `HelpDeskTicketResource` (Admin/Teach panel as applicable) — assign,
      reply (respecting `is_internal_note`), change status, same
      workflow-with-status-actions shape `MessageReportResource` already
      established.
- [ ] Tests: assignment, a public reply is visible to the requester, an
      internal note is not, status transitions (open→in_progress→resolved→
      closed).

### B9 — N16 pt.3: SLA sweep + push

- [ ] `HelpDeskSlaSweep` job — same "unscoped daily sweep" shape
      `TeacherAttendanceSweep` (S16) established; flags/notifies on a
      breached `sla_due_at` still open/in_progress.
- [ ] New replies broadcast on `private-help-desk-ticket.{ticketId}`,
      authorized to the requester + assigned staff only.
- [ ] Tests: sweep flags exactly the breached-and-still-open tickets,
      notifies the right recipient (assignee, or every institute_admin if
      unassigned); channel auth refuses an unrelated tenant user.

### B10 — N12 pt.1: Planner domain + aggregator

- [ ] New `app/Domains/Planner/` scaffold (Models/Services/Livewire/Views/
      per CLAUDE.md's standard domain layout).
- [ ] `planner_events` migration + `PlannerEvent` model, registered in
      `TenantModelRegistry`.
- [ ] `PlannerAggregator::eventsFor(User $viewer, DateRange $range)` — reads
      `planner_events` + the viewer's enrolled `BatchSession`s +
      `Assignment.due_at` + quiz open/close windows, merged and sorted, no
      duplication into `planner_events`.
- [ ] Tests: aggregator returns the right merged set for one user, excludes
      another user's/tenant's data, respects the date range.

### B11 — N12 pt.2: calendar UI

- [ ] Student-facing calendar Livewire component (month/week view) backed
      by `PlannerAggregator`.
- [ ] Custom-event CRUD (create/edit/delete a `PlannerEvent`, owner-only).
- [ ] Tests: calendar view renders the merged event set; a user can't
      edit/delete another user's custom event.

### B12 — N12 pt.3: iCal export

- [ ] Signed, per-user feed URL (same shape `MonthlyReportCard`'s WhatsApp
      download link already established) generating a real `.ics` file
      from `PlannerAggregator`'s output.
- [ ] Tests: feed round-trips through a real `.ics` parser to the expected
      event set; an unsigned/tampered URL is refused; a leaked URL for user
      A never resolves to user B's events.

### B13 — N18 pt.1: gamification schema + XP

- [ ] `gamification_profiles`/`badges`/`student_badges` migrations +
      models, registered in `TenantModelRegistry`.
- [ ] Listeners on `AttendanceRecorded`, quiz-submission, and
      assignment-submission events — award fixed XP, update
      `last_activity_date`/streak (increment on consecutive day, reset on a
      missed day).
- [ ] `BadgeEvaluator` — runs after every XP award, checks the tenant's own
      badge catalog against the updated profile, awards at most once per
      badge per user.
- [ ] Tests: XP awarded exactly once per qualifying event (no double-award
      on a retried job), streak increments/resets correctly, a badge
      auto-awards exactly once when its criteria is first met.

### B14 — N18 pt.2: XP UI + leaderboard

- [ ] Student-facing widget — XP total, level, current streak, earned
      badges.
- [ ] Per-batch leaderboard, computed at read time (no materialized rank
      column, same posture as `TestSeriesRankingService`).
- [ ] `tenants.gamification_leaderboard_enabled` migration + toggle;
      leaderboard hidden tenant-wide when off, profile/badges still visible
      to the student themself.
- [ ] Tests: widget shows the right XP/level/streak/badges; leaderboard
      ranks correctly within a batch; opt-out hides the leaderboard but not
      the student's own profile.

### B15 — `TenantModelRegistry` sweep

- [ ] Reverse sweep (same method S16 B16/S17 B12 used): grep every
      `app/Domains/*/Models/*.php` for a real `use BelongsToTenant`
      declaration, diff against `TenantModelRegistry::MODELS`, confirm zero
      gaps either direction. All 10 new S19 models
      (`Survey`, `SurveyQuestion`, `SurveyResponse`, `SurveyAnswer`,
      `HelpDeskTicket`, `HelpDeskTicketReply`, `PlannerEvent`,
      `GamificationProfile`, `Badge`, `StudentBadge`) confirmed present with
      working `seedAttributes()`/`updateAttributes()` entries.

### B16 — Full test coverage, review pass

- [ ] Lifecycle E2E: a student raises a help-desk ticket → staff replies →
      requester sees the reply in real time → resolves, chained in one run;
      a full survey lifecycle — admin publishes → two students respond →
      aggregation/CSV correct; a general-forum thread created and replied
      to by two different-batch students, both seeing it live.
- [ ] Dispatch a background review agent over the whole slice's diff, same
      discipline every prior slice's final block established — expect
      findings around channel-authorization edge cases and cross-tenant
      leaderboard/forum visibility first, same class of bug S15/S16's own
      review passes caught.
- [ ] Update `HANDOVER.md` (S19 complete entry), `docs/PROJECT-STATUS.md`
      (new S19 section), `docs/ROADMAP.md` (S19 row → Done, shipped-count
      18→19).
