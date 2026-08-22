# HANDOVER

Read this first, every session. Live snapshot of where things stand right
now — not a dump of everything. Full detail always lives in the doc linked
below, never copied here; this file just says where things are and points
at the source.

## Right now (2026-08-22)

- **S15 — Batch & Institute Operations: DESIGN + PLAN DONE, build not
  started.** `docs/superpowers/specs/2026-08-22-s15-batch-institute-operations-design.md`,
  `...-plan.md`. `docs/ROADMAP.md`'s own S15 row lists F6-F10/§S.S2-S7 (11
  items), but §S.S7 (job board/placement module) is P3 and not named in
  the roadmap's own one-line S15 scope description — cut, same "P3 item
  absent from the headline gets cut" precedent S14 established for
  D10-D12. Real build scope is 10 items. **Verified before writing the
  design, one real load-bearing gap found**: no instructor↔course/batch
  assignment exists anywhere in the codebase — every `instructor`-role
  user has blanket tenant-wide access today (Spatie roles are global,
  not resource-scoped), and `docs/FEATURES.md`'s own §S.S1 row
  ("instructor invitations, 14-day link, assignment, deactivate/
  reactivate") was never actually built despite being P1-tier and S1
  "Foundation" marked done — F7 (conflict detection) and §S.S2
  (per-instructor permissions) both need this closed first, so B0-B2 of
  the plan build it as this slice's own foundation before touching
  either named item. New `Operations` domain for the admission CRM
  (§S.S4) — doesn't fit `Batches` (nothing to enrol into yet) or
  `Tenancy` (not a role/branding concern); everything else extends
  `Tenancy`/`Batches`/`Comms`. 11 new `BelongsToTenant` models. 22 blocks
  (B0-B21). Next step: run the per-block build command in the plan doc
  starting at B0.

- **S14 — Video & Live-Class Depth: COMPLETE, all 19 blocks
  (2026-08-22).** `docs/superpowers/specs/2026-08-19-s14-video-live-depth-design.md`,
  `...-plan.md` (full per-block build log + B18's own review-pass notes
  live there, not repeated here). `docs/ROADMAP.md`'s own S14 row lists
  D9-D13/E4/G5-G10 (12 items), but D10/D11/D12 (multi-DRM, forensic
  watermark, offline DRM) are **P3-tier**, cut from this slice's real
  build scope (9 items). Extends `Content` (concurrent-playback,
  subtitles, print/save protection) and `Batches` (Zoom OAuth, recording
  capture, Google Meet, live polls); no new domain. 6 new
  `BelongsToTenant` models (`LessonStreamSession`,
  `InstituteZoomConnection`, `LiveRecording`, `LivePoll`,
  `LivePollOption`, `LivePollResponse`), all registered in
  `TenantModelRegistry`, confirmed exact-complete in B17.
  - **B0-B1** built D9: `lesson_stream_sessions` +
    `ConcurrentStreamGuard` (one active stream per user across
    *different* lessons, heartbeat-timeout-based), wired into
    `StreamLessonController`/`WatchHeartbeatController`, daily stale-row
    sweep.
  - **B2-B3** built D13: `SubtitleGenerator` (SRT/VTT straight from the
    existing ingestion transcript chunks, no new AI call) auto-fires off
    the same `LessonArtifactReady` event `StartIngestion`'s own chain
    already produces; instructor-editable via a `LessonResource`
    textarea overwriting the same VTT file; `<track>` wired onto the
    player.
  - **B4-B5** built E4: print/save deterrents (`#toolbar=0` + right-click
    guard for PDF, `@media print` + guard for text), gated behind a new
    per-lesson `print_disabled` toggle (default off).
  - **B6-B7** built G7: `institute_zoom_connections` (per-tenant OAuth,
    Zoom's REST API called directly, no Socialite driver exists for
    Zoom) + `ZoomMeetingsProvider` (real meeting creation, `start_url`
    for staff vs `join_url` for students), a new `zoom_meetings_oauth`
    `live_provider` option alongside the pre-existing, still-unbound
    `zoom_video_sdk`.
  - **B8-B9** built G5/G6: `live_recordings` + a real Zoom webhook
    (`ZoomRecordingWebhookHandler`, real HMAC-SHA256 verification +
    the `endpoint.url_validation` handshake) + `DownloadLiveRecording`
    (downloads onto the lesson's existing `video_disk_path`, fires the
    *unchanged* `LessonVideoReady` into S3's ingestion pipeline — G6's
    "signed URL + enrolment check" needed zero new code, confirmed by a
    dedicated B10 test, since `StreamLessonController` already treats
    `video`/`live_session` identically).
  - **B11-B12** built G10 pt.2/G8: `ZoomMeetingsProvider` now refreshes
    an expired OAuth `access_token` via the stored `refresh_token`
    before every call (Zoom rotates the refresh token on every use, the
    new one is stored); `ZoomVideoSdkProvider`'s own 2-hour join-JWT
    confirmed already correct, no change. New `GoogleMeetProvider`
    (Calendar API `conferenceData`/`hangoutsMeet` event creation) — no
    per-institute connection table exists for Google (unlike Zoom), so
    it uses one platform-wide `GOOGLE_CALENDAR_REFRESH_TOKEN`; Meet has
    no host/participant URL split, so `joinUrl()` returns the same link
    to everyone (Google's own meeting lobby is the real access control,
    documented as a limitation).
  - **B13-B16** built G9: `live_polls`/`live_poll_options`/
    `live_poll_responses` (unique per student per poll, DB-level, same
    `lesson_question_votes` defense-in-depth precedent); `LivePollService`
    (create/open/close, reuses `EnrolmentApprovalPolicy::canDecide()`
    rather than a new role constant, `open()` gated to
    `BatchSession::liveState() === STATE_LIVE`); `App\Livewire\
    LivePollResponse` (student vote, gated by `LessonAccess::
    canJoinLive()`'s own verdict, app-check-plus-unique-constraint
    one-vote enforcement); `App\Livewire\LivePollBoard` (instructor
    launch controls + the shared instructor/student results view,
    `wire:poll.2s` while open — B14 shipped the service with no UI to
    reach it, B16 is what actually closes that gap). Both components
    sit on the syllabus tree's own live_session row, next to the Join
    link, since students leave the app once they click it.
  - **B18's own dedicated review pass** — same discipline S9 B14/S10
    B15/S11 B16/S12 B14/S13 B17 each already established, re-reading
    every B0-B17 file for real bugs rather than trusting a green suite
    alone. **Found and fixed 1 real bug**: `GenerateLessonSubtitles`
    (B2) is a `ShouldQueue` listener that calls `$this->fail($e)` on a
    throw, but only used the `Queueable` trait — `fail()` lives on
    `InteractsWithQueue`, which `CallQueuedListener` only wires up when
    the class actually uses that trait, so a real generation failure
    would have fatally errored with "Call to undefined method" instead
    of marking `subtitle_status: failed`. `DownloadLiveRecording`'s own
    docblock (B9) had already flagged this exact bug class in itself and
    fixed it there, but explicitly left this sibling instance
    unfixed/unverified ("flagged here rather than fixed, out of scope
    for this block") — B18 closed it, with a new regression test that
    forces the throw and confirms no fatal error (previously untested,
    since `QUEUE_CONNECTION=sync` in `phpunit.xml` means the happy-path
    test alone was never enough to catch it). Two new lifecycle E2E
    tests (`S14VideoLiveDepthE2ETest`): Zoom OAuth connect → schedule on
    the new provider → recording-completed webhook → downloaded →
    ingested → playable, chained in one run; and a live poll
    instructor-launches → 2 students respond → a 3rd non-enrolled
    student is refused → instructor closes → aggregation numbers match
    exactly.
  - Tests green: **1283/1284** (same 1 pre-existing, unrelated
    `CourseSlugUniquenessTest` failure carried since S1/S2, still out of
    scope) — 78 new tests across all 19 blocks.
  - `docs/PROJECT-STATUS.md` updated to COMPLETE, matching S1-S13's own
    post-completion format.

- **S13 — Discovery & Content Engagement: COMPLETE, all 18 blocks
  (2026-08-19).** `docs/superpowers/specs/2026-08-19-s13-discovery-engagement-design.md`,
  `...-plan.md` (full per-block build log + B17's own review-pass notes
  live there, not repeated here). `docs/FEATURES.md` C7-C10 (categories/
  tags/catalog filtering, prerequisites + sequential unlocking, `Program`
  bundles, catalog/search/wishlist/recommendations) + N7-N10 (lesson Q&A,
  video notes/bookmarks, 1:1 direct messaging, course ratings/reviews).
  Extends the existing `Content` domain (7 of 8 items) plus `Comms`
  (direct messaging + moderation); no new domain. 12 new `BelongsToTenant`
  models — the widest single-slice model count so far (S12 had 5), all
  registered in `TenantModelRegistry`.
  - **B0-B4** built C7/C10: `Category`/`Tag` + pivots, Admin-panel CRUD;
    public `CourseCatalog` (filters + Eloquent `LIKE` search, no raw
    SQL); `Wishlist` toggle; `RecommendationService` (same-category/tag
    heuristic, explicitly not a real recommender yet — no production
    traffic to train one on).
  - **B5-B6** built C8: `lesson_prerequisites` pivot + `PrerequisiteGraph`
    (bounded-DFS cycle check, write-time); `LessonCompletion` (per-type
    binary heuristic) wired into `LessonAccess::canStream()`.
  - **B7-B8** built C9: `Program`/`program_batch` (bundles `Batch` rows,
    this app's real sellable unit); `fee_invoices.program_id` nullable FK
    (`batch_id`/`enrolment_id` relaxed to nullable alongside it);
    `ProgramEnrolmentService` enrols one per bundled batch on payment
    capture, via a `ProgramInvoicePaid` event (not a direct call — B17
    fixed an initial version that called it directly).
  - **B9-B11** built N7/N8: `LessonQuestion`/`Reply`/`Vote` (post/reply/
    upvote gated by `canStream()`, resolved-marking staff-only);
    `LessonNote` (nullable `body` doubles as a bookmark), player timeline
    markers reusing `DoubtChat`'s own seek-to dispatch.
  - **B12-B13** built N9: new Comms `DirectMessage`/`BlockedUser`/
    `MessageReport` — symmetric block enforcement at send time; Admin
    moderation queue (`actioned` hides a message display-time only,
    `dismissed` clears the flag).
  - **B14-B15** built N10: `CourseReview` (unique per learner/course, DB
    `CHECK` on rating); `CourseReviewPolicy` gates on genuine
    (active/expired) enrolment, any batch of the course; instructor
    response; Admin hide/show — reversible both ways.
  - **B16** — `TenantModelRegistry` sweep, confirmed exact-complete.
  - **B17's own dedicated review pass** — 8 parallel finder agents, same
    discipline S9 B14/S10 B15/S11 B16/S12 B14 each established. **Found
    and fixed 7 real bugs**: `PaymentReconciler` called
    `ProgramEnrolmentService` directly across the Fees→Content domain
    boundary (a hard CLAUDE.md violation, independently flagged by 3 of
    the 8 agents) — fixed with a `ProgramInvoicePaid` event, mirroring
    `PaymentCaptured`'s shape; `TeachPanelProvider`'s directory-wide
    resource discovery silently exposed Category/Tag/Program/
    CourseReview management to every instructor (Admin's own explicit
    `->resources()` array only *added* those to Admin, never removed
    them from Teach's scan) — fixed by switching Teach to the same
    explicit-array pattern; `LessonResource`'s prerequisite field and
    `CourseResource`'s category/tag fields both had same-tenant/
    same-course scoping that was UI-only — Filament's relationship
    `Select` syncs whatever raw ids are submitted, `modifyRelationshipQueryUsing`
    only narrows the dropdown's own options and the detach diff, never
    what gets attached (confirmed against Filament's own vendor source)
    — fixed with real `->rule()` server-side checks on both;
    `CourseReviewPolicy::canReview()` counted any enrolment status,
    letting a rejected/never-paid applicant post a public review — fixed
    to require genuine participation; `CourseCatalog::courses()`
    reimplemented course visibility instead of reusing `LessonAccess`,
    silently dropping the staff-preview bypass — fixed by widening
    `LessonAccess::isStaff()` to `public` and reusing it;
    `ProgramEnrolmentService::enrolFromInvoice()` had a TOCTOU race on
    concurrent webhook deliveries, unlike `BatchEnrolmentService::createEnrolment()`'s
    own established try/catch pattern — fixed the same way. All 7 fixed
    with regression tests, plus one new lifecycle E2E test chaining
    catalog search → wishlist → enrol → prerequisite-denied →
    completion-unlocked in one run.
  - Tests green: **1206/1207** (same 1 pre-existing, unrelated
    `CourseSlugUniquenessTest` failure carried since S1/S2, still out of
    scope) — 113 new tests across all 18 blocks.
  - `docs/PROJECT-STATUS.md` updated to COMPLETE, matching S1-S12's own
    post-completion format.

- **S12 — Security & Resilience Hardening: COMPLETE, all 15 blocks
  (2026-08-18).** `docs/superpowers/specs/2026-08-17-s12-security-resilience-design.md`,
  `...-plan.md` (full per-block build log + B14's own review-pass notes
  live there, not repeated here). `docs/FEATURES.md` Section B (B9-B13,
  B15) + Section V-a (V1-V5). Picked as the next P2-backlog slice per
  `docs/ROADMAP.md`'s own S12 entry. New `app/Domains/Security/` domain;
  5 new `BelongsToTenant` models (`DeviceSession`, `LoginHistory`,
  `AccountSharingFlag`, `GdprExportRequest`, `GdprDeletionRequest`), all
  registered in `TenantModelRegistry`, plus 2 deliberately-not-tenant-scoped
  ones (`AuditLogEntry`, `CookieConsent` — same `ImpersonationAudit`-style
  exception S9 B3 established, both need to record platform-level or
  pre-tenant-context events).
  - **B0-B4** built device/session/login tracking: `DeviceFingerprintService`
    (UA + first-party cookie hash, no paid vendor) + `DeviceRegistrar`
    (registered-device limit, default 2, config-driven) firing off
    `Illuminate\Auth\Events\Login` — the one chokepoint every login surface
    (password, TOTP, Google OAuth, all 3 Filament panels, both portals)
    already funnels through, no controller touched; Admin/Teach-panel
    "My devices" page with a remote-logout action, enforced lazily on the
    revoked device's *next* request (`EnsureDeviceNotRevoked`, same
    lazy-check tradeoff `Tenant::isSuspended()` already accepts);
    `LoginHistory` + `ImpossibleTravelDetector` (plain haversine velocity
    check against a config'd max plausible km/h, `FakeGeoIpResolver` stands
    in for a real MaxMind driver — deferred, no `.mmdb` file to test
    against in this environment); `AccountSharingFlag` daily sweep
    (distinct-device-count threshold, forces re-auth on flag rather than
    locking the account, so a false positive doesn't lock out a real user).
  - **B5-B9** built the general audit log + GDPR/DPDP surface: `AuditLogger`
    (`audit_log_entries`, written synchronously — not queued, since the
    entire point is surviving a queue worker failure) wired into role
    assignment, impersonation, plan change, and tenant suspension;
    `PruneAuditLogEntries` (365-day retention, monthly sweep, hard delete —
    an aged-out entry has nothing left to restore) + a Super-panel
    read-only viewer; cookie-consent banner recorded per-session before
    login then reconciled to the user on `Auth::login()` (mirrors
    `ParentInviteCode`'s own "real state before a User row exists" shape);
    GDPR export (async job, zip of every domain's rows for one user,
    signed download link, same shape `ExportRevenueRegister` (S9 B11)
    already established) and GDPR deletion (anonymize PII on rows with a
    legal/financial retention requirement — `FeeInvoice`/`SubscriptionInvoice`
    — hard-delete everything else, 30-day grace window before the daily
    sweep executes it).
  - **B10-B13** built the resilience half (V1-V4): `App\Support\Resilience\CircuitBreaker`
    (Redis-backed `INCR`/`SETEX`, half-open implicit via TTL expiry,
    failures not reset on trip so a failed probe re-trips immediately)
    wrapped as a decorator around all 6 external-provider drivers (Bunny,
    Claude, both Razorpay integrations, WhatsApp BSP, live-class) —
    container bindings swapped, call sites untouched — plus a Sentry
    `Log::critical()` alert on trip and a shared Super/Admin dashboard
    widget; `AggregateCache` (explicit named remember/forget per target,
    not generic keying) standing up a first-ever cache layer for
    `GradebookService::scoreFor()`/`StudentMetrics::summarize()`/
    `InstituteOverviewWidget`, busted on every write path that can change
    the underlying numbers (assignment grading, quiz submission/review,
    attendance recorded via a dedicated listener); `SoftDeletes` +
    `TrashedFilter`/`RestoreAction` closing the 4 real V3 gaps
    (`FeePayment`/`FeeInvoice`/`TenantSubscription`/`SubscriptionInvoice`)
    — 3 of those 4 needed a brand-new minimal browse-only Filament resource
    built from scratch, since only `FeeInvoice` already had one; Form
    Request extraction on the last 2 inline-`validate()` calls
    (`TwoFactorChallengeRequest`, `WatchHeartbeatRequest`).
  - **B14's own dedicated review pass** — same discipline S9 B14/S10 B15/S11
    B16 each already established, re-reading every B0-B13 file rather than
    trusting a green suite alone. **Found and fixed 2 real bugs:** (1)
    `DeviceRegistrar` never revives an old revoked `device_sessions` row on
    relogin, it inserts a fresh one — but `EnsureDeviceNotRevoked` checked
    *any* row for that fingerprint ever revoked, not the latest one, so a
    legitimately-relogged-in device got force-logged-out on every request
    forever, permanently. (2) `RoleAssignmentPolicy::assign()` wrote its
    `'role.assigned'` audit row *before* the seat-quota check that can
    still throw `SeatLimitExceededException` — a seat-limit-blocked
    assignment left a false "success" row in the general audit sink for a
    grant that never took effect. Both fixed with regression tests, plus
    one new full lifecycle E2E test (`SecurityLifecycleE2ETest`, chains
    login → device-limit-hit → remote-logout → login-history-flagged in
    one run, mirroring S11 B16's own `SubscriptionLifecycleE2ETest`
    posture) proving the pieces still fit together end to end.
  - Tests green: **1094/1093** (same 1 pre-existing, unrelated
    `CourseSlugUniquenessTest` failure carried since S1/S2, still out of
    scope) — 168 new tests across all 15 blocks.
  - `docs/PROJECT-STATUS.md` updated to COMPLETE, matching S1-S11's own
    post-completion format.

- **S11 — Plan Tiers + Institute Billing: COMPLETE, all 17 blocks
  (2026-08-17).** `docs/superpowers/specs/2026-08-16-s11-plan-billing-design.md`,
  `...-plan.md` (full per-block build log + B16's own dedicated review-pass
  notes live there, not repeated here). `docs/FEATURES.md` Section Q
  (Q1-Q5) — plan tiers with seat/storage/bandwidth limits, enforcement at
  point of action, Razorpay recurring subscriptions for institutes, 7-day
  grace period → suspension, upgrade/downgrade with proration. Picked over
  2 other leftover P2 candidates (account-security round 2, engagement
  analytics + parent comms follow-up) — directly closes the fast-follow
  `BandwidthQuota`/`AiSpendCap` (S9 B12) themselves flagged as deferred
  ("richer tiers/limits product... once real customers need different
  limits"). New `app/Domains/Billing/` domain; new un-tenanted `Plan`
  catalog table (only `Plan` of the four new models is *not*
  `BelongsToTenant` — `TenantSubscription`/`SubscriptionInvoice`/
  `TenantStorageUsage` all are, all three registered in
  `TenantModelRegistry`, confirmed exact-complete in B16); `tenants.plan_id`
  added additively (nullable, existing `plan_tier` enum + hardcoded caps
  kept as the fallback when null — zero blast radius on existing S9
  tests); second, separate Razorpay integration (`SubscriptionProvider`,
  Subscriptions API) beside the existing `PaymentProvider` (Orders API,
  Fees domain) since they're different vendor surfaces.
  - **B0-B4** built the domain end to end: schema, Super-panel `PlanResource`
    CRUD, `TenantPlanLimits` (plan-aware, legacy `plan_tier` fallback
    preserved), `SeatQuota` (enforced at `RoleAssignmentPolicy::assign()`),
    `StorageQuota` (wired into every file-store/delete call site across
    Assignments/Content/Tenancy).
  - **B5-B10** built the real subscription lifecycle: `SubscriptionProvider`
    + `RazorpaySubscriptionProvider`/`FakeRazorpaySubscriptionDriver`;
    `TenantResource`'s "Plan" section + `PlanChangeService::subscribe()`;
    the webhook (`RazorpaySubscriptionWebhookController` +
    `SubscriptionReconciler`); the daily grace-period sweep
    (`SuspendOverdueTenantSubscriptions`, `grace_ends_at` logic); suspension
    enforcement confirmed on the existing `ResolveTenant` gate (decision 8
    — reuses `Tenant::STATUS_SUSPENDED`, no parallel flag) plus a
    `GracePeriodBanner`; upgrade/downgrade with in-house proration
    (`PlanChangeService::changePlan()`, decision 9 — computed in this app's
    own ledger, not delegated to Razorpay's opaque INR proration).
  - **B11-B15** built the remaining surfaces + hardening: Admin-panel
    `BillingOverview` (plan/usage bars/status/invoices); Super-panel
    `InstituteSubscriptions` (cross-tenant list); `PlanChangeService::cancel()`
    (reverts to `plan_id = null`, decision 11's own legacy-fallback branch,
    not a new state); grace/suspension-imminent WhatsApp+email+database
    notifications (`GracePeriodReminder`, mirrors Fees' `FeeReminderDue`
    shape); **B15's dedicated concurrency pass** found and fixed 4 real
    races with no unique DB constraint to catch-and-retry against —
    `PlanChangeService::subscribe/changePlan/cancel` and
    `RoleAssignmentPolicy`'s seat-consuming branch both now lock the
    `Tenant` row (`lockForUpdate()`, locked-and-discarded) inside their own
    `DB::transaction()`, serializing concurrent calls per tenant; flagged,
    not fixed, as a documented limitation: `SubscriptionReconciler` (locks
    the `TenantSubscription` row) and `PlanChangeService` (locks the
    `Tenant` row) lock different targets, so a webhook landing and a manual
    plan-change at the same instant on the same tenant have no mutual
    exclusion between them — assessed low-severity (proration-math
    precision only, self-correcting next cycle, no data corruption, disjoint
    columns written).
  - **B16's own dedicated review pass** — the user's own explicit ask,
    beyond the plan note's stated scope: re-read every B1-B15 file for real
    bugs, not just re-confirm the suite stayed green. **Found and fixed 3
    real bugs, none of them theoretical:** (1) `StorageQuota::adjust()` had
    a lost-update race on its read-then-write increment (two concurrent
    file uploads for the same tenant could silently undercount usage) plus
    a unique-constraint race on its create-new-row branch — fixed with
    atomic `increment()`/`decrement()` and a retry-once-on-`QueryException`
    for the create race (same shape `AssignmentSubmissionService::submitWithRetry()`
    established in S10 B15), floor-at-zero moved from write time to
    `usedNow()`'s read time. (2) `PlanChangeService::liveSubscriptionFor()`
    excluded `SUSPENDED` from its status filter, so `cancel()`/`changePlan()`
    both refused exactly the tenant a super_admin is most likely to want to
    act on — worse, `EditTenant`'s own header actions stay *visible* for a
    suspended tenant (visibility only checks `plan_id !== null`), so this
    was a real visible-but-broken button, not a hypothetical gap. (3)
    `SubscriptionReconciler::charged()` flipped `TenantSubscription::status`
    back to `active` on a recovered payment but never touched
    `Tenant::status` — the column `ResolveTenant` actually gates login on —
    so a tenant the daily sweep had already suspended stayed locked out of
    login forever even after billing genuinely recovered, with zero
    automated path back in. All three ship with regression tests (7 new,
    plus one full lifecycle E2E test chaining subscribe → charge → grace →
    suspend → recover → cancel in one run, mirroring S7's own
    `DemoScriptE2ETest` posture — the one thing no per-block test could
    cover). Full detail in the plan doc's own B16 notes.
  - Tests green: **925/926** (same 1 pre-existing, unrelated
    `CourseSlugUniquenessTest` failure carried since S1/S2, still out of
    scope).
  - `docs/PROJECT-STATUS.md` updated to COMPLETE, matching S1-S10's own
    post-completion format.

- **S10 — Assignments + Gradebook + Certificates: COMPLETE, all 16 blocks
  (2026-08-16).** `docs/superpowers/specs/2026-08-16-s10-assignments-gradebook-design.md`,
  `...-plan.md` (full per-block build log + review-pass notes live there,
  not repeated here). Picked over 3 other P2 candidates (account-security
  round 2, plan-tier billing, engagement analytics) as the largest
  structural gap in `docs/FEATURES.md`'s ~75-item P2 backlog — J1-J7
  (assignments + consolidated gradebook) and K1-K4 (certificates). New
  `app/Domains/Assignments/` domain (`Assignment`/`AssignmentSubmission`/
  `Certificate`); new `courses.gradebook_weights`/`passing_threshold` and
  `tenants.certificate_signature_path`/`certificate_background_path`
  columns; first use of `barryvdh/laravel-dompdf`, `maatwebsite/excel`,
  and `endroid/qr-code` in this app. 804/805 tests green (same 1
  pre-existing, unrelated `CourseSlugUniquenessTest` failure carried since
  S1/S2).
  - **Grading + rubric**: grading columns live on `AssignmentSubmission`
    itself (same shape `QuizAnswer` used), not a separate `Grade` model.
    Rubric-based grading derives `marks_awarded` from the sum of criterion
    scores — never accepted as a separately-typed number a grader could
    make disagree with their own breakdown.
  - **Resubmission**: separate versioned rows (not a JSON blob), capped at
    3, one unified `AssignmentSubmissionService::submit()` for both first
    submission and every resubmission (a plan-note deviation, recorded) —
    version_number computed from the existing row count.
  - **Gradebook**: `GradebookService::scoreFor()` — best attempt per quiz,
    *latest graded* (not best-scoring) version per assignment, a category
    with zero gradeable items drops out of the weighted average entirely
    (renormalized) rather than scoring as zero. Its own
    `GRADEBOOK_AT_RISK_THRESHOLD` (60%), deliberately separate from S9
    B13's attendance-based `StudentMetrics::AT_RISK_THRESHOLD` — the two
    flags are independent (a student can be passing-but-at-risk).
  - **Certificates**: "course completion" newly defined as gradebook-based
    (every quiz+assignment attempted, weighted score ≥
    `Course.passing_threshold`) — no generic lesson-completion tracking
    exists anywhere else in this app to build on instead. Issued by a
    daily unscoped sweep (`CheckCourseCompletionAndIssueCertificates`,
    same `CurrentTenant::clear()` shape as S9 B10's own jobs), not a
    real-time hook off quiz/assignment grading — those two writes live in
    different domains, and a live check would need a new cross-domain
    event this slice doesn't otherwise touch. Public verification page is
    host-agnostic (`GET /certificates/verify/{id}`, no auth), a QR code,
    and a LinkedIn "Add to profile" link using LinkedIn's own documented
    static-URL parameters (checked against their help docs, not
    remembered — they've changed before).
  - **Real bugs found and fixed** (full detail per-block in the plan
    doc's own notes): a Filament Repeater's per-item `default()` silently
    never fires outside the UI's own "add" action, so rubric-criterion ids
    were backfilled in `Assignment::saving()` instead (B1); B9's
    `standingFor()` collapsed "no graded data yet" into "Failing" instead
    of a distinct "No data yet" state (B9); `Certificate::withoutGlobalScope('tenant')`
    on the top-level verify query wasn't enough — the eager-loaded
    `course` relation is `BelongsToTenant` too and needed its own
    unscoping, or a certificate opened from a different institute's
    subdomain 500'd instead of verifying (B13); a `FileUpload` field
    prefilled with an already-stored path silently reads back empty when
    the field's own configured disk differs from the file's real disk
    (B12, matches a real, pre-existing gap in `LessonResource`'s own
    `video_upload` field, never itself hit because it never tried to
    prefill either); **B15's own dedicated review pass** then found two
    more: `AssignmentSubmissionService::submit()` computed
    `version_number` from a plain `count()` with no lock, so two
    tabs/a double-click racing the same count could both hit the DB's
    unique index as an uncaught `QueryException` instead of either
    succeeding or failing cleanly (fixed with a one-retry-on-collision
    shape, matching `BatchEnrolmentService::createEnrolment()`'s own S4
    precedent for an identical race, plus its own `DB::transaction()`
    savepoint so a failure can't poison a caller's already-open
    transaction); and `Batch`/`Course` are both `SoftDeletes` with **no**
    delete guard against active enrolments (unlike Assignment/Quiz, which
    both hide delete once real submissions/attempts exist) — an active
    `Enrolment` could point at a soft-deleted batch or course, and every
    Gradebook consumer (both Filament pages, all three export formats,
    the certificate sweep) dereferenced `->batch->course` unconditionally;
    filtered out at the query level in all four places (`BuildsGradebookRows`
    covers three of them, the fourth is the sweep job itself) rather than
    null-checked per row.
  - **What's not real yet, on purpose**: `endroid/qr-code`/`maatwebsite/excel`/
    `barryvdh/laravel-dompdf` are all first-use dependencies with no prior
    track record in this app to lean on — watch for edge cases in
    production PDF/Excel rendering (fonts, large datasets) that this
    slice's own test suite wouldn't surface. Certificate issuance latency
    is up to 24h by design (daily sweep). Gradebook weighting is
    course-wide only, no per-section override. `Gradebook` UI is not
    truly DB-sortable by score (matches `StudentProgress`'s own S8 B5
    precedent for an identical four-table computed aggregate with no
    materialized column to sort on).
- **S9 — Hardening & Backlog: COMPLETE, all 15 blocks (2026-08-13).**
  `docs/superpowers/specs/2026-08-12-s9-hardening-backlog-design.md`,
  `...-plan.md`. **Not part of the original P1 "Institute Zero" roadmap** —
  S8 already closed that out in full; S9 is a new, owner-requested slice
  picking up the fast-follow backlog every S1-S8 design doc deferred, plus
  security gaps three separate review passes flagged but never fixed.
  15 blocks (B0-B14), scoped from a dedicated research pass that
  cross-checked every prior slice's "out of scope" table against the
  live code, not against the deferring doc's own (sometimes stale)
  wording.
  - **B0 — Tenant-isolation security parity: done.** The two real,
    still-open multi-tenant security gaps that drove B0 going first are
    now closed: `StudentPortal::mount()` gained the same tenant-mismatch
    force-logout `ParentPortal` got in S8 B1; `LessonAccess::canStream()`
    and `Quiz::isVisibleTo()` now cross-check the viewer's own `tenant_id`
    against the lesson's/quiz's, closing the same "Spatie roles carry no
    tenant_id, SESSION_DOMAIN is a shared parent domain" bug class
    `EnsureRole`/`DownloadInvoiceController` were each fixed for
    previously — a tenant A user with a `student`/`instructor` role could
    stream tenant B's lessons or preview tenant B's draft quizzes just by
    staying logged in and opening tenant B's URL. Also fixed in the same
    block: `canJoinLive()` now reuses `isCourseVisibleTo()` so a stale
    bookmark into a since-unpublished course's live session stops
    resolving for students (staff still bypass, unchanged); and
    `myLiveBatchSession()` gained a deterministic `orderBy('starts_at')`
    tiebreak (soonest session wins) for viewers enrolled in more than one
    batch offering the same lesson. 8 new regression tests, one per
    scenario, written before each fix. Tests green: **536/537** (same 1
    pre-existing `CourseSlugUniquenessTest` failure, still out of scope).
  - **B1 — TOTP two-factor auth: done.** Major deviation from the design
    doc, found *before* writing code: `filament/filament` v5.7.6 already
    depends on `pragmarx/google2fa-qrcode`, because **Filament v5 ships a
    complete, built-in TOTP multi-factor auth system**
    (`Filament\Auth\MultiFactor\App\AppAuthentication`, a `HasAppAuthentication`
    contract pair). Used that instead of adding the design doc's recommended
    `pragmarx/google2fa-laravel` (added it, confirmed the collision, removed
    it again — never touched `composer.lock`) — one TOTP implementation, not
    two competing ones. The three Filament panels (Admin/Teach/Super) now
    get enrollment, QR codes, recovery codes, and login challenge entirely
    from Filament's own `->profile()` + `->multiFactorAuthentication(...)`,
    wired through a new shared `App\Filament\Auth\TenantAwareLogin` (all
    three panel `Login` classes extend it) that keeps Filament's own MFA
    bookkeeping but swaps the credential check for the tenant-scoped
    `TenantAuthenticator::validate()`. The two non-Filament portals
    (StudentPortal, ParentPortal) get a hand-rolled but same-service
    challenge step (`TenantAuthenticator::challengeTwoFactor()`, reusing
    Filament's own `AppAuthentication` for verification) with a one-page
    two-schema toggle, no redirect. A stub enrollment surface for those two
    (`App\Livewire\TwoFactorSettings` at `/settings/two-factor`) fills the
    gap until B9's real shared profile page absorbs it. The JSON `/login`
    endpoint now returns `{"two_factor_required": true}` instead of logging
    in, with a new `POST /login/two-factor-challenge` to finish it. 11 new
    regression tests. Tests green: **547/548** (same pre-existing failure).
  - **B2 — Google OAuth login: done.** Scoped to the two portals
    (StudentPortal, ParentPortal) only, not the three Filament panels — a
    deliberate cut, documented in the plan doc, not an oversight (staff
    already have password + optional TOTP from B1). The real mechanics
    question this block had to resolve: Google's own `redirect_uri` must be
    one fixed URL, which no per-tenant-subdomain route can be, so
    `redirect()` (tenant-subdomain-scoped, stores the tenant id + which
    portal in session) and `callback()` (host-agnostic, reads both back out
    of session) are split across `routes/auth.php` and `routes/web.php`
    respectively — the shared-SESSION_DOMAIN behavior every other S9 B0 fix
    treats as a leak to guard against is, here, the one thing that makes
    the flow work at all. New `GoogleOAuthAuthenticator` matches an
    existing user by `google_id` first, falls back to an `email` match
    *within the same tenant* and links `google_id` onto that row (a
    password account and a Google identity converge, verified not to cross
    tenant boundaries), or creates a new role-less `User` the same shape
    `RegisteredUserController` already uses. New `google_id` column on
    `users`, same tenant-scoped partial-unique-index shape `email` already
    has. 8 new regression tests, Socialite mocked (no real network call).
    Tests green: **555/556** (same pre-existing failure).
  - **B3 — Super Admin impersonation + audit log: done.** Found the block
    note's own premise wrong before building on it (same discipline B0/B2
    already applied once each): `RoleAssignmentAudit` has **no**
    nullable-actor precedent (its `tenant_id`/`actor_id` are both `NOT
    NULL`) — role assignment only ever happens inside a tenant-scoped
    panel, but impersonation's actor is always a tenant-less `super_admin`.
    `ImpersonationAudit` therefore does **not** use `BelongsToTenant` (that
    trait's `creating()` hook would throw on every single impersonation
    start, since it unconditionally reads `CurrentTenant::id()`) and is
    **not** registered in `TenantModelRegistry` (overriding the block
    note's own "register it, not deferred" instruction — registering a
    non-`BelongsToTenant` model there would fail `CrossTenantTest`'s
    generated per-model test, which assumes that trait's behavior).
    `tenant_id` is set explicitly from the *target* user's tenant instead.
    Session-based (`impersonating_user_id`), resolved by a new
    `ResolveImpersonation` middleware that had to be registered in **two**
    places, not the one place `ResolveTenant::class.':optional'` needed —
    Filament panel page loads don't reference the bare `'web'` group string
    at all (they list concrete middleware classes), so a global-group-only
    append would apply on Livewire AJAX round-trips but never on an actual
    panel page load, missing the one moment `EnsureRole` needs to already
    see the swapped identity. Guards: cannot impersonate another
    super_admin (start-time, throws); cannot have the impersonated identity
    silently apply on a *different* tenant's subdomain just because
    SESSION_DOMAIN is shared (continuous per-request guard in the
    middleware itself, same bug class every other S9 B0 fix closed). A
    shared `ImpersonationBanner` ("Stop impersonating") mounts on all three
    panels via the same render hook `NotificationCentre` (S8 B7) uses, plus
    both portals directly. No Tenant/User Resource existed anywhere in the
    Super panel before this block (confirmed empty, not assumed) — a single
    custom `ImpersonateUser` page with one searchable select is the whole
    "start impersonating" surface. 8 new regression tests. Tests green:
    **563/564** (same pre-existing failure).
  - **B4 — Custom domain (DNS verification only): done. All four of S1's
    "lands in S8" broken promises (TOTP 2FA, Google OAuth, impersonation,
    custom domain) are now built.** Confirmed `dns_get_record()`/`DNS_TXT`
    callable on this native Windows PHP 8.4 build before relying on it
    (no blocker). Verification token is derived
    (`hash_hmac(tenant_id.'|'.domain, APP_KEY)`), not a third schema
    column — the design doc's own schema list only names `custom_domain`/
    `custom_domain_verified_at`. New `App\Integrations\DnsResolver` +
    `RealDnsResolver`/`FakeDnsResolver`, matching every other
    interface-plus-driver in this app — but `RealDnsResolver` binds as the
    *default*, not behind a Fake, since it needs no vendor account (plain
    PHP builtin). **Caught a real bug via its own regression test**:
    `Tenant::$fillable` deliberately excludes `custom_domain_verified_at`
    (same posture as `User`/`email_verified_at`), and the first draft of
    `CustomDomainService::verify()` used `$tenant->update([...,
    'custom_domain_verified_at' => now()])` — Eloquent's mass-assignment
    guard silently drops a non-fillable key, no error, so `verify()`
    returned `true` (the DNS check genuinely passed) while the column
    itself stayed `null`. Fixed with `forceFill()`, same pattern
    `markEmailAsVerified()` already uses. `ResolveTenant` gained a
    subdomain-first, custom-domain-fallback lookup; `URL::defaults()`
    always stays on the tenant's own canonical subdomain regardless of
    which path resolved it. **Explicit, documented scope boundary**: every
    tenant route is still registered under `Route::domain('{subdomain}.lms.test')`
    only — a request whose *routing* host is a custom domain never reaches
    those routes at the router level at all (a separate concern from what
    `ResolveTenant` itself can resolve); actually serving every surface on
    a verified custom domain needs every route group re-registered under a
    second pattern, a materially bigger change than "DNS verification
    only" scopes this block to — same boundary this block's own "SSL stays
    manual" note already draws. New `CustomDomainSettings` Admin-panel page
    — this app's first tenant-settings UI of any kind (no branding editor
    exists in Filament either, confirmed by searching). 9 new regression
    tests. Tests green: **572/573** (same pre-existing failure).
  - **B5 — Self-service parent linking (invite code): done.** Neither home
    the block note offered fit (`ParentGuardianResource` only ever lists
    parent-role users; no Students Resource exists anywhere in this app,
    confirmed by listing) — built a new single-purpose Admin page instead
    (`GenerateParentInviteCode`), same shape as B3/B4's own custom pages.
    New `ParentInviteCode` model matches the design doc's schema exactly
    (no `created_at`/`updated_at` — `expires_at` already anchors "when").
    Redemption is a real host-agnostic Livewire page (`/invite`), same
    reasoning as B1/B2's own host-agnostic surfaces. **Two real bugs
    surfaced by the exact "tenant comes from the code, not the host"
    requirement, both caught before shipping**: (1) the redemption lookup
    and the generation-time uniqueness check both need
    `withoutGlobalScope('tenant')` — `code` is globally unique, but
    `BelongsToTenant`'s default scope would silently miss a valid code
    whenever `CurrentTenant` happens to be bound to some other tenant (or
    unbound); (2) `ParentGuardianLink::create()` and the
    `RoleAssignmentAudit` row `RoleAssignmentPolicy::assign()` writes both
    also use `BelongsToTenant`, and their `creating()` hooks throw
    `MissingTenantContextException` the instant `CurrentTenant` is
    unbound — exactly the redemption page's normal state. Fixed by
    temporarily rebinding `CurrentTenant` to the invite's own tenant for
    the redemption transaction only, restoring whatever was bound before
    in a `finally` block — the same rebind-for-one-call shape
    `App\Support\Tenancy\RebindsTenant` already established for queued
    jobs, applied here to an HTTP request. 8 new regression tests. Tests
    green: **581/582** (same pre-existing failure).
  - **B6 — Lesson content-type completion + content protection: done.**
    The block note's own "reusing Lesson's already-fillable columns"
    premise was wrong (sixth time this discipline has caught a stale
    plan-note claim) — no `text`/`embed`/`pdf` column existed anywhere,
    confirmed by reading every `lessons` migration; `LessonResource::form()`
    had exactly `title`/`type`/`video_upload`. New `text_body`, `embed_url`,
    `pdf_disk_path` columns — `pdf_disk_path` is a **separate** column from
    `video_disk_path`, not a reuse of it (same `'local'` disk, new column,
    so a lesson's stored path always says what kind of file it actually
    is). `LessonAccess::isPlayable()` generalized to a `match` on
    `$lesson->type` (video/live_session unchanged, pdf/text/embed each
    check their own column) — `WatchLessonController` needed no changes at
    all, it already called `isPlayable()` blindly for every type, so it had
    been silently 404ing every pdf/text/embed lesson regardless of what
    `SyllabusTree` linked to. New `DownloadLessonPdfController` (signed,
    same posture as `StreamLessonController`'s video bytes). `embed_url`
    validated against a new `Lesson::EMBEDDABLE_HOSTS` allow-list
    (YouTube/Vimeo/Google Docs) at save time, not render time.
    `text_body` sanitized via the existing `SanitizesHtml` trait. Content
    protection: `controlsList="nodownload"` + `oncontextmenu="return
    false"` on the `<video>` element, framed honestly (deters casual
    copying, not real DRM) same as the existing watermark feature. 8 new
    regression tests. Tests green: **589/590** (same pre-existing
    failure).
  - **B7 — Quiz answer autosave: done.** Closes a gap
    `AutoSubmitExpiredQuizAttempts`'s own docblock (S6) already named
    explicitly as a real, known, unclosed limitation — that job's own
    comment said closing it "is out of this block's one-line scope and not
    built here." New `QuizAttemptService::saveAnswer()` (an `updateOrCreate`
    keyed on attempt+question, called on blur/selection-change from
    `QuizRunner`, not every keystroke) writes real `QuizAnswer` rows with a
    new **`'draft'` status** — reusing `'pending_review'` was considered
    and rejected: `QuizAnswerResource`'s own review-queue `visible()` check
    would have surfaced an in-progress, still-being-typed short answer as
    "ready to review," confirmed by reading that Resource, not assumed —
    its query now explicitly excludes `'draft'` rows. `submit()` (used by
    both a real student Submit and `AutoSubmitExpiredQuizAttempts`'s own
    unchanged `submit($locked, [], 'auto_submitted')` call) now falls back
    to the already-persisted draft response whenever its own `$responses`
    array doesn't include a question — an explicit response still wins,
    so the normal submit path is unchanged (every pre-existing submit/
    auto-submit/review-queue test still passes). No existing
    "save-as-you-go" precedent existed anywhere else in this app to match
    (checked) — genuinely new UX pattern here. 9 new regression tests.
    Tests green: **598/599** (same pre-existing failure).
  - **B8 — Fee-checkout live refresh + Razorpay idempotency key: done.**
    The block note's own "sends an Idempotency-Key header" premise was
    checked against Razorpay's real API docs (WebSearch/WebFetch, not
    guessed) and was wrong for this endpoint — seventh consecutive block
    to catch a stale plan-doc claim. That header only exists on Razorpay's
    Payouts/Refunds APIs; the **Orders API** (what `createOrder()` calls)
    has no header — its `receipt` field *is* the idempotency mechanism,
    and a re-used receipt gets **rejected outright** (`400 "Duplicate
    request"`), not handed back the original order. That "rejects instead
    of degrading gracefully" behavior means idempotency has to be enforced
    *before* Razorpay is ever called twice, not relied on to dedupe after.
    `FeeInvoiceService::createPaymentOrder()` now checks for an
    already-`created` `FeePayment` first (locking the invoice row, same
    shape `applyCoupon()` already uses) and reuses it rather than calling
    the provider again; the deterministic key is
    `{invoice_number}-{attempt_number}`. `PaymentProvider::createOrder()`
    gained an `$idempotencyKey` param — every call site and both drivers'
    own tests updated. `FeeCheckout`'s live refresh is `wire:poll.3s`
    (`BROADCAST_CONNECTION` is still `log`, no real broadcasting exists,
    matches the block note's own reasoning exactly) — scoped to the
    "order created, awaiting payment" markup only, so it stops the instant
    a webhook resolves it either way. Small related fix: a fully-paid
    invoice now shows "All invoices for this enrolment are paid" instead
    of reusing the same message as the genuinely-broken "no invoice ever
    existed" case. 5 new regression tests. Tests green: **603/604** (same
    pre-existing failure).
  - **B9 — User profile page: done.** One shared `App\Livewire\UserProfile`,
    mounted exactly like `NotificationCentre` (S8 B7): both portal Blade
    layouts directly, plus added into the *existing* Filament render hook
    in `AppServiceProvider::boot()` (no second registration). New
    `avatar_path`/`timezone`/`locale` columns on `users` — none existed
    before this block (grepped every migration first, 9/9 blocks now).
    Avatar upload reuses `BrandingService::storeUpload()`'s exact shape
    (`'public'` disk, `['png','jpg','webp']` allow-list, old file deleted on
    replacement). Password change uses Laravel's built-in
    `'current_password'` rule — genuinely new territory, no prior
    password-change flow existed anywhere in this app. 2FA entry point
    nests the existing `TwoFactorSettings` component (B1), only rendered
    outside a Filament panel since Filament's own `EditProfile` already
    carries 2FA there. **Real bug found by this block's own tests**: a
    freshly created `User` (factory, or any real create-then-`Auth::login()`
    path — registration, Google OAuth, invite redemption) has
    `timezone`/`locale` **null in memory** despite the migration's DB-level
    defaults, because Eloquent's `create()` doesn't pull server-side column
    defaults back into the model after an insert. `mount()` now falls back
    with `$user->timezone ?? 'Asia/Kolkata'` — flagged in "Avoid" below,
    this is a real risk on any create-then-login path, not just a test
    artifact. 16 new regression tests. Tests green: **619/620** (same
    pre-existing failure).
  - **B10 — Production readiness: backups + real queue retry: done.**
    Installed `spatie/laravel-backup` (none installed before); backs up
    `storage/app/private` only (not the whole codebase, not
    `storage/app/public`) to a **new** `'backups'` disk — deliberately not
    `'local'`, since `'local'` IS `storage/app/private`, the very thing
    being backed up; writing the archive into its own source tree would
    make every run zip up every prior run's own zip too. Flat 30-day
    retention (the package's own default is a decaying 7/16/8/4/2-tier
    scheme, a different policy than "30 days" read literally). **Real bug
    found and fixed**: a blank `BACKUP_ARCHIVE_PASSWORD=` resolves to an
    empty string via `env()`, not `null` — an empty string still passes
    the package's own "encryption enabled" check, so it silently attempted
    AES encryption with a zero-length password, and `ZipArchive::close()`
    threw `Invalid argument` — fixed with `env(...) ?: null`. `pg_dump` is
    not on this Windows dev host's PATH (confirmed, Postgres runs in
    Docker) — a real end-to-end `backup:run` can't be exercised here;
    flagged as a deployment-environment concern, same posture as B4's "SSL
    stays manual" boundary, not silently worked around.
    Of the plan's 9 named jobs, 5 (`AutoSubmitExpiredQuizAttempts`,
    `ExpirePendingEnrolments`, `FlagOverdueInvoices`, `SendFeeReminders`,
    `RecordVideoCoverageAttendance`) were plain classes, not `ShouldQueue`
    (matches this file's own pre-existing "Avoid" note) — `Schedule::job()`
    silently runs a non-`ShouldQueue` job inline (`dispatchNow()`) instead
    of queuing it, confirmed by reading Laravel's own `Schedule::job()`, so
    `$tries`/`backoff()` on any of them would have been dead configuration
    before this block. Converted all 5 to real `ShouldQueue` jobs (now
    dispatched onto a new `'scheduled'` Horizon queue, lower-priority than
    `'default'`) with `$tries`/`backoff()`, auditing each for idempotency
    first. **`SendFeeReminders` failed that audit** — its own docblock
    already accepted "if the scheduler somehow fires this twice, the same
    reminder goes out twice" as unguarded; a real queue retry turns
    "somehow" into "will, after any transient failure," so this block
    added `alreadySentToday()` (mirrors `SendAbsenceAlert`'s S8 B3 shape)
    before enabling retry. **Second bug found**: converting these 5 to
    real queued jobs introduces a risk synchronous execution never had — a
    persistent Horizon worker processes many jobs per process, and
    `RebindsTenant` (every existing queued job) sets `CurrentTenant` and
    never clears it; a stale binding left by a prior job would silently
    narrow one of these cross-tenant sweeps to just one tenant. Fixed with
    `app(CurrentTenant::class)->clear()` at the top of each `handle()` —
    see "Avoid" below. The 4 AI ingestion jobs deliberately got **no**
    `$tries`/`backoff()` — they self-fail via `$this->fail($e)` on every
    exception (`HandlesIngestionFailure`), so Laravel's automatic retry
    never gets a chance to run regardless of `$tries`; their real retry is
    the existing manual whole-chain restart. `failed_jobs` already existed
    (Laravel's own default migration) — nothing to provision.
    `config/horizon.php` was still the package's unmodified defaults;
    added a `'scheduled'` queue (priority-ordered after `'default'` in one
    supervisor — AI ingestion/CSV export are this app's only
    interactively-triggered background jobs, someone is often waiting;
    nothing here is latency-sensitive enough to need a second supervisor).
    27 new regression tests across 7 files. Tests green: **639/640** (same
    pre-existing failure; one run hit a transient Postgres "relation does
    not exist" cluster, reran clean, treated as a one-off local hiccup).
  - **B11 — Report catalog (revenue + engagement/completion): done.**
    Two new CSV exports copying `ExportAttendanceRegister`'s exact shape
    (S8 B6) — `ShouldQueue` job + `RebindsTenant` + `Storage::disk('local')`
    + a bare Filament `Page` with generate/download header actions + a
    gated plain download controller — no new architectural pattern.
    `csvSafe()` extracted into a new shared trait,
    `App\Domains\Comms\Jobs\Concerns\EscapesCsvFormulas`, since a third
    near-identical copy would have been the same CWE-1236 bug class
    waiting to be forgotten on export #4; `ExportAttendanceRegister` itself
    retrofitted to use it too. `ExportRevenueRegister`: one row per
    `FeeInvoice` (invoice number, student, batch, amounts, status, due
    date, paid at) — deliberately not per `FeePayment`, since
    `FeeInvoice.status`/`paid_at` are already the authoritative source
    `SendFeeReminders`/`FlagOverdueInvoices` (B10) both treat as such, and
    a payments-only log would silently drop every pending/overdue invoice.
    `ExportEngagementRegister`: one row per actively-enrolled student
    (name, email, attendance %, coverage %, quiz average %, fee status),
    reusing `StudentMetrics::summarize()` per row — scoped per-student, not
    per-student-per-course, since `StudentMetrics` itself only computes a
    student's overall numbers (same granularity `StudentProgress`, S8 B5,
    already shows); roster query copied verbatim from
    `StudentProgress::studentsQuery()`. 30 new regression tests across 6
    files. Tests green: **664/665** (same pre-existing failure).
  - **B12 — Plan tiers: bandwidth quota + AI spend cap enforcement: done.**
    `Tenant.plan_tier` (flat `'free'`/`'paid'` enum, design doc §2.4
    decision 5) + hardcoded placeholder caps (bandwidth 5/50 GB day;
    AI spend $1/$10 day — explicitly not sourced from a real pricing
    decision). Bandwidth: new `BandwidthQuota` service, centralized in a
    new `LessonAccess::assertWithinBandwidthQuota()` method — found
    **two** controllers record bandwidth, not one
    (`DownloadLessonPdfController`, S9 B6, in addition to
    `StreamLessonController`), by checking every `BandwidthUsageDaily::record()`
    call site rather than assuming. AI spend: six call sites dispatch a
    real `AiProvider` call app-wide; five of six resolve it fresh from the
    container rather than holding an instance, so a decorator around the
    `AiProvider::class` binding itself (`SpendCapGuardedAiDriver`) covers
    all six with one change instead of six inline guards. Both new
    exceptions render as `abort(409, $e->getMessage())` — specific and
    actionable, not a bare 403. **Two pre-existing tests asserted the
    exact behavior this block deliberately reverses** — found and fixed,
    not left red: `BandwidthMeteringTest`'s "never blocks... no matter how
    much bandwidth" and `FakeAiDriverTest`'s "resolves as FakeAiDriver"
    (now `SpendCapGuardedAiDriver`). **Real test-fixture bug found while
    writing this block's own tests**: `UploadedFile::fake()->create($name,
    $kilobytes)` reports the given size via metadata but writes zero real
    bytes into `Storage::fake()`'s disk — `filesize()` reads back `0`,
    which can never trip any quota check. 24 new/updated regression tests.
    Tests green: **681/682** (same pre-existing failure).
  - **B13 — Low-attendance alert + automated nudges: done.**
    `InstituteOverviewWidget`'s `AT_RISK_THRESHOLD`/`isAtRisk()` (S8 B5)
    moved to `StudentMetrics` once a second consumer needed the exact same
    definition — the widget now delegates. `SendLowAttendanceAlert`
    (registered on the same `AttendanceRecorded` event `SendAbsenceAlert`,
    S8 B3, already listens to) copies that listener's exact shape
    (`ParentGuardianLink` lookup, `WhatsAppChannel`+`database` dual-channel
    `LowAttendanceAlertDue`, per-parent-per-student dedupe) — but fires on
    *every* event regardless of status, not just `'absent'`, since at-risk
    depends on the student's overall percentage, which a `'present'` mark
    can still leave below threshold. "Fires once when first crossed, not
    every check" has no true state-transition detection without a new
    schema column, so once-per-week dedupe approximates it (the plan
    note's own text offered day-or-week, not one prescribed key).
    `SendInactivityNudges` — confirmed no "last active" column exists
    anywhere first; derives it from the more recent of
    `VideoWatchEvent`/`QuizAttempt` `created_at`. Built as a real
    `ShouldQueue` job matching S9 B10's own posture exactly (`$tries`/
    `backoff()`, `'scheduled'` queue, `CurrentTenant::clear()`) — the
    first genuinely new scheduled job since B10 established that shape.
    Gated on the student's own enrolment being ≥3 days old too, not just
    activity. Notifies the student directly, not a parent — `WhatsAppChannel`
    silently no-ops for every real send today (no student anywhere in
    this app has ever had a `phone` captured, same gap `FeeReminderDue`
    already carries), `database` still lands regardless. 15 new
    regression tests. Tests green: **696/697** (same pre-existing
    failure).
  - **B14 — Full test coverage, isolation registration, review pass:
    done. S9 is now COMPLETE (all 15 blocks).** `TenantModelRegistry`
    confirmed exact-complete (22 `BelongsToTenant` models imported, all 22
    registered; `ImpersonationAudit` correctly absent — the plan note's
    own instruction to "confirm both are registered" was itself stale).
    Bug-hunting pass: a manual review of the highest-risk cross-block
    seams (render-hook composition, the `AttendanceRecorded` dual-listener,
    every `LessonAccess` call site, `AiProvider` decorator coverage, all 6
    `CurrentTenant::clear()` jobs) plus an independent background-agent
    audit given the same brief found **6 real issues, all fixed**:
    (1) **Google OAuth login silently bypassed TOTP 2FA entirely** — the
    password path (B1) correctly challenges a 2FA-enabled user,
    `GoogleAuthController::callback()` (B2) called `Auth::login()`
    unconditionally with no check at all; fixed by handing off to the same
    challenge UI the password path already has. (2) **Impersonation locked
    the real super_admin out of the Super panel** while impersonating
    anyone — `CurrentTenant` is never bound on that bare host, so the
    tenant-mismatch guard (written to reject a *mismatched* tenant) never
    fired for an *unbound* one either; fixed by requiring a bound *and*
    matching tenant for the swap to apply, not just rejecting a mismatch.
    (3) **`QuestionGenerator`'s real AI spend never reached B12's own spend
    cap** — gated correctly by the decorator, but `ChatResult::$usage` was
    discarded on every call, so it never contributed to
    `TokenCostLedger`'s measurement; fixed with a new
    `questions.token_cost` column, same shape the other two sources use.
    (4) **`docs/FEATURES.md`'s T2 ("failed-job retention") and A6 ("60-min
    auto-expiry")** were both claimed but never built — fixed with a
    scheduled `queue:prune-failed --hours=720` and an
    `ImpersonationService::MAX_DURATION_MINUTES = 60` check against the
    audit row's own `started_at`. (5) **`NotificationCentre` showed B13's
    two new notification types with a raw class-basename label** — fixed
    with two more `match` arms. **Two more real gaps flagged, not fixed**
    (bigger product/UX decisions than a review pass should make alone,
    same posture as B4's "SSL stays manual" boundary): `docs/FEATURES.md`'s
    B5 claims 2FA is "mandatory" for Super Admin + Institute Admin — it's
    opt-in for every role, never enforced; and D8/T3's "overage billing"
    and "rehearsed restore" claims — neither exists (B12 blocks access
    rather than billing for it; B10's backups have never had a restore
    exercised). 24 new regression tests. Tests green: **707/708** (same
    pre-existing failure).
- **S8 — Comms: COMPLETE** (all 9 blocks, B0-B8 —
  `docs/superpowers/specs/2026-08-12-s8-comms-plan.md`, `...-design.md`).
  The eighth and final slice of the P1 "Institute Zero" roadmap. New
  `app/Domains/Comms/` domain (`ParentGuardianLink`, `StudentMetrics`,
  `SendAbsenceAlert`, `ExportAttendanceRegister`); admin-created parent
  accounts linked to ≥1 student in one Filament action
  (`ParentGuardianResource`); a read-only `ParentPortal` (login mirrors
  `StudentPortal`, plus a tenant-mismatch logout check `StudentPortal`
  itself still lacks); `StudentMetrics::summarize()` (attendance %,
  video-coverage %, quiz average, fee status) reused across the parent
  portal, an instructor `StudentProgress` page, and an admin
  `InstituteOverviewWidget`; a same-day WhatsApp absence alert
  (`AttendanceRecorded` event, new — neither of S5's two real attendance
  writers fired one before this slice — deduped per parent+student+day);
  `FeeReminderDue` gained the `WhatsAppChannel` S7 pinned; a real queued
  (`ShouldQueue`) CSV export job, this app's first outside the AI
  ingestion chain; one shared `NotificationCentre` bell mounted on both
  portals and all three staff panels via this app's first Filament render
  hook. `RoleHierarchy::LEVELS['parent']` was split off `student`'s tied
  level (a correction — it already existed since S1, the design doc's own
  claim that it didn't was stale, caught before Block 0 started).
  - **B8's review pass found and fixed 3 real bugs**: a CSV
    formula-injection gap in the new attendance export (a student
    name/email starting with `=`/`+`/`-`/`@` becomes a live spreadsheet
    formula, CWE-1236 — fixed with a leading-apostrophe guard); a missing
    `ON DELETE CASCADE` on `parent_student_links.tenant_id` (deviation
    from both the design doc and this app's own 18-of-20 convention,
    currently unreachable but cheap to fix); and the new notification
    bell briefly rendering a cross-tenant parent's own notifications
    before that session's tenant-mismatch logout took effect (two
    sibling Livewire components on one page mounting independently, bell
    listed first — fixed by reordering so the safety check always runs
    first). **A fourth suspected bug was investigated and found to be a
    false positive**: a fix assuming Filament's `Select` doesn't validate
    a submitted value against its own tenant-scoped `options()` closure
    was reverted once its own regression test proved Filament already
    rejects the tampered value with a real validation error — the review
    process catching its own incorrect assumption before it shipped,
    full detail in the plan doc's "Post-S8 review" section.
  - Tests green: **528/529** (same 1 pre-existing `CourseSlugUniquenessTest`
    failure carried since S1/S2, still out of scope).
  - `docs/PROJECT-STATUS.md` updated to COMPLETE, matching S1-S7's own
    post-completion format — the whole P1 roadmap is now built.
- **S4 — Batches + Enrolment: COMPLETE** (all 9 blocks —
  `docs/superpowers/specs/2026-08-10-s4-batches-enrolment-plan.md`). Two
  review rounds found and fixed 6 real bugs total (seat-limit races on
  approval, a draft-batch join bypass, an unlocked cancel/waitlist path, an
  approval-expiry TOCTOU, a waitlist FIFO tiebreak gap, a policy-skipping
  admin action) — full detail in the plan doc's "Post-S4 review" sections.
- **S5 — Live Classes: COMPLETE** (all 9 blocks, B0-B8 —
  `docs/superpowers/specs/2026-08-10-s5-live-classes-plan.md`,
  `...-design.md`). `Integrations/LiveClassProvider` (+ `FakeLiveClassDriver`,
  real `ExternalLinkProvider`, real `ZoomVideoSdkProvider` not yet bound),
  `BatchSession.lesson_id` links a topic-anchor `Lesson` to a batch's
  scheduled occurrence, `BatchSession::liveState()` derives
  SCHEDULED/LIVE/ENDED/RECORDED with no stored state column, a brand-new
  student-facing syllabus tree (`App\Livewire\SyllabusTree`,
  `GET /courses/{course}`), `LessonAccess::canJoinLive()` gates joining +
  `GET /live-sessions/{session}/join`, teacher bulk attendance marking
  (H2), manual recording attach reusing S2's upload flow + S3's ingestion
  pipeline unchanged, async video-coverage attendance (H6) via a new
  daily-scheduled job.
  - **Two real gaps caught mid-build**, not by the review pass but by each
    block's own "verify, don't assume" instruction: B6 found the
    video-upload field was invisible for `live_session` lessons (form
    `visible()` only checked `type === 'video'`) — a teacher had no way to
    attach a recording at all until fixed. B7 found
    `Lesson.video_duration_seconds` — the denominator the whole ≥80%
    coverage formula divides by — had never been written anywhere in the
    app; fixed by having the existing watch heartbeat also report the
    video's own duration.
  - **B8's review pass found and fixed 1 more real bug**: a draft course's
    title and section names were readable by any guest who knew its slug —
    `ShowSyllabusController` never checked the course itself was published,
    only the syllabus tree's individual lesson rows were gated. Fixed with
    a new `LessonAccess::isCourseVisibleTo()`. Full detail, plus two items
    checked and deliberately left alone, in the plan doc's "Post-S5 review"
    section.
  - Tests green: **283/284** (same 1 pre-existing `CourseSlugUniquenessTest`
    failure carried since S1/S2, still out of scope).
  - `docs/PROJECT-STATUS.md` updated to COMPLETE, matching S1–S4's own
    post-completion format.
- **S6 — Assessments: COMPLETE** (all 9 blocks, B0-B8 —
  `docs/superpowers/specs/2026-08-11-s6-assessments-plan.md`,
  `...-design.md`). New `app/Domains/Assessments/` domain (`Question`/
  `Quiz`/`QuizAttempt`/`QuizAnswer`), built on two contracts pinned but
  unused since earlier slices: `Lesson.type` already had `'quiz'` (S1), and
  `lesson_artifacts.seed_questions` was already populated by S3 with
  nothing reading it. Question bank CRUD + AI-generated papers
  (`seed_questions`-first, `chat()` fallback) + a draft→approved review
  gate + blueprint-driven per-attempt draw (`Quiz.lesson_id` anchors to a
  `quiz`-type `Lesson`, same topic-anchor shape S5 used) + a student-facing
  `QuizRunner` Livewire component (start → answer → submit → auto-graded
  result, wired into the syllabus tree's new `quiz` branch) + a short-answer
  review queue (`/teach`'s new "Review queue" resource) + a time-limit
  auto-submit sweep + M9's raw per-quiz score history.
  - **Auto-submit runs every 5 minutes, not daily** — a deliberate deviation
    from this slice's own plan note, because a quiz's time limit is
    *minutes* and nothing else in the app enforces it (`QuizRunner`'s own
    submit path never checks `expires_at`, on purpose, trusting the sweep
    entirely); a daily cadence would have left an expired attempt
    answerable for up to 24 hours.
  - **`QuizRunner` has no answer autosave.** A response only reaches the
    database at the moment of an explicit Submit click — the auto-submit
    sweep grades a timed-out-but-never-submitted attempt as entirely blank,
    because that's genuinely all the server knows. Documented plainly in
    `AutoSubmitExpiredQuizAttempts`'s own class docblock as a known gap, a
    fast-follow candidate (would need incremental answer persistence),
    not a shortcut taken quietly.
  - **B8's review pass found and fixed 3 real bugs**: a soft-deleted
    question silently under-scored a student's in-flight attempt (fixed by
    recomputing `max_score` at submit time from the questions actually
    still gradable, not the draw-time count); `QuizResource`'s plain
    `DeleteAction` could cascade-destroy a quiz's entire graded attempt
    history in one click with no confirmation of that (fixed with a
    `visible()` guard — hidden once a quiz has any attempts); a
    partially-credited short answer displayed as a flat "Correct" in the
    student's results view, the same label a fully-correct answer gets
    (fixed to show the real per-question score instead). Full detail in
    the plan doc's "Post-S6 review" section.
  - Tests green: **356/357** (same 1 pre-existing `CourseSlugUniquenessTest`
    failure carried since S1/S2, still out of scope).
  - `docs/PROJECT-STATUS.md` updated to COMPLETE, matching S1-S5's own
    post-completion format.
- **S7 — Fees: COMPLETE** (all 8 blocks, B0-B7 —
  `docs/superpowers/specs/2026-08-11-s7-fees-plan.md`, `...-design.md`).
  Built on two pinned-but-unused contracts: `Batch.is_paid`/`price`
  (S1/S4) with `BatchEnrolmentService::enrol()` never reading either, and
  `Tenant` having no GSTIN/billing fields at all. New `app/Domains/Fees/`
  domain (`FeeInvoice`/`FeePayment`/`FeeCoupon`); payment gates through a
  new `Enrolment.status = 'pending_payment'` rather than a parallel
  system; Fees never calls `BatchEnrolmentService` directly — a symmetric
  pair of one-way-ignorant events (`EnrolmentAwaitingPayment` /
  `Fees\PaymentCaptured`) crosses the boundary both directions; coupons,
  instalment plans (`Batch.instalment_plan` template materialized into
  per-enrolment invoices, last line absorbs the rounding remainder), GST
  invoices (sequential per-tenant numbering under `lockForUpdate()`,
  GSTIN snapshot, printable HTML), a Razorpay webhook in `routes/api.php`
  (outside every tenant domain group, tenant resolved from the order's
  own `notes.tenant_id` and bound only after signature verification), and
  a fee-defaulter sweep + T-3/T-0/T+3 mail reminder (`FeeReminderDue`,
  stateless — no dedupe state needed, an offset can only match one
  calendar day under once-daily scheduling).
  - **B7's review pass found and fixed 1 real security bug**: the invoice
    download route's owner-or-staff gate checked role membership alone
    (`hasAnyRole(['instructor', 'institute_admin'])`), the same
    cross-tenant gap `EnsureRole` (S1) exists to close on the admin/teach
    panels — `SESSION_DOMAIN` is a shared parent domain, so a staff
    session from a different institute stays valid on this one's
    subdomain, and Spatie roles carry no institute of their own. A
    different institute's staff could have read any tenant's GSTIN,
    billing address, and student PII off an invoice id. Fixed by also
    requiring the viewer's own tenant to match the invoice's, mirroring
    `EnsureRoleTest`'s own cross-tenant regression case. **Flagged, not
    fixed** (out of S7's own scope): the same role-only pattern, with a
    bigger surface (any student-roled user, not just staff), is also how
    `LessonAccess`/`ShowQuizController` (Content/Assessments, S2/S3/S5/S6)
    gate lesson streaming and quiz visibility — worth a dedicated
    cross-cutting pass. Full detail in the plan doc's "Post-S7 review"
    section.
  - Also built the one thing no per-block test could cover: a true
    end-to-end story (`DemoScriptE2ETest`) chaining join -> pay over a
    real webhook POST -> auto-enrol -> invoice download -> 33 days
    later -> overdue sweep -> T+3 reminder, all in one run — the design
    doc's own testing-table citation for this ("mirrors S6 B2's tree
    E2E") pointed at a block that isn't a tree or an E2E test at all,
    caught by checking the citation rather than trusting it.
  - Tests green: **443/444** (same 1 pre-existing `CourseSlugUniquenessTest`
    failure carried since S1/S2, still out of scope).
  - `docs/PROJECT-STATUS.md` updated to COMPLETE, matching S1-S6's own
    post-completion format.
- **S8 — Comms full scope** (design doc
  `docs/superpowers/specs/2026-08-12-s8-comms-design.md`, B0 progress in
  the top bullet above): WhatsApp BSP (`CommsProvider` + `FakeWhatsAppDriver`,
  real `AiSensyProvider` unbound), a 5th `parent` role + admin-created
  student linkage, a read-only parent portal, same-day absence WhatsApp
  alert, `FeeReminderDue` gaining the WhatsApp channel S7 pinned but left
  unused, instructor/admin dashboards, one CSV report (attendance
  register), and a minimal in-app notification centre — 9 blocks (B0-B8).
  Explicitly deferred to fast-follow: self-service parent linking, the
  monthly WhatsApp report-card PDF, live-class T-30 reminders,
  announcements, and the rest of the report catalog beyond attendance —
  full reasoning in the design doc §2.2.

## Broken / open

- **No tenant provisioning or branding UI in the Super panel** — found
  during S11 B6 (2026-08-17). `docs/FEATURES.md` A5 ("Super Admin panel:
  tenant + plan CRUD") is marked P1 and S1 is marked complete, but no
  `TenantResource` existed anywhere until S11 B6 added a deliberately
  minimal one (name/status edit + list, for the "Plan" section's own
  needs only) — there is still no Create page (a new institute can only
  be provisioned via `TenantSeeder`/`artisan tinker`) and no
  logo/favicon/colors/custom-domain fields on the edit form. Not S11's
  scope to close; needs its own block whenever institute
  onboarding/provisioning actually gets picked up (not currently a named
  slice in `docs/ROADMAP.md` S12-S26 — add one when it's prioritised).

## Avoid

- Don't hand-write per-model cross-tenant isolation tests —
  `TenantModelRegistry` generates them (see `CLAUDE.md`)
- Don't call another domain's *service* class directly, even when it would
  answer exactly the question you need — cross-domain contact goes through
  `Events/` only. Cross-domain *model* reads are fine and established
  (`Question`/`Quiz belongsTo Lesson`); calling into e.g. Content's
  `LessonAccess` from Assessments code is not. Rebuild the small piece of
  logic locally reading the model directly instead (`Quiz::isVisibleTo()`
  reads `Lesson`/`Course` itself rather than calling
  `LessonAccess::isVisibleTo()`, found in S6 B5, see plan doc's B5 notes).
  **One deliberate, repeated exception (S11 B3/B4/B6):** `Billing`'s
  quota/limit services (`SeatQuota`, `StorageQuota`) get called directly
  from other domains' own write paths (`Tenancy\Policies\
  RoleAssignmentPolicy`, `Assignments\Services\AssignmentSubmissionService`,
  `Content\Models\Lesson`, `Tenancy\Services\BrandingService`) — a
  synchronous "block this action if over quota" check cannot be an event
  (events react after the fact, they don't gate before it), and `Billing`
  functions as a cross-cutting enforcement layer here, not a domain whose
  business logic another domain is reaching into. Every call site
  documents this inline; don't treat it as license to call *any* other
  domain's service, only this specific, already-established shape.
  **Caught again in S13 B17's own review pass** (`Fees\Services\PaymentReconciler`
  called `Content\Services\ProgramEnrolmentService` directly, no event) —
  fixed with a `ProgramInvoicePaid` event + a Content-side listener,
  mirroring `PaymentCaptured`'s own shape; not a case that fits the
  Billing exception above (Content's own business logic, not a
  cross-cutting quota gate)
- Don't trust a Filament `Select::relationship(..., modifyQueryUsing:
  ...)`/`modifyRelationshipQueryUsing` closure to constrain what actually
  gets **written** on save — it only narrows the dropdown's own rendered
  options and the auto-*detach* diff. The real save path
  (`Select::saveRelationshipsUsing()`) calls `$relationship->sync($state,
  detaching: false)` with the **raw submitted state**, completely
  unfiltered by that closure (confirmed by reading Filament's own vendor
  source, S13 B17). A `BelongsToMany` relationship field's own `->rule()`
  closure is the *only* real server-side gate — it must validate every
  submitted id itself (existence, tenant scope, any other business
  constraint) and `$fail()` loudly on anything invalid, never silently
  skip a null/unexpected value. Found on two fields in the same block:
  `LessonResource`'s prerequisites (same-course scoping was UI-only, and
  the existing rule silently skipped an unresolvable id instead of
  failing) and `CourseResource`'s categories/tags (no `->rule()` existed
  at all)
- Don't add a `BelongsToTenant` model without registering it — `CrossTenantTest`
  fails the build if you forget
- Don't inline a lesson visibility check in a controller — `LessonAccess`
  is the one place that answers "can this viewer see/play this lesson"
- Don't call `route('student.*')` (or any `Route::domain('{subdomain}...')`
  route) from a Blade view without passing `['subdomain' => ...]` — throws
  `UrlGenerationException` even from inside that same tenant's request
  (found in S4 B7, see plan doc's B7 gotcha note)
- Don't `expect(fn () => Livewire::test(...)->call(...))->toThrow(...)` to
  assert an `abort()`/`abort_unless()` inside a Livewire action —
  `Testable::update()` converts it into a captured HTTP response instead of
  letting the exception reach PHPUnit; assert `->assertForbidden()`/
  `->assertNotFound()` on the call chain instead (found in S5 B2, see plan
  doc's B2 notes)
- Don't assume a `Lesson::TYPES` form field (like `video_upload`'s
  `FileUpload`) is visible for every type it should logically apply to —
  its `visible()` closure is a separate, easy-to-miss gate from whatever
  storage/event logic already claims to be "type-agnostic" underneath
  (found in S5 B6: `live_session` lessons couldn't attach a recording at
  all because the field's `visible()` still checked `type === 'video'`,
  even though `attachUploadedVideo()` itself never cared about type)
- Don't add a `ShouldQueue` job/listener that calls `$this->fail($e)`
  with only the `Queueable` trait — `fail()` lives on
  `InteractsWithQueue`, which `CallQueuedListener`/`CallQueuedHandler`
  only wire up when the class actually uses that trait; without it, a
  real failure fatally errors with "Call to undefined method" instead of
  hitting your catch block's own cleanup. Bitten twice: `DownloadLiveRecording`
  (S14 B9) found and fixed it in itself but explicitly left a sibling,
  `GenerateLessonSubtitles` (S14 B2), unfixed — B18's review pass closed
  it. Both traits together (`Dispatchable, InteractsWithQueue, Queueable`)
  is the safe default for any `ShouldQueue` class that calls `fail()`. A
  happy-path test alone won't catch this — you need one that forces the
  throw
- Don't call `Model::create()` on a `BelongsToTenant` model from a
  scheduled job that runs with no `CurrentTenant` bound (the
  `ExpirePendingEnrolments`/`RecordVideoCoverageAttendance` pattern) —
  the trait's `creating()` hook calls `CurrentTenant::id()`
  unconditionally and throws `MissingTenantContextException`. Updating an
  already-loaded row is fine (no `creating` event fires); creating a new
  one needs `Model::withoutEvents(fn () => Model::create([..., 'tenant_id'
  => $explicitTenantId]))` (found in S5 B7, see plan doc's B7 notes). Same
  gap in an HTTP request with no tenant-bearing subdomain (a host-agnostic
  route, S9 B1/B2/B5's own pattern) — when the request needs to create
  *multiple* `BelongsToTenant` rows in one call (`ParentInviteCodeService::redeem()`
  creates a `User`, a `ParentGuardianLink`, and — via
  `RoleAssignmentPolicy::assign()` — a `RoleAssignmentAudit`), rebinding
  `CurrentTenant` for the duration of the call and restoring it in a
  `finally` block (`App\Support\Tenancy\RebindsTenant`'s own shape,
  originally built for queued jobs) reads more correctly than
  `withoutEvents()` on each model individually (S9 B5).
- Don't assume a scheduled job only *updates* rows just because the block
  that built it planned it that way — check whether the shared service
  method it calls also *creates* anything (`QuizAttemptService::submit()`
  creates `QuizAnswer` rows even though `AutoSubmitExpiredQuizAttempts`'s
  own plan note assumed "pure update, no wrinkle"). And if a per-row fix
  ends up binding `CurrentTenant` to that row's own tenant inside a
  cross-tenant sweep's loop, that binding leaks into the *next* row's own
  re-fetch unless it's explicitly `withoutGlobalScope('tenant')` —
  otherwise row 2's lookup gets silently scoped to row 1's tenant and
  quietly no-ops (found in S6 B7, see plan doc's B7 notes)
- Don't gate a plain (non-Filament) per-record controller with a role
  check alone (`$user->hasAnyRole([...])`) — `SESSION_DOMAIN` is a shared
  parent domain, so a staff session from a *different* tenant stays valid
  on this one's subdomain, and Spatie roles carry no tenant_id of their
  own. `EnsureRole` already closes this for every Filament panel
  (`$user->tenant_id !== $currentTenant->id()`, tested in
  `EnsureRoleTest`); any new plain controller with an owner-or-staff gate
  needs the same tenant cross-check added by hand, it doesn't come for
  free (found in S7 B7 — `DownloadInvoiceController` was leaking GST
  invoices across tenants to any instructor/admin role, see plan doc's B7
  notes). `LessonAccess`/`Quiz::isVisibleTo()` (Content/Assessments) had
  the same gap — fixed in S9 B0, no longer open.
- Don't assume a middleware appended to the global `web` group
  (`$middleware->web(append: [...])`, `bootstrap/app.php`) reaches a
  Filament panel's own page loads just because `ResolveTenant::class.':optional'`
  does — that one only needs the global append to *additionally* cover
  Livewire's own `/livewire/update` endpoint, since every panel already
  lists `ResolveTenant::class` explicitly in its own `->middleware([...])`
  array for the strict case. A middleware with no such per-panel explicit
  entry (`ResolveImpersonation`, S9 B3) only fires on Livewire AJAX
  round-trips if left in the global group alone — a panel's actual page
  load bypasses the `'web'` group entirely (Filament panels list concrete
  middleware classes, never the bare `'web'` string). Register it in both
  places: the global group *and* each panel provider's own middleware
  array.
- Don't assume `Model::update([...])`/`create([...])` returning without an
  exception means every key you passed it actually persisted — a key
  that's deliberately not in `$fillable` (e.g. `email_verified_at`,
  `custom_domain_verified_at` — anything meant to be set only
  programmatically, never via mass assignment) is **silently dropped**, no
  error at all. Hit twice in S9 (B2's `GoogleOAuthAuthenticator`, B4's
  `CustomDomainService`) before the fix in both cases: use `forceFill([...])
  ->save()` for that one specific field, matching how
  `User::markEmailAsVerified()` already does it — and assert the actual
  persisted column value in a test, not just a method's own boolean return,
  when the field is intentionally non-fillable.
- Don't trust a design/plan doc's claim that a key/role/column "doesn't
  exist yet" without grepping the live file first — S8's own design doc
  asserted `RoleHierarchy::LEVELS` had no `parent` key; it had one since
  S1 (`'parent' => 4`, tied with `student`), just not the value the doc
  wanted. Read the file before editing it, even when a spec doc sounds
  confident (found in S8 B0, see plan doc's B0 notes).
- Don't assume a model's default table name follows from its class name
  when the table itself was named independently in a design doc —
  `ParentGuardianLink` (class) vs. `parent_student_links` (table, per
  design doc §3.1) don't match Eloquent's singular→plural guess
  (`parent_guardian_links`), and nothing catches the mismatch until a
  query actually runs (`CrossTenantTest`, not a static check). Set
  `protected $table` explicitly whenever a model's name and its table's
  name are allowed to diverge (found in S8 B0, see plan doc's B0 notes).
- Don't add a domain-specific relation method (e.g. `feeInvoices()`,
  `enrolments()`, `linkedStudents()`) onto the shared `App\Models\User` —
  every domain model already `belongsTo(User::class)`, but none of them
  add the reverse relation back onto `User` (confirmed by reading
  `User.php`: it has `tenant()` only, because tenancy is foundational, not
  because the pattern is "add your domain's relation here"). Doing so
  would couple the one shared model to every domain that ever needs a
  User-keyed lookup. Query the domain model directly instead
  (`ParentGuardianLink::where('parent_user_id', $user->id)`, not
  `$user->linkedStudents()`) — found in S8 B1 while deciding how to let a
  Filament `RelationManager` manage `ParentGuardianLink` (see plan doc's
  B1 notes for why a `Repeater` was used instead of a `RelationManager`
  there).
- `App\Livewire\StudentPortal` has no tenant cross-check on its own
  session — `SESSION_DOMAIN` is a shared parent domain, so a student
  logged in at tenant A stays authenticated if they open tenant B's
  `/dashboard` directly, the same gap class `EnsureRole`/
  `DownloadInvoiceController` close elsewhere. `App\Livewire\ParentPortal`
  (S8 B1) got the fix on `mount()` because its own block required it;
  `StudentPortal` itself was deliberately left as-is — flagged here, not
  silently carried forward as if it were fine.
- Don't take a design doc's dedupe/idempotency wording at face value when
  the notifiable can plausibly receive the same notification type for two
  *different* underlying subjects on the same day — "same notifiable, same
  type, same day" (S8 design doc §2.4 decision 5) reads as one rule, but
  literally implementing it against a parent linked to two students would
  make the second child's absence alert silently vanish on any day both
  are absent, since it'd match the same notifiable+type+day already used
  by the first. `SendAbsenceAlert::alreadySentToday()` (S8 B3) additionally
  keys on which student the alert is about, read back from the stored
  notification's own `data` payload — narrow the dedupe key to whatever
  the notification is actually *about*, not just its type, whenever a
  notifiable can plausibly receive more than one distinct instance of the
  same type per day.
- Don't assume a design/plan doc's roadmap-level claim (e.g. "S5's three
  attendance writers") is still accurate just because it's repeated
  consistently across multiple docs — it can describe an *earlier* plan
  that was later narrowed (S5's third, live-webhook, writer was deferred
  to fast-follow and never built), and nothing goes back to correct every
  doc that mentioned the original count. Read the actual writer code (or
  a model's own docblock, which had it right: "two writers ship in S5; a
  third is fast-follow") before trusting a specific number repeated in
  prose (found in S8 B3, see plan doc's B3 notes).
- Don't assume a `User` with the `student` role has a `phone` set — nothing
  in this app ever writes one for a student; the only write site is
  `ParentGuardianResource` (S8 B1), and only for parents. Any
  `WhatsAppChannel`-routed notification sent to a student silently no-ops
  (blank-phone guard, S8 B0) — true today for `FeeReminderDue` (S8 B4).
  If a future block needs a student-facing WhatsApp send to actually work,
  it needs its own phone-capture surface first, not just a channel added
  to `via()`.
- Don't assume "the instructor's own batches/courses/students" has any
  backing schema to enforce it — no instructor-batch or instructor-course
  pivot exists anywhere in this codebase. Every `/teach` surface today
  (`CourseResource`, `QuestionResource`, `StudentProgress`) scopes only by
  tenant, not by instructor, and `EnrolmentApprovalPolicy`'s own docblock
  already documents this exact gap (S4), reading "assigned" as "same
  tenant" for lack of real data. Follow that same reading for any new
  "instructor's own X" surface rather than inventing a stricter scope
  with nothing to enforce it (found again in S8 B5, see plan doc's B5
  notes — this is now two slices independently hitting the same gap).
- A Filament `TextColumn`'s `formatStateUsing()` never runs when `state()`
  returns `null` — the column's own render path short-circuits on blank
  state and shows `getPlaceholder()` instead (`vendor/filament/tables/src/Columns/TextColumn.php`,
  the `blank($state)` branch). A null-check *inside* `formatStateUsing()`
  (`fn (?float $state) => $state !== null ? ... : '—'`) silently never
  reaches its own fallback branch — use `->placeholder(...)` for that
  instead (found in S8 B5, `StudentProgress`'s attendance/coverage/quiz
  columns, caught by a test asserting the fallback text actually
  appeared).
- **Stale as of S9 B10 — kept for the dated lesson it still teaches.**
  Through S8, only the AI ingestion chain (`TranscribeLesson`/
  `ChunkTranscript`/`ExtractLessonConcepts`/`EmbedLessonChunks`) and
  `ExportAttendanceRegister` were real `ShouldQueue` jobs; the five
  scheduled sweeps (`ExpirePendingEnrolments`, `RecordVideoCoverageAttendance`,
  `AutoSubmitExpiredQuizAttempts`, `FlagOverdueInvoices`,
  `SendFeeReminders`) were plain classes the cron scheduler ran
  synchronously — `docs/FEATURES.md`'s T2 row ("job queues... retry with
  backoff") wasn't what those five actually did, despite prose elsewhere
  (S8's own plan doc, B6) grouping them together as if they were. **S9 B10
  converted all five to real `ShouldQueue` jobs** with `$tries`/`backoff()`,
  closing that exact gap — `docs/FEATURES.md`'s T2 row is now accurate.
  The lesson stands regardless: check `implements ShouldQueue` on the
  actual class before citing something as "the queued-job precedent," or
  assuming `$tries`/`backoff()` on a class do anything at all — see the
  S9 B10 entry further below on `Schedule::job()` silently running a
  non-`ShouldQueue` class inline (found in S8 B6, see plan doc's B6 notes;
  corrected in S9 B10, see plan doc's B10 notes).
- `Illuminate\Support\Facades\Storage::disk(...)->download()` returns a
  Symfony `StreamedResponse`, not `Illuminate\Http\Response` — a
  controller method type-hinted to return the latter throws a `TypeError`
  on every real call. Type-hint plain file-serving controller methods as
  `Symfony\Component\HttpFoundation\Response` (the actual common parent)
  instead (found in S8 B6, `DownloadAttendanceExportController`).
- Don't put `app(CurrentTenant::class)->set($tenant)` in a shared
  `beforeEach` "just in case" when a test file's fixtures don't actually
  need it (e.g. plain `User::factory()->create(['tenant_id' => ...])`
  calls, which don't rely on `BelongsToTenant`'s `creating()` hook). A
  stale binding survives for the rest of that test method and leaks into
  any later request the test makes that has no `ResolveTenant` middleware
  of its own to rebind or clear it — the bare `lms.test` super-panel
  domain is exactly that case, and the leaked tenant tripped `EnsureRole`'s
  cross-tenant check against a `tenant_id => null` super admin (found in
  S8 B7, `NotificationCentreMountedTest`). Only bind `CurrentTenant`
  manually when a fixture in that same test genuinely needs it.
- Don't trust `$user->someColumn` to hold a column's DB-level `default(...)`
  right after `Model::create([...])` (or `User::factory()->create([...])`)
  without explicitly passing that column — Eloquent doesn't pull
  server-side defaults back into the in-memory model after an insert (only
  the generated ID round-trips, via Postgres's `RETURNING`). Any
  create-then-`Auth::login()` path (registration, Google OAuth, an invite
  redemption) hands that same not-yet-refreshed instance to the session, so
  code reading `Auth::user()->thatColumn` right after can see `null` even
  though every row in the table has the default. Fall back explicitly
  (`$user->timezone ?? 'Asia/Kolkata'`) or `->fresh()` the model — don't
  assume the attribute is populated (found in S9 B9, `UserProfile::mount()`).
- A test that visits more than one Filament panel (or a tenant-bound panel
  *then* the bare-host super panel) inside a single `it()` can trip
  `EnsureRole`'s cross-tenant check on the later visit — `CurrentTenant` is
  a singleton that survives across multiple `$this->get()` calls within one
  Pest test, so a tenant bound by the first request is still bound on the
  second. `NotificationCentreMountedTest` (S8 B7) already documents this in
  a comment; split one `it()` per panel instead of combining them (found
  again in S9 B9 while writing `UserProfileTest`).
- Laravel's `confirmed` validation rule looks for a literal
  `"{field}_confirmation"` key, not a camelCase equivalent — a Livewire
  property must be named e.g. `newPassword_confirmation`, not
  `newPasswordConfirmation`, or the rule silently never finds its pair and
  every confirmation fails validation. Matches this app's own existing
  `password`/`password_confirmation` convention already used by
  `RegisterUserRequest`/`ResetPasswordRequest` (found in S9 B9).
- Don't trust a bare `env('SOME_KEY')` to be `null` just because the
  `.env` line is blank (`SOME_KEY=`) — it resolves to an empty string,
  which still passes a `!== null` check. `config/backup.php`'s own
  `password` (`spatie/laravel-backup`) is exactly this shape: a blank
  `BACKUP_ARCHIVE_PASSWORD=` silently satisfied the package's "encryption
  enabled" check with a zero-length password, and `ZipArchive::close()`
  threw `Invalid argument` the instant that happened — not a clean
  "encryption disabled" no-op. Use `env('KEY') ?: null` wherever a blank
  env value must genuinely mean "unset," not "empty string" (found in S9
  B10).
- Don't assume `$tries`/`backoff()` do anything on a job class just
  because it's dispatched via `Schedule::job(...)` — that helper silently
  falls back to running a job **inline** (`dispatchNow()`, no queue
  involved at all) unless the class actually `implements ShouldQueue`;
  confirmed by reading `Illuminate\Console\Scheduling\Schedule::job()`
  itself. Five of this app's own scheduled jobs were plain classes for
  exactly this reason (see the entry below on "every scheduled job is a
  plain class," now stale — fixed in S9 B10). Also: a job whose own
  `handle()` catches every exception internally and calls `$this->fail($e)`
  explicitly (the four AI ingestion jobs, `HandlesIngestionFailure`) never
  gives Laravel's automatic queue retry a chance to run either, regardless
  of `$tries` — that logic only fires when an exception propagates up to
  the queue worker uncaught. Check both of these before assuming a
  `$tries`/`backoff()` you're about to add will actually do anything (S9
  B10).
- A cross-tenant sweep job that relies on `BelongsToTenant`'s global scope
  being a no-op (i.e., expects `CurrentTenant` to be *unbound*, not bound
  to anything in particular) is only safe while it runs in a fresh,
  short-lived process. The instant such a job becomes a real queued job
  processed by a persistent Horizon worker, it can run right after any
  other tenant-bound job in the *same* PHP process — and `RebindsTenant`
  (used by every existing queued job) sets `CurrentTenant` and never
  clears it. A stale binding left over from whatever ran immediately
  before would silently narrow the sweep to just that one tenant, with no
  error. `app(CurrentTenant::class)->clear()` at the very top of `handle()`
  is the fix — cheap, and needed the moment a previously-synchronous
  cross-tenant job gets converted to `ShouldQueue` (found converting five
  jobs to real queued jobs in S9 B10; the risk didn't exist before that
  conversion, since a fresh scheduler process starts with nothing bound).
- `UploadedFile::fake()->create($name, $kilobytes)` reports `$kilobytes`
  via the upload's own `getSize()` metadata but writes **zero real
  bytes** into `Storage::fake()`'s disk — a `filesize()` call on the file
  once stored reads back `0` regardless of the declared size. Any test
  that needs a real byte count from a fake-uploaded file (a bandwidth
  quota check, anything measuring actual content size) must overwrite the
  stored path with real content afterward
  (`Storage::disk(...)->put($path, str_repeat('x', $n))`), not trust the
  fake upload's own declared size (found in S9 B12, `LessonContentTypesTest`'s
  PDF-quota test — confirmed by dumping actual state, not guessed).
- When a container binding gets wrapped in a decorator (`AiProvider::class`
  now resolves to `SpendCapGuardedAiDriver` wrapping `FakeAiDriver`, S9
  B12), grep for any existing test asserting the OLD concrete resolution
  type (`toBeInstanceOf(FakeAiDriver::class)`) — it will now fail, and
  needs updating to assert the new wrapper type, not silently left
  red or deleted (found in S9 B12, `FakeAiDriverTest`, same posture as
  `BandwidthMeteringTest`'s own reversed-behavior test the same block hit).
- A "does the acting identity's own tenant match the resolved host" guard
  shaped as `if ($tenant->isBound() && $mismatch) { reject }` only rejects
  a *mismatched* tenant — it silently does nothing on a host that never
  binds a tenant at all (the bare Super panel host, by design). If the
  guarded action should *only* apply within a specific, resolved tenant
  context (impersonation only makes sense inside a tenant's own surfaces,
  never the platform-level Super panel), the condition needs to require a
  bound *and* matching tenant, not just reject a bound-and-mismatched one:
  `if (! $tenant->isBound() || $mismatch) { reject }`. Found in
  `ResolveImpersonation` (S9 B14) — the original form let a swap silently
  keep applying on the bare host, 403ing the real actor via `EnsureRole`'s
  own super_admin check with no way to tell why.
- `queue:prune-failed`, not `queue:prune-failed-jobs` — the more
  guessable name doesn't exist (confirmed via `--help`, not assumed;
  found in S9 B14).
- When a new call site gets wired into an existing spend/usage cap
  (`AiProvider`'s container-binding decorator, S9 B12), confirm its own
  cost actually reaches the *measurement* the cap reads from, not just
  that the *gate* covers it — `QuestionGenerator::generate()` was
  correctly gated by `SpendCapGuardedAiDriver` from the day B12 shipped,
  but its own real `ChatResult::$usage` was discarded on every call, so
  `TokenCostLedger` never saw it and the cap it was supposedly enforced
  against never actually moved (found in S9 B14 — gating and measuring
  are two separate things a single "covers all six call sites" decorator
  claim can silently only be true for one of).
- Don't assume a `belongsTo` relation on a `SoftDeletes` model is safe to
  dereference unconditionally just because the row currently has an
  active status — `Batch`/`Course` are both `SoftDeletes`, and (unlike
  `Assignment`/`Quiz`, which both hide their own delete action once real
  submissions/attempts exist) `BatchResource`'s `DeleteAction` has no
  guard against active enrolments at all. An `Enrolment.status = 'active'`
  row can point at a soft-deleted `Batch`, or a `Batch` whose own `Course`
  was soft-deleted — every Gradebook consumer that read
  `$enrolment->batch->course` unconditionally would crash on that one
  stale row (found in S10 B15's own review pass, four call sites: the
  shared `BuildsGradebookRows` trait, both `/teach` and `/admin`
  `Gradebook` pages, and `CheckCourseCompletionAndIssueCertificates`).
  Filter at the query level — `->whereHas('batch', fn ($q) =>
  $q->whereHas('course'))` — rather than null-checking in every column
  closure or loop body.
- A `count()`-then-`create()` pattern with no row lock races the same way
  `BatchEnrolmentService::enrolDirect()`'s own seat-limit check did before
  S4's fix — two requests (a double-click, two open tabs) can both read
  the same count before either commits, then both aim for the same
  DB-unique-constrained value. `AssignmentSubmissionService::submit()`
  hit this for `version_number` (found in S10 B15's own review pass, not
  a failing test): fixed with one retry on `QueryException`, recomputing
  the count fresh, matching `BatchEnrolmentService::createEnrolment()`'s
  own "catch the constraint, recover cleanly" shape (S4 B3). The retryable
  insert itself needs its own `DB::transaction()` too — a real SAVEPOINT
  when nested inside any caller's already-open transaction (a test's
  `RefreshDatabase` wrapper included) — or the constraint violation
  poisons the *whole* enclosing transaction (Postgres SQLSTATE 25P02,
  first hit in S10 B11's own review pass on an unrelated query) and the
  retry's own recompute query fails too, turning "recovered from the
  race" into "crashed on the recovery attempt instead."
- When simulating a concurrent-write race in a single-connection test
  (there's no real second connection to race against), hook the
  *pre-transaction* read the code performs (a `DB::listen()` on the
  `count()`/`SELECT` query), not an Eloquent model event fired *inside*
  the code's own `DB::transaction()` — a competing insert triggered from
  inside that transaction is only a **savepoint** deep, so it gets rolled
  back together with the deliberately-failed attempt instead of surviving
  it, and the "race" silently stops reproducing the moment the code gains
  its own transactional wrapping (found in S10 B15 while testing the
  fix immediately above — the first version of the test used
  `AssignmentSubmission::creating()` and passed for the wrong reason
  before failing once the real fix was in place).
- Don't trust a custom app cookie (one never added to `EncryptCookies`'
  own `$except` list — true of every real, server-only cookie in this
  app, `lms_device_id` included) to survive Laravel's test HTTP client
  with a known, predictable value. `withUnencryptedCookie()` sends a value
  the middleware then tries to *decrypt*, fails, and silently drops
  (`catch (DecryptException)`) — a real HTTP test then sees no cookie at
  all. Switching to `withCookie()` (the documented, correct
  *encrypted*-cookie helper) did **not** reliably fix this either, across
  three separate attempts in S12 B2 — root cause not fully isolated (a
  `CookieValuePrefix` validation mismatch inside `EncryptCookies` is the
  leading suspect). B1's own tests happened to pass despite hitting the
  identical `withUnencryptedCookie()` mistake, purely because none of
  them depended on a cookie value surviving a second request (each of its
  3 device-limit logins supplied a different raw value anyway — a
  silently-nulled cookie still produced 3 distinct-looking devices,
  right answer for the wrong reason). Current best-known-working
  approach: call the middleware/service under test directly
  (`(new Middleware)->handle($request, $next)`, a `Request::create()`
  object with cookies set directly on it) rather than dispatching a real
  HTTP request at all — sidesteps `EncryptCookies` entirely instead of
  fighting it. A real, stated gap in this suite's own coverage — the
  literal HTTP-level wiring of anything reading a *custom* cookie is not
  provable this way, only the logic is (see S12 B2's own plan-doc notes
  for what was tried).
- Don't assume `Carbon::diffInSeconds()`'s plain default (no extra args)
  call is already unsigned just because `diffInDays()` behaves that way
  elsewhere in this app (`PlanChangeService::prorate()`, S11 B9) — in
  this Carbon version, `$toAt->diffInSeconds($fromAt)` still returned a
  *negative* value when `$toAt` was genuinely after `$fromAt`, found by
  `ImpossibleTravelDetector`'s own "should NOT flag a plausible trip"
  test failing (S12 B3) — a negative diff floored straight to a
  `max(0.001, ...)` minimum-hours guard, turning a real ~50 km/h trip
  into an apparent multi-million-km/h one no config threshold could ever
  un-flag. Wrap in `abs()` explicitly for any `diffIn*()` call whose sign
  you haven't independently verified for that specific method in this
  Carbon version — don't extrapolate from a different `diffIn*()`
  method's own already-observed behaviour.
- A "which statuses count as live/active" whereIn list written when a
  model only had 3-4 statuses easily goes stale the moment a later block
  adds a new one — `PlanChangeService::liveSubscriptionFor()`'s
  `[TRIALING, ACTIVE, GRACE]` filter predates `SUSPENDED` even existing as
  a `TenantSubscription` status (B8 added it), and nothing forced a
  revisit when it did. The UI-side symptom is the real danger: a header
  action's own `->visible()` check (`EditTenant`'s `cancelSubscription`/
  `changePlan`) can easily key off a *different*, broader condition
  (`plan_id !== null`) than the service method it calls actually accepts,
  so the button stays clickable and *looks* fine right up until it throws
  — found in S11 B16's own review pass, not by a failing test. When a
  model gains a new status, grep every whereIn/match over its status
  column, not just the state machine that introduced the status.
- When a status column mirrors another model's status column for a
  real-world reason (`Tenant::status` mirrors `TenantSubscription::status`
  for suspension — decision 8, S11 design doc), a fix that makes the
  *forward* transition correct (suspend both rows together, already true
  since B8) doesn't guarantee the *reverse* transition is too.
  `SubscriptionReconciler::charged()` correctly recovers
  `TenantSubscription::status` on a successful payment but, until S11
  B16's review pass caught it, never touched `Tenant::status` at all — a
  tenant billing-suspended by the daily sweep whose payment later
  recovered stayed locked out of login forever, since nothing else in the
  app ever un-suspends a tenant automatically. Whenever a mirrored pair of
  columns gets a "enters state X" fix, check the code path that reverses
  it too, even if no test was asserting the reverse path was broken.
- When a "revoked"/"disabled"/"cancelled" check queries a table that
  *inserts a new row* rather than *updating the old one* on the reversing
  action (re-registration, re-subscription, etc.), an unscoped
  `->exists()`/`->where(...)->count()` over every historical row for that
  key will keep matching the old, still-flagged row forever — found in
  S12 B14's own review pass: `DeviceRegistrar::registerLogin()` (B1)
  inserts a fresh `device_sessions` row when a revoked fingerprint logs
  back in rather than reviving the old one (matches `MyDevices`'s own "log
  back in afterward, using up one of your device slots again" UX text),
  but `EnsureDeviceNotRevoked` (B2) checked `->whereNotNull('revoked_at')->exists()`
  across every row ever, not the latest — permanently locking out a
  legitimately-relogged-in device. Any check like this needs `->latest('id')->first()`
  (or an equivalent "current row" scope), not a blanket existence check
  over the row's full history.
- Don't write an audit-log entry (or any "this thing happened" record)
  before the code that can still fail to make it happen — found in S12
  B14's own review pass: `RoleAssignmentPolicy::assign()` wrote its
  `AuditLogger`-backed `'role.assigned'` row *before* the seat-quota
  `DB::transaction()` branch that can throw `SeatLimitExceededException`
  after the privilege check already passed, leaving a false "success" row
  in the general audit sink for a role grant that never actually took
  effect. `AuditLogger`/`RoleAssignmentAudit` writes belong strictly after
  every operation that can still throw, never before — `ImpersonationService::start()`
  and `EditTenant`'s `changePlan` action both already got this right
  (checked directly in the same review pass), this was the one call site
  that didn't.

## Where the detail actually lives

- Full roadmap + what's shipped: `docs/PROJECT-STATUS.md`
- The original 8-slice roadmap (S1-S8) is complete; S9, S10, S11 are later
  additions, not on it. Most recently completed slice's own design + plan
  (S11 — Plan Tiers + Institute Billing, COMPLETE, all 17 blocks):
  `docs/superpowers/specs/2026-08-16-s11-plan-billing-design.md`,
  `...-plan.md`. S10 (Assignments + Gradebook + Certificates, COMPLETE, 16
  blocks): `docs/superpowers/specs/2026-08-16-s10-assignments-gradebook-design.md`,
  `...-plan.md`. S9 (Hardening & Backlog, COMPLETE, 15 blocks):
  `docs/superpowers/specs/2026-08-12-s9-hardening-backlog-design.md`,
  `...-plan.md`.
- Why things are built this way: `DECISIONS.md`
- How a request actually flows through the code: `FLOW.md`
- System map (domains, surfaces, shared infra): `ARCHITECTURE.md`
- Codebase conventions + footguns: `CLAUDE.md`
- Per-bug / per-feature trace files: `docs/bugs/`, `docs/features/`

---

Update this file at the end of every session, or the moment a block
finishes or breaks — whichever comes first. A stale handover is worse than
no handover: it actively misleads the next session.
