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
| B3 | N14 pt.2 — real-time new-post push for both batch and general forum threads (reuses B0/B1 infra) | ✅ done |
| B4 | N15 pt.1 — `surveys`/`survey_questions` schema + Admin-panel survey builder (mirrors `QuestionResource`'s shape) | ✅ done |
| B5 | N15 pt.2 — `survey_responses`/`survey_answers` schema + student-facing `SurveyRunner` Livewire + submission | ✅ done |
| B6 | N15 pt.3 — per-question aggregation view + CSV export (reuses the existing export-job pattern) | ✅ done |
| B7 | N16 pt.1 — `help_desk_tickets`/`help_desk_ticket_replies` schema + student-facing "raise a ticket" flow | ✅ done |
| B8 | N16 pt.2 — staff ticket queue Filament resource (assign/reply/resolve, internal notes) | ✅ done |
| B9 | N16 pt.3 — SLA sweep (flags breached tickets, notifies) + real-time push for new replies | ✅ done |
| B10 | N12 pt.1 — `Planner` domain scaffold + `planner_events` schema + `PlannerAggregator` service | ✅ done |
| B11 | N12 pt.2 — student-facing calendar Livewire view + custom event CRUD | ✅ done |
| B12 | N12 pt.3 — signed iCal feed export | ✅ done |
| B13 | N18 pt.1 — gamification schema + XP-award listeners on existing events + streak computation | ✅ done |
| B14 | N18 pt.2 — student-facing XP/level/badges/streak widget + per-batch leaderboard + tenant opt-out | ✅ done |
| B15 | `TenantModelRegistry` sweep — all 10 new `BelongsToTenant` models confirmed registered | ✅ done |
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

### B3 — N14 pt.2: real-time forum push — done

- New `App\Domains\Comms\Services\ForumThreadAccess` — the one place
  answering "may this viewer participate in this thread," same "lesson
  access rules live in one class" discipline `LessonAccess` already
  established (CLAUDE.md). `canParticipateInBatch()` holds the exact
  enrolment/instructor/institute_admin rule `BatchForum::canParticipate()`
  used to inline; `canParticipateInThread()` wraps it, adding the explicit
  tenant check and the "null batch_id = any tenant user" branch for general
  threads. `BatchForum::canParticipate()` now delegates to it — the
  channel-auth closure below needed the identical rule, and duplicating an
  enrolment check in two places is exactly the drift risk that discipline
  exists to prevent.
- `App\Domains\Comms\Events\ForumPostCreated` (`ShouldBroadcastNow`) fires
  from `ForumPostComposer::post()` — the single write chokepoint both forum
  kinds already share, so one dispatch point covers `createThread()` and
  `reply()` on both `BatchForum` and `GeneralForum` without touching either
  component's own write methods. `broadcastOn()` →
  `PrivateChannel('forum-thread.'.$post->forum_thread_id)`, `broadcastAs()`
  → `'post.created'`.
- `routes/channels.php` — `forum-thread.{threadId}` channel, one shared
  pattern for both forum kinds (a thread's own `batch_id` decides which
  rule applies, not two channel patterns). Fetches the thread
  `withoutGlobalScopes()` and leans on `ForumThreadAccess`'s own explicit
  tenant check as the real boundary — this auth endpoint carries no
  `Route::domain()` constraint (B0), so `CurrentTenant` isn't guaranteed
  bound the way a normal tenant-subdomain request would be.
- Both `batch-forum.blade.php` and `general-forum.blade.php` gained the
  same `@script` block: `openThreadId` changes as the viewer opens/closes
  threads (unlike B1's fixed-per-session `user.{id}` channel), so the
  subscription re-establishes on every change via `$wire.$watch`, leaving
  the previous thread's channel first (`Echo.leave`) before subscribing to
  the new one. Both host pages (`resources/views/comms/{batch,general}-forum.blade.php`)
  gained the `@vite` tag neither had — B1's own gotcha, would have silently
  no-op'd otherwise.
- New tests (7, `tests/Feature/Comms/ForumThreadBroadcastTest.php`): an
  enrolled student and the batch's own instructor are both authorized for
  a batch thread's channel; an unrelated tenant user is refused; any tenant
  user is authorized for a general thread's channel; a different tenant's
  user is refused; a new post on each forum kind broadcasts on the correct
  `forum-thread.{id}` channel with the right event name/payload. Existing
  `BatchForum`/`GeneralForum`/`ForumPostComposer`/`DirectMessageBroadcastTest`/
  `BroadcastingChannelAuthTest` suites (23 tests) re-run green as a
  regression guard — `BatchForum`'s refactored `canParticipate()` needed to
  produce byte-identical decisions to before.
- `composer test`: 1646/1647 pass (7 new tests; sole failure the
  pre-existing unrelated `CourseSlugUniquenessTest`).

### B4 — N15 pt.1: survey schema + builder — done

- `surveys`/`survey_questions` migrations + `Survey`/`SurveyQuestion`
  models (Comms), both registered in `TenantModelRegistry`. `Survey`
  carries `STATUSES = ['draft', 'published', 'closed']`;
  `SurveyQuestion::TYPES = ['text', 'single_choice', 'multi_choice',
  'rating']`, `options` jsonb per design doc §4 decision 6 (`{choices:
  [...]}` for the two choice types, `{max: N}` for rating, null for text).
- `SurveyResource` — Comms' resource directory is already discovered on
  the Admin panel (unlike Assessments), so no explicit
  `->resources()` entry was needed the way `TestSeriesResource` required.
  Questions are authored inline via a `Repeater::make('questions')
  ->relationship()` bound to `Survey::questions()`, not a separate child
  resource + relation manager — a survey question has none of a real quiz
  question's grading complexity, so one form covers authoring end to end.
  `orderColumn('position')` manages the `position` column on its own.
  Type-conditional fields (`options.choices` TagsInput, `options.max`
  rating input) use the same `Get`-closure `visible()`/`required()` idiom
  `QuestionResource` already established, dot-notation nesting the value
  under the model's single `options` jsonb column.
- New tests (4, `tests/Feature/Comms/SurveyResourceTest.php`): creates a
  survey with one question of each of the 3 non-trivial types (text,
  single_choice, rating) in one form submission, confirming each type's
  own `options` shape persists correctly; publishes a draft survey by
  editing its `status`; lists surveys for the current tenant only; not
  reachable from the Teach panel. "A draft survey isn't listed for
  respondents" is B5's own concern (no respondent-facing code exists
  yet) — not tested here, per the plan's own note.
- `composer test`: 1652/1653 pass (6 new tests — 4 above +
  `CrossTenantTest`'s auto-generated `Survey`/`SurveyQuestion` cases; sole
  failure the pre-existing unrelated `CourseSlugUniquenessTest`).

### B5 — N15 pt.2: response collection — done

- `survey_responses`/`survey_answers` migrations + `SurveyResponse`/
  `SurveyAnswer` models (Comms), both registered in `TenantModelRegistry`.
  `survey_responses` carries the actual `unique(survey_id, respondent_id)`
  DB constraint the design doc names.
- `SurveyRunner` Livewire component — `availableSurveys()` lists only
  `status = 'published'` surveys the viewer has no `SurveyResponse` row
  for yet. `submit()` uses `SurveyResponse::updateOrCreate` keyed on that
  same pair: **re-submitting updates the existing response rather than
  erroring or duplicating** — the same precedent `BatchFeedbackForm`
  already established, chosen over refusing a second submission since
  nothing in this slice's own scope needs "one shot only" semantics.
- Caught and fixed one real gap during implementation, before it shipped:
  `openSurvey()` only guards entry into a survey via its own published
  check, but `surveyId` is a plain public Livewire property — settable
  directly from the client, bypassing that guard entirely. `questions()`
  now re-verifies `status = 'published'` on every call, not just at
  `openSurvey()` time, so a crafted request naming a draft/closed survey's
  id can't read its question text — same class of gap `BatchForum`'s own
  B21 review pass had to catch after the fact; caught here in first draft
  instead.
- New tests (5, `tests/Feature/Comms/SurveyRunnerTest.php`): only a
  published, not-yet-answered survey is listed; a draft survey's questions
  are refused both through the normal open flow and a direct property-set
  bypass attempt; a full response with one answer per question type (text,
  single_choice, rating) persists correctly; a second submission updates
  the same response row rather than creating a second one; an answered
  survey no longer appears in the list. `CrossTenantTest`'s own generated
  `SurveyResponse`/`SurveyAnswer` cases surfaced one real framework gotcha:
  its update path (`Model::where()->update()`) bypasses Eloquent's `array`
  cast mutator entirely, so a bare PHP string sent to a jsonb column fails
  Postgres' implicit `text->jsonb` cast — fixed by pre-encoding the
  registry's own tampered value (`json_encode('tampered')`), not a
  production code change.
- `composer test`: 1659/1660 pass (7 new tests; sole failure the
  pre-existing unrelated `CourseSlugUniquenessTest`).

### B6 — N15 pt.3: aggregation + export — done

- `SurveyResponseAggregator` (Comms) — computed at read time, no
  materialized column, same posture `TestSeriesRankingService` (S16)
  already established. `single_choice`/`multi_choice` → per-option tally;
  `rating` → average + response count; `text` → response count only (no
  numeric summary makes sense for free text).
- `SurveyResource` table gained a `viewResults` modal action — same
  modal-action-with-`Placeholder` idiom `FlaggedQuizAttemptResource` (S16)
  already established, one `Placeholder` per question rendering its own
  aggregate.
- `ExportSurveyResponsesCsv` job — same `ShouldQueue` + `RebindsTenant` +
  `EscapesCsvFormulas` + `Storage::disk('local')` shape
  `ExportGradebookCsv`/`ExportCompletionRegister` already established, one
  row per respondent, one column per question in position order.
  `DownloadSurveyExportController` (`GET /surveys/{survey}/export`, `survey`
  resolved via `$request->route()` per CLAUDE.md's domain-route splicing
  gotcha) mirrors `DownloadCompletionExportController`'s institute_admin +
  tenant-match gate exactly. `exportCsv`/`downloadCsv` table actions on
  `SurveyResource` mirror `CompletionExport` page's own
  generate-then-download idiom, just per-record instead of per-tenant.
- New tests (4, `tests/Feature/Comms/SurveyAggregationExportTest.php`):
  choice tally and rating average/count match known seeded responses
  exactly; a text question's count excludes a null answer; a different
  tenant's survey never appears in another tenant's own aggregate; the CSV
  export contains every question as a column header and one row per
  respondent with the right values. Existing `SurveyResource`/
  `SurveyRunner` suites (9 tests) re-run green as a regression guard.
- `composer test`: 1663/1664 pass (4 new tests; sole failure the
  pre-existing unrelated `CourseSlugUniquenessTest`).

### B7 — N16 pt.1: ticket schema + raise-a-ticket flow — done

- `help_desk_tickets`/`help_desk_ticket_replies` migrations +
  `HelpDeskTicket`/`HelpDeskTicketReply` models (Comms), both registered in
  `TenantModelRegistry`. `is_internal_note` (default false) is a flag on
  the reply, not a second table — same binary-visibility shape this app
  already prefers for a yes/no distinction.
- New `config/comms.php` — `help_desk_sla_hours` per priority
  (low/normal/high), env-overridable, tenant-independent for this slice
  (design doc §4 decision 7's own "no per-institute override yet" starting
  point).
- `HelpDeskTicketForm` Livewire component — raises a ticket
  (`sla_due_at = created_at + config('comms.help_desk_sla_hours')[priority]`),
  lists the requester's own tickets, and shows a ticket's replies minus any
  `is_internal_note` one. Replying is B8's staff-queue concern, not built
  here. `replies()` re-verifies the viewer owns the ticket on every call
  (not just at `openTicket()` time) — same client-settable-property
  discipline `SurveyRunner::questions()` (B5) already established, since
  `openTicketId` is a plain public property a crafted request could set
  directly.
- New tests (3, `tests/Feature/Comms/HelpDeskTicketFormTest.php`): all
  three priorities produce the exact configured `sla_due_at` offset; a
  requester sees a public reply but never an internal note on their own
  ticket; a different user is refused both through the normal open flow
  and a direct property-set bypass attempt.
- `composer test`: 1668/1669 pass (5 new tests — 3 above +
  `CrossTenantTest`'s auto-generated cases; sole failure the pre-existing
  unrelated `CourseSlugUniquenessTest`).

### B8 — N16 pt.2: staff queue — done

- `HelpDeskTicketResource` (Admin panel, auto-discovered — Comms' resource
  directory already is, same as `SurveyResource`) — browse-only, same shape
  `MessageReportResource`/`TeacherAttendanceFlagResource` already
  established (every row comes from a requester's own `raiseTicket()`, B7,
  never a manual admin entry, so no create/edit pages). `assign` and
  `reply` reuse `TeacherAttendanceFlagResource`'s own
  `Action->schema()->action()` modal-form idiom; `assign` offers every
  `institute_admin`/`instructor` in the tenant. Status actions
  (`markInProgress`/`resolve`/`close`) each guard their own visibility off
  the ticket's current status, same `visible(fn ($record) => ...)` pattern
  `MessageReportResource` already uses — `resolve` also stamps
  `resolved_at`.
- New tests (4, `tests/Feature/Comms/HelpDeskTicketResourceTest.php`):
  assigning sets `assigned_to_user_id`; a public reply and an internal note
  both persist with the right `is_internal_note`/`author_id` (B7's own test
  already covers the requester-side hiding, not re-tested here); a ticket
  walks open→in_progress→resolved→closed with each action hidden once past
  it, `resolved_at` set on resolve; not reachable from the Teach panel.
- `composer test`: 1672/1673 pass (4 new tests; sole failure the
  pre-existing unrelated `CourseSlugUniquenessTest`). B9 (SLA sweep +
  real-time replies) is N16's remaining block.

### B9 — N16 pt.3: SLA sweep + push — done

- Migration adds `help_desk_tickets.sla_breach_notified_at` (nullable
  timestamp) — the dedup marker a schema this size needs isn't a whole
  second table the way `TeacherAttendanceFlag` got one; one boolean fact,
  one column.
- `HelpDeskSlaSweep` job — same unscoped-daily-sweep shape
  `TeacherAttendanceSweep` (S16) already established (`CurrentTenant`
  cleared up front). `breachedTickets()` = `sla_due_at < now()` AND
  `status` in `open`/`in_progress` AND `sla_breach_notified_at IS NULL`.
  Notifies the assigned staff member, or every institute_admin for the
  tenant if unassigned (design doc §4 decision 7) — new
  `App\Notifications\HelpDeskSlaBreached` (database-only, same posture
  `TeacherAttendanceFlagged` already established), one new
  `NotificationCentre::summarize()` case. Registered `->daily()` in
  `bootstrap/app.php` alongside the other unscoped sweeps.
- New `App\Domains\Comms\Events\HelpDeskTicketReplyCreated`
  (`ShouldBroadcastNow`) fires from `HelpDeskTicketResource`'s own `reply`
  action — **only when the reply isn't an internal note**. This is the
  real security constraint here: every subscriber to
  `help-desk-ticket.{id}` receives the identical payload, and the
  requester is one of the two roles authorized on that channel, so
  broadcasting an internal note would leak it to them regardless of
  `HelpDeskTicketForm::replies()`'s own server-side filter — the filter
  only protects the next page render, not a live push.
  `help-desk-ticket.{ticketId}` channel (`routes/channels.php`) authorizes
  the ticket's own `requester_id` or `assigned_to_user_id` only, fetched
  `withoutGlobalScopes()` same as `forum-thread.{id}` (B3).
  `help-desk-ticket-form.blade.php` gained the same `@script` re-subscribe-
  on-`openTicketId`-change wiring the forum views (B3) already established;
  its host page gained the `@vite` tag it never had.
- New tests (8, `HelpDeskSlaSweepTest.php` + `HelpDeskTicketReplyBroadcastTest.php`):
  sweep notifies the assignee when one exists, every institute_admin when
  none does, never flags a not-yet-due ticket, never re-flags an
  already-resolved or already-notified one; channel auth accepts the
  requester and the assigned staff member, refuses an unrelated tenant user
  and a different tenant entirely; a public reply broadcasts on the exact
  right channel with the right payload; an internal note never broadcasts
  at all.
- `composer test`: 1680/1681 pass (8 new tests; sole failure the
  pre-existing unrelated `CourseSlugUniquenessTest`). **N16 (help desk)
  fully done — B7/B8/B9 all shipped.**

### B10 — N12 pt.1: Planner domain + aggregator — done

- New `app/Domains/Planner/` domain — `Models/PlannerEvent.php` +
  `Services/PlannerAggregator.php` (Livewire/Views arrive in B11, per
  CLAUDE.md's standard layout populated incrementally — every other domain
  in this app grew its subfolders the same way, not all at once).
  `planner_events` migration + model, registered in `TenantModelRegistry`.
  Domain view namespace registration is automatic (`AppServiceProvider`'s
  own `glob()` loop), no manual step needed.
- **Real gap found before writing the service**: the design doc's own
  third source, "quiz open/close windows," doesn't exist anywhere in this
  app — verified directly, not assumed: `Quiz` has no `opens_at`/
  `closes_at`/any scheduling column at all, only `time_limit_minutes` (a
  duration once *started*) and `max_attempts`; `Quiz::isVisibleTo()` gates
  on the parent lesson's own access rules, not a date window. Same
  "verify before building, the design doc can be stale" discipline every
  prior slice's own design doc already applied when a roadmap assumption
  turned out wrong (S16's I7, H10). `PlannerAggregator::eventsFor()` merges
  two real sources, not three: the viewer's own `PlannerEvent` rows, and
  `BatchSession`s + `Assignment.due_at` for batches they hold an active
  `Enrolment` in (assignments resolved through
  `Assignment → Lesson → CourseSection → Course`, matched against the
  enrolled batches' own `course_id`). Computed at read time, no
  materialized aggregate — same posture `TestSeriesRankingService` (S16)
  already established; nothing gets copied into `planner_events`.
- New tests (4, `tests/Feature/Planner/PlannerAggregatorTest.php`): merges
  all three real sources for one user in `starts_at` order; excludes a
  batch the viewer isn't enrolled in and another user's own custom event;
  never leaks another tenant's data; respects the given date range.
- `composer test`: 1685/1686 pass (5 new tests — 4 above +
  `CrossTenantTest`'s auto-generated `PlannerEvent` case; sole failure the
  pre-existing unrelated `CourseSlugUniquenessTest`).

### B11 — N12 pt.2: calendar UI — done

- `App\Domains\Planner\Livewire\Calendar` — one month at a time
  (`previousMonth()`/`nextMonth()`), events rendered as a plain
  date-ordered list rather than a visual grid — same bare-HTML minimalism
  every other Livewire view in this app already uses (`BatchForum`,
  `SurveyRunner`, `HelpDeskTicketForm`), not a week-view toggle on top of
  it (not asked for beyond the plan's own descriptive parenthetical, and
  this app has no calendar-grid precedent to match).
- Create/edit/delete only ever touch the viewer's own `PlannerEvent` rows
  — `ownEvent()` re-verifies ownership on every call, same client-settable-
  id discipline `SurveyRunner`/`HelpDeskTicketForm` already established.
  Edit/delete controls in the view only render for `source === 'custom'`
  entries — `BatchSession`/`Assignment` ids share the same numeric space as
  `PlannerEvent` ids across different tables, so wiring a delete button to
  a non-custom entry's id could delete an unrelated row the viewer does
  own if the numbers happened to coincide; gating in the view is what
  keeps a click meaningful, not just a nicety.
- New `routes/planner.php` (`GET /calendar`, no `auth` middleware, same
  "guest sees a log-in prompt" shape every other Comms route already
  takes) — first route file for the new `Planner` domain, added to
  `routes/web.php`'s domain-group list alongside every other domain's.
  Registered as `planner.calendar`.
- New tests (5, `tests/Feature/Planner/CalendarTest.php`): renders the
  merged set for the current month; creates a custom event through the
  form; edits and deletes its own event; never lets a user touch another
  user's event (via `editEvent`/`deleteEvent` directly, not just through
  the UI); shows a different event set after navigating to the next month.
- `composer test`: 1690/1691 pass (5 new tests; sole failure the
  pre-existing unrelated `CourseSlugUniquenessTest`).

### B12 — N12 pt.3: iCal export — done

- `DownloadCalendarFeedController` on a `signed`-middleware-only route
  (`GET /calendar/{user}/feed.ics`, named `calendar.feed`), same "the
  signed URL itself is the access token" shape `DownloadReportCardController`
  established — but **permanently** signed (`URL::signedRoute()`, no TTL),
  unlike that one's 7-day expiry: a calendar app subscribes once and polls
  the same URL indefinitely, so an expiring link would silently break every
  subscription a week in. `{user}` read off the route (`$request->route('user')`)
  rather than method-injected, per CLAUDE.md's domain-route splicing gotcha.
- `Calendar::feedUrl()` builds the signed URL from the viewer's own tenant
  subdomain + id; the view renders it as a plain "Subscribe" link when a
  viewer is authenticated.
- `buildIcs()` renders `PlannerAggregator::eventsFor()`'s 3-months-back/
  6-months-forward window as RFC5545 `VCALENDAR`/`VEVENT` blocks (`\r\n`
  line endings, `SUMMARY` comma/semicolon/newline-escaped).
- Tests (4, `tests/Feature/Planner/CalendarFeedTest.php`): feed parses as a
  well-formed `.ics` containing the expected event; an unsigned request is
  refused; a tampered signature naming a different user is refused (the
  signature covers the full URL including the `{user}` param, so swapping
  it invalidates the signature rather than serving the wrong feed); one
  user's valid feed URL never leaks another user's events.
- `composer test`: 1694/1695 pass (4 new tests; sole failure the
  pre-existing unrelated `CourseSlugUniquenessTest`).

### B13 — N18 pt.1: gamification schema + XP

- [x] `gamification_profiles`/`badges`/`student_badges` migrations +
      models, registered in `TenantModelRegistry`.
- [x] Listeners on `AttendanceRecorded`, quiz-submission, and
      assignment-submission events — award fixed XP, update
      `last_activity_date`/streak (increment on consecutive day, reset on a
      missed day). `QuizAttemptSubmitted` (Assessments) and
      `AssignmentSubmitted` (Assignments) are two new events this block
      adds — neither domain fired anything on submit before this.
- [x] `BadgeEvaluator` — runs after every XP award, checks the tenant's own
      badge catalog against the updated profile, awards at most once per
      badge per user.
- [x] Tests: XP awarded exactly once per qualifying event (no double-award
      on a retried job), streak increments/resets correctly, a badge
      auto-awards exactly once when its criteria is first met.

### B14 — N18 pt.2: XP UI + leaderboard — done

- [x] `App\Domains\Analytics\Livewire\GamificationWidget` — own
      `GamificationProfile` (XP total, level, current/longest streak) and
      earned `StudentBadge` list, both always visible regardless of the
      leaderboard opt-out. No profile row yet (no XP awarded) reads as the
      same level-1/0-XP defaults `GamificationService` would create on
      first award, not an error — built as a throwaway in-memory
      `GamificationProfile` instance, never persisted. Routed `GET
      /gamification` (`routes/analytics.php`, no `auth` middleware, same
      "guest sees a log-in prompt inside the component" shape `Calendar`'s
      own route already established), registered as
      `analytics.gamification-widget`.
- [x] `App\Domains\Analytics\Services\LeaderboardService::rankingsFor()` —
      same "PHP-side sort, no raw SQL, no materialized rank column,
      computed at read time" posture `TestSeriesRankingService` (S16)
      already established (this app's security baseline forbids raw-SQL
      query methods in `app/Domains/`). Scoped to one batch's own `active`
      enrolments, tenant-matched explicitly off the batch
      (`Enrolment::where('tenant_id', $batch->tenant_id)`), not trusted to
      `CurrentTenant`'s own binding — same belt-and-suspenders
      `TestSeriesRankingService` already takes. A student never awarded any
      XP has no `GamificationProfile` row and so doesn't appear on the
      board at all (no zero-fill entry) — same "no zero-fill penalty"
      judgment call `TestSeriesRankingService` already made for a student
      who never attempted a series quiz. The widget's own batch picker
      only ever lists batches the viewer holds an active enrolment in, so
      a viewer can't name an arbitrary batch id to view another batch's
      board.
- [x] `tenants.gamification_leaderboard_enabled` migration (boolean,
      default true) + `Tenant::$fillable`/cast. **Real bug caught by the
      opt-out test itself, before it shipped**: `Tenant::factory()->create()`
      never sets this new column, so the in-memory model read the
      attribute as `null` (Eloquent doesn't re-fetch a server-side column
      default into memory after an `INSERT` — `$attributes` not present in
      the payload just stay unset in memory even though the DB row itself
      correctly defaults to `true`), and `null` fails the widget's
      `$tenant->gamification_leaderboard_enabled` truthiness check the
      same way `false` would — every freshly-created-and-not-refetched
      tenant silently rendered "leaderboard disabled" regardless of the
      real column value. Fixed at the model, not the test: `Tenant` gained
      a `protected $attributes = ['gamification_leaderboard_enabled' =>
      true]` class-level default, matching the DB column default, so a
      brand-new in-memory instance (factory or `::create()`) and a
      DB-hydrated one now agree. Leaderboard hidden tenant-wide when off;
      the widget's own profile/badges section reads `$tenant` only for the
      leaderboard section, so it never disappears with it.
- [x] Tests (8 total — 5 `GamificationWidgetTest`, 3 `LeaderboardServiceTest`):
      widget shows XP/level/streak from a real profile and the level-1/0-XP
      default with none yet; earned badges list by name; leaderboard ranks
      two batchmates by XP descending; opt-out hides the leaderboard
      section but not the profile; `LeaderboardService` ranks by XP
      descending, excludes a non-`active` enrolment, and never leaks a
      different tenant's own batch/student into the ranking.
- `composer test`: 1714/1716 pass (8 new tests; the 2 failures are both
  pre-existing/environmental — the standing `CourseSlugUniquenessTest`
  (carried since S1/S2) and `BackupConfigurationTest`'s archive-size
  assertion (accumulated stray export files from repeated full-suite
  reruns this session, same class logged in S16 B7/B11/B17 and B0's own
  note above) — neither caused by this block.

### B15 — `TenantModelRegistry` sweep — done

- [x] Reverse sweep (same method S16 B16/S17 B12 used): grepped every
      `app/Domains/*/Models/*.php` for a real `use BelongsToTenant`
      declaration — anchored to a line starting with `use` (either the
      `use App\Support\Tenancy\BelongsToTenant;` import or the in-class
      `use BelongsToTenant[, OtherTrait];` statement), not a bare substring
      match. A naive substring grep for `"use BelongsToTenant"` first
      flagged `ImpersonationAudit` as an apparent gap — a false positive:
      its own docblock literally contains the sentence "Deliberately does
      NOT use BelongsToTenant..." (explained there since `tenant_id` is set
      explicitly by `ImpersonationService` from the target user's own
      tenant, never resolved from `CurrentTenant`), which a substring match
      can't tell apart from real code. The anchored re-grep found exactly
      **83** models with a genuine trait declaration, diffed 1:1 against
      `TenantModelRegistry::MODELS`' own 83 entries — zero gaps either
      direction. All 10 new S19 models (`Survey`, `SurveyQuestion`,
      `SurveyResponse`, `SurveyAnswer`, `HelpDeskTicket`,
      `HelpDeskTicketReply`, `PlannerEvent`, `GamificationProfile`,
      `Badge`, `StudentBadge`) confirmed present in `MODELS`,
      `seedAttributes()`, and `updateAttributes()` alike (all three
      sections checked, not just the `MODELS` list) — already wired
      correctly by B7/B10/B13, nothing to add.
- No code changes this block — pure verification, per the plan's own
  scope. `composer test`: 1714/1716 pass (unchanged from B14 — no diff
  introduced here; the 2 failures are the same standing pre-existing/
  environmental ones — `CourseSlugUniquenessTest` and
  `BackupConfigurationTest`'s archive-size assertion).

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
