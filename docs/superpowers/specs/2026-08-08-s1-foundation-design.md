# Design: S1 Foundation — AI-Native LMS

Date: 2026-08-08
Status: APPROVED
Scope: Sub-project 1 of 8 in the P1 build (Institute Zero)
Window: 2026-08-08 → 2026-08-22 (demo #1 date)
Supersedes on two points: `docs/DESIGN.md` §3 (learner UI, live-class provider)

---

## 1. Why this document exists

`docs/FEATURES.md` holds ~225 features across 21 modules, ~95 of them in P1.
That is not one spec. It is at least eight subsystems, and no version of that
document has ever carried a schedule.

This document does two things:

1. Decomposes P1 into eight sub-projects with a dependency order and a calendar.
2. Specifies sub-project **S1 (Foundation)** in full: scope, architecture,
   error handling, testing, and the demo it has to survive.

It also records two stack reversals decided during this session (§7).

---

## 2. P1 decomposition

### 2.1 Dependency graph

```
 S1 Foundation ──┬──► S2 Content + Video ──► S3 AI Spine ──► S6 Assessments
 tenancy·auth    │           │                    │                 │
 roles·branding  ├──► S4 Batches + Enrolment ─────┼──► S5 Live Classes
                 │           │                    │           │
                 │           └──► S7 Fees ────────┼───────────┤
                 │                                │           ▼
                 └──► S8 Comms (WhatsApp/Parent) ◄┴──── Attendance ──► Analytics
```

Attendance and Analytics are not sub-projects. Attendance is one
`attendance_records` table with writers contributed by S4 (manual), S2 (async
video coverage), and S5 (live webhooks). Analytics is a read layer over the
watch events S2 produces and the results S6 produces. Hence eight, not ten.

### 2.2 Calendar

One sub-project per fortnight. **Each sub-project boundary is a demo date.**
This merges two cadences that were previously written as separate things: the
CEO review's D3 ("demoable milestone gates every ~2 weeks") and VALIDATION.md's
D2 ("demo friend, fixed fortnightly date"). They are now one calendar with one
date each.

| # | Sub-project | Demo date | What the demo friend sees |
|---|---|---|---|
| S1 | Foundation — tenancy, auth, roles, branding, isolation suite | **2026-08-22** | Their own branded institute; login; course tree |
| S2 | Content + Video — HLS player, watermark, watch heartbeat | 2026-09-05 | A student watching a lecture, resuming across devices |
| S3 | AI Spine — `lesson_artifacts`, ingestion, doubt chat | 2026-09-19 | Upload a lecture; get transcript, concepts, a doubt answered |
| S4 | Batches + Enrolment — batches, seats, waitlist, timetable | 2026-10-03 | One batch, ten students enrolled |
| S5 | Live Classes — topic-anchored sessions, attendance | 2026-10-17 | Join a live class from the syllabus tree; the recording attaches back to that same node (manually in S5 — see §7.2) |
| S6 | Assessments — question bank, AI paper, quiz runner, grading | 2026-10-31 | A question paper generated from the teacher's own notes |
| S7 | Fees — Razorpay, instalments, GST invoice, defaulters | 2026-11-14 | Fees collected, an instalment schedule, a reminder firing |
| S8 | Comms — WhatsApp BSP, parent portal, dashboards, reports | 2026-11-28 | A parent's phone receiving an absence message |

**~16 weeks to Institute Zero.** This is the project's first schedule. Its
value is not accuracy; it is that slippage becomes visible on a specific date
instead of being invisible indefinitely.

### 2.3 Outside the slot structure

- **`git init` + CI** is inside S1 (task T12). Until it exists, the
  cross-tenant isolation "CI gate" — the most-cited safety mechanism in the
  whole plan — is imaginary.
- **The WhatsApp BSP application starts on 2026-08-08**, not in S8. Approval is
  external and slow. Waiting until S8 blocks the 2026-11-28 demo on a third
  party.

---

## 3. S1 scope

### 3.1 In scope

| # | Deliverable | Source |
|---|---|---|
| 1 | `git init` + GitHub Actions CI; Pest; isolation suite runs on every push | T12 |
| 2 | Laravel + PHP 8.3 skeleton; Docker Compose with PostgreSQL 16 + pgvector + Redis 7 | DESIGN §3 |
| 3 | `tenants` table; `tenant_id` on every tenant-owned model; `BelongsToTenant` trait with a global scope | A1, A3 |
| 4 | `ResolveTenant` middleware: subdomain → tenant, otherwise 404 | A1 |
| 5 | Cross-tenant isolation test suite as the CI gate | A3 |
| 6 | Auth: register, login, email verification, password reset, remember-me, rate limiting, lockout | B1, B3, B4 |
| 7 | Five roles via `spatie/laravel-permission`; downward-only assignment guard | B6, B7, B8 |
| 8 | Three Filament panels wired and role-gated: `/super`, `/admin`, `/teach` (empty shells) | DESIGN §3.1 |
| 9 | Livewire student shell at `/` (login → dashboard placeholder) | §7.1 |
| 10 | White-label branding: logo, favicon, primary/accent colour, applied across all four surfaces | A7 |
| 11 | Course → Section → Lesson models + Filament CRUD, **structure only** | C1, C2 |
| 12 | `lesson_artifacts` schema contract pinned on paper (§5), no code | T1 |
| 13 | Security baseline: CSRF, XSS sanitisation, explicit `$fillable`, file-type allowlist | B14 |

### 3.2 Out of scope, and where each goes

| Cut | Lands in | Why deferring is safe |
|---|---|---|
| Custom domain + SSL | S8 | Subdomain routing is sufficient through Institute Zero; purely additive |
| Impersonation + audit log (A6) | S8 | A support feature. There are no users to support yet |
| TOTP 2FA (B5) | S8 | One middleware and one table. Additive, not structural |
| Google OAuth (B2) | S8 | Socialite bolt-on; touches no schema |
| Plans / subscriptions (A2) | P3 | This is B2B billing (module Q). It was mis-filed as P1 |
| All lesson content (video, PDF, text bodies) | S2 | S1 builds the tree; S2 hangs leaves on it |
| Parent↔student linkage logic (O1, T7) | S8 | The role is defined in S1, but no parent user can be created and no `/parent` route exists, so there is no reachable authorization hole |

---

## 4. Architecture

### 4.0 Codebase layout (added 2026-08-09)

The codebase is organised **by domain, not by layer**. S1 creates two of the eight
domain folders and all three shared folders:

```
app/
├── Domains/
│   ├── Tenancy/        institutes, users, roles, branding, tenant scope
│   └── Content/        course → section → lesson (structure only in S1)
├── Integrations/       interfaces + FakeDrivers; real drivers arrive per slice
├── Support/
│   ├── Tenancy/        CurrentTenant, BelongsToTenant trait
│   ├── CircuitBreaker/ arrives in S2 with the first real driver
│   └── Cache/          arrives in S2
└── Http/Middleware/    ResolveTenant, EnsureRole
```

Each domain carries the same eight subfolders (`Models/`, `Services/`,
`Filament/`, `Livewire/`, `Views/`, `Jobs/`, `Policies/`, `Events/`). Domains talk
to each other through `Events/` only, never by calling into another domain's
services.

S1 must set this up correctly because every later slice inherits it: PSR-4
autoload paths, Filament's `discoverResources()` pointed at each domain, and one
`View::addNamespace()` call per domain registered in a loop at startup. Rationale
and the full folder tables are in `DESIGN.md` §11.

### 4.1 Request path

```
Request: physics-classes.lms.test
   │
   ▼ ResolveTenant middleware
   │   subdomain → Tenant        (not found → TenantNotFoundException)
   │   status = suspended?       → 403 + session flush
   │   app()->instance(CurrentTenant::class, $tenant)
   ▼
   BelongsToTenant global scope (on every tenant-owned model)
   │   reads:    WHERE tenant_id = <current>
   │   creating: tenant_id auto-filled
   │   no context bound → MissingTenantContextException (throw, never write NULL)
   ▼
   Filament panel (super | admin | teach)   |   Livewire student app
```

### 4.2 Two decisions that are cheap now and expensive later

**`users.tenant_id` is nullable; `NULL` means platform level (Super Admin).**
This avoids a separate super-admin table. Its direct consequence is that the
unique key is **`(tenant_id, email)`**, not `email`. One person can therefore
hold separate accounts at two institutes, which is normal in this market — a
teacher often works at more than one coaching centre.

**Queue jobs have no subdomain.** A worker runs outside HTTP, so
`CurrentTenant` is unbound. Every queued job must carry `tenant_id` explicitly
and re-bind it before touching a model. This is specified in S1, not S3,
because S3's entire ingestion pipeline is queue-based: if the pattern is not
set here, every ingestion job silently writes to `NULL` or to the wrong tenant,
and the damage surfaces months later. This is why the global scope **throws**
on a missing tenant rather than quietly no-opping. Silence is the leak
mechanism.

### 4.3 Schema (S1 tables)

```
tenants
  id · name · subdomain (unique) · status enum(active,suspended)
  logo_path · favicon_path · primary_color · accent_color
  created_at · updated_at

users
  id · tenant_id (nullable FK → tenants) · name · email · password
  email_verified_at · remember_token · created_at · updated_at
  unique (tenant_id, email)

courses
  id · tenant_id · title · slug · status enum(draft,published,archived)
  description · created_by (FK users) · created_at · updated_at
  unique (tenant_id, slug)

course_sections
  id · tenant_id · course_id · title · position · created_at · updated_at

lessons
  id · tenant_id · course_section_id · title · position
  type enum(video,pdf,text,embed,quiz,live_session)   -- type only in S1, no content
  created_at · updated_at
```

`roles` / `permissions` / pivots come from `spatie/laravel-permission`
migrations, used **unmodified**. They deliberately carry no `tenant_id`:
`users.tenant_id` already scopes every user to exactly one tenant, so scoping
the role pivot as well would be redundant and would imply a per-tenant-role
model that this design does not have. If a future requirement lets one user
hold different roles at two institutes, that is a schema change to `users`
first, and the pivot follows from it.

---

## 5. The `lesson_artifacts` contract (T1 — paper deliverable)

This is the keystone. Eleven features read it. Pinning it now, while changing it
costs nothing, is the point of doing it in S1. **S1 writes no migration for
these tables; S3 does.**

```
lesson_artifacts
  id
  tenant_id
  lesson_id
  version                int          -- increments on every re-ingest
  source_asset_version   string       -- id/hash of the source file this derives from
  status                 enum(pending, processing, ready, failed)
  transcript             text
  chunks                 jsonb   [{idx, start_s, end_s, text}]
  concepts               jsonb   [{name, confidence, chunk_idxs}]
  syllabus_map           jsonb   {board, subject, chapter, topic}
  difficulty             jsonb   {level, rationale}
  seed_questions         jsonb   [{type, stem, options, answer, explanation,
                                   concept, difficulty}]
  model_route            jsonb   {stage: model_id}     -- provenance
  token_cost             jsonb   {in, out, usd}
  failure_reason         text nullable
  created_at · updated_at
  unique (lesson_id, version)

lesson_artifact_chunks                 -- separated so pgvector indexes cleanly
  id · tenant_id · lesson_artifact_id
  chunk_idx · start_s · end_s · text
  embedding  vector(D)
```

`D` is fixed in S3 when the embedding model is chosen, and it is a one-way
door: a pgvector index requires a fixed dimension, so changing `D` later means
re-embedding every chunk in the system. S3 must therefore pick the embedding
model before the first lesson is ingested, not after.

### 5.1 Contract rules the eleven consumers depend on

1. Consumers always read the **highest `version` where `status = ready`**.
   A `processing` row is never visible to a read path.
2. Anything generated from an artifact (a quiz, a paper, a notes PDF) stores
   `lesson_artifact_id` — a specific version. Re-ingesting therefore never
   mutates an already-published quiz.
3. `source_asset_version` binds the artifact to the file it came from. When a
   lesson's asset changes, a new version is created and the previous ready
   version keeps serving until the new one reaches `ready`. This closes the
   ingest/edit race (CEO review D6 / task T9).
4. `token_cost` is written per artifact so per-tenant AI spend is measurable
   from data rather than estimated.

### 5.2 The eleven consumers

Question paper generation · doubt-chat RAG · revision notes · semantic search ·
weak-topic detection · WhatsApp nudges · auto chapters and timestamps · auto
lecture notes · daily practice problems · ask-at-timestamp · post-class auto
quiz.

**Verification for T1:** write each of the eleven read-queries against the
schema above on paper before S3 begins. Any consumer that cannot be expressed
means the contract is wrong, and it is still free to change.

---

## 6. Error handling

No catch-all handlers. Every failure has a named class.

| Trigger | Exception | Caught in | User sees |
|---|---|---|---|
| Unknown subdomain | `TenantNotFoundException` | `ResolveTenant` | 404: "Ye institute maujood nahi" |
| Tenant suspended | `TenantSuspendedException` | `ResolveTenant` | 403 + session flush; re-login blocked |
| Model touched with no tenant bound | `MissingTenantContextException` | `BelongsToTenant` scope | 500 + Sentry alert; must never occur in production |
| Queue job dispatched without `tenant_id` | `MissingTenantContextException` | job middleware | Job fails loudly to `failed_jobs`; visible in Horizon |
| Cross-tenant read | none — scope returns empty | — | Empty list, or 404 from `find` |
| Role assigned at or above own level | `PrivilegeEscalationException` | policy | 403 + audit row |
| Login rate limit exceeded | `ThrottleRequestsException` | rate limiter | "15 minute ke liye locked" |
| Branding upload of a disallowed type | `InvalidFileTypeException` | validator | Field error; nothing written |

Two deliberate choices:

**A cross-tenant read returns empty rather than 403.** A 403 confirms that the
row exists in some other tenant, which turns the endpoint into an enumeration
oracle. Empty is the safe answer.

**Queue jobs fail loudly.** See §4.2.

---

## 7. Stack reversals decided in this session

Both were decided with zero code written, so both cost nothing now. Both become
rewrites after S2.

### 7.1 Learner UI: Livewire 3 + Alpine + Tailwind (was Inertia 2 + React + TypeScript + shadcn)

Filament is itself Livewire. Using Livewire for the learner surface gives all
four UI surfaces one paradigm, one build pipeline, and one session model.

This closes two eng-review findings structurally instead of leaving them as
work items: **T17** (Filament and shadcn are two visual languages) and **E-T5**
(no shared component contract across four surfaces). It also removes the
"two frontend build pipelines, two session surfaces" finding.

The reason it matters more than a taste preference: the builder's background is
PHP/WordPress. The previous choice placed the least familiar technology
(React + TypeScript) on the most critical screens — the HLS player, the quiz
runner, and the doubt chat. Filament had removed the admin CRUD mountain; this
change removes the remaining unfamiliar mountain.

Accepted cost: the HLS player needs an Alpine wrapper around `hls.js` (it is a
JS component under either choice), and streaming doubt-chat responses use
Livewire 3 streaming or a small Alpine + fetch island on that one screen.
Accepted risk: a future React hire would not know Livewire.

Noted: the original `skills.md` specified "do NOT use Inertia.js". That
instinct is now honoured.

### 7.2 Live classes: ExternalLinkProvider + Zoom Video SDK first; BigBlueButton self-hosting deferred

Self-hosting a WebRTC media server is an ops specialty, not a solo PHP
developer's side task, and BigBlueButton fails at 6pm while a class is running.

Separately, the BBB server cost (~$48–72/mo on a dedicated box) is **entirely
absent** from `DESIGN.md` §7's stated fixed cost of "$45–70/month plus AI". The
real figure with BBB is roughly $95–140/month.

The `LiveClassProvider` interface keeps this a two-way door. Day one is
`ExternalLinkProvider` (the teacher pastes their own Zoom or Meet link, zero
setup) with Zoom Video SDK as a usage-based paid option. BBB gets self-hosted
when a customer's volume justifies the operational burden.

**Consequence to carry into S5:** attendance ships with two writers (manual
marking and async video coverage), not three, until a webhook-capable provider
is in place. The `attendance_records` table is designed for three writers from
the start; the live webhook writer simply arrives later.

---

## 8. Testing

### 8.1 The isolation suite (the CI gate)

```
for each tenant-owned model M:
  seed one row for tenant A, one for tenant B
  acting as tenant A:
    M::all()                      excludes B's row
    M::find(B.id)                 returns null
    M::where(id, B.id)->update()  affects 0 rows
    DELETE /api/M/{B.id}          returns 404
    Filament resource table       excludes B's row
    Filament edit page for B.id   returns 404
  acting with no tenant bound:
    M::create()                   throws MissingTenantContextException
```

**The suite is generated from a model registry, not hand-written per model.**
Hand-written tests mean that adding a model in S4 silently skips its coverage,
which turns the gate into decoration. The rule is: **a new tenant-owned model
without a registry entry fails CI.**

### 8.2 Other S1 tests

- Auth: throttle, lockout after 5 attempts in 1 minute, 15-minute lock, email
  verification, password reset, remember-me persistence.
- Role hierarchy: assignment at or above own level is blocked, in both
  directions of the hierarchy.
- Queue tenant binding: a job dispatched under tenant A writes rows carrying
  A's `tenant_id` when executed by a worker with no HTTP context.
- Branding: each tenant's logo, favicon, and colours render on all four
  surfaces; no bleed between tenants.

---

## 9. Demo #1 — 2026-08-22, 10 minutes

1. Open `physics-classes.lms.test` — its own logo and colours.
2. Open `chemistry-hub.lms.test` — different logo and colours, same codebase.
3. Admin login → create a course → a chapter → a topic.
4. Student login → the syllabus tree renders.
5. Show the CI run: isolation suite green.
6. Change one row's `tenant_id` by hand → suite goes red, build fails.

Step 6 is the point of the demo. It shows the gate works rather than that it
was written down.

**Set expectations before the demo:** no video, no AI, no fees on 2026-08-22.
VALIDATION.md's rule applies — show what exists, including unfinished work.
Saying "this is the foundation demo" up front stops the demo friend from
judging a product that does not exist yet.

---

## 10. Definition of done for S1

- [ ] Repository initialised; CI runs the isolation suite on every push
- [ ] Two tenants resolve by subdomain with distinct branding on all four surfaces
- [ ] Isolation suite green, and demonstrably red when tenant scoping is broken
- [ ] Every tenant-owned model is in the registry; a model without an entry fails CI
- [ ] All five roles exist; privilege escalation is blocked and audited
- [ ] Auth flows complete: register, verify, login, reset, remember-me, lockout
- [ ] Course → Section → Lesson CRUD works in the `/teach` panel
- [ ] Security baseline in place: CSRF on every form, XSS sanitisation on
      user-supplied text, explicit `$fillable` on every model, file-type
      allowlist on branding uploads — each with a test
- [ ] `lesson_artifacts` contract written, with all eleven consumer read-queries expressed against it
- [ ] Demo #1 delivered on 2026-08-22

---

## 11. Documents this spec makes stale

These are not S1 work items, but they should be reconciled before S2 begins,
because three documents currently disagree with each other:

| Doc | What is now wrong |
|---|---|
| `DESIGN.md` §3 | Learner UI row (Inertia + React) and live-class row (BBB default) — superseded by §7 here |
| `DESIGN.md` §3.3 | Says all ingestion routes through the Batch API and hardcodes `× 0.5` in the per-lesson cost. CEO decision **D7** reversed this to hybrid routing; the real-time path is ≈₹9/lesson, not ₹4.5 |
| `DESIGN.md` §7 | Fixed monthly cost omits the live-class server entirely |
| `DESIGN.md` §8 | Still carries the "get one institute to say yes to a free pilot" assignment, which the owner declined; VALIDATION.md's demo-friend assignment supersedes it |
| `DESIGN.md` §9 | Lists the AI provider as an open item; it is a logged, settled decision |
| `DESIGN.md` §5 vs `FEATURES.md` Part 5 | Two different definitions of Institute Zero (attendance is required in one, absent in the other) |
| `DESIGN.md` (4 roles) vs `FEATURES.md` §0 (5 roles) | The Parent portal is P1 in one and has no UI surface assigned in the other |
| `FEATURES.md` "Status of the wider project" | Says "Tech stack: Not yet chosen"; it has been chosen and revised twice since |
| `FEATURES.md` D8 vs Q1/Q2 | Bandwidth quota and overage billing are P1, but the plan tiers that define the quotas are P2 |
| Status headers | `DESIGN.md` = DRAFT, `FEATURES.md` = Locked, `VALIDATION.md` = APPROVED |
