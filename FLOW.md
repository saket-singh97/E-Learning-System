# FLOW

How execution actually travels between files, functions, and domains —
what calls what, in what order. Bugs live in the gaps between files; this
is what makes those gaps visible. Update the relevant section whenever you
touch a path described here.

## 1. Tenant resolution (every request)

`ResolveTenant` middleware runs twice — both load-bearing, see `CLAUDE.md`
"Tenant context is resolved twice, on purpose" for the full why:

- **Strict** (`resolve.tenant`) on tenant route groups + all three Filament
  panels — unknown subdomain → `TenantNotFoundException` → 404.
- **Lenient** (`ResolveTenant::class.':optional'`), appended to the `web`
  group in `bootstrap/app.php` — binds `CurrentTenant` when the host maps
  to a tenant, stays silent when it doesn't. Exists because Livewire's
  `/livewire/update` endpoint has no domain constraint.

`Livewire::test()` bypasses this route entirely (runs in-process) — so
component-level tests can never catch a regression here.
`tests/Feature/Tenancy/LivewireTenantContextTest.php` guards the route
wiring instead. Do not "optimise" the double resolution away with an early
return when `CurrentTenant` is already bound.

## 2. Lesson access (stream route, player page, watch heartbeat)

Single source of truth: `app/Domains/Content/Services/LessonAccess.php`.
All three call sites — the stream route, the player page, the watch
heartbeat — call it. Add new visibility rules there, never inline in a
controller (the player page shipped with no check at all once, when this
was three separate inline checks).

## 3. Lecture ingestion pipeline (S3, Content → AI)

`LessonVideoReady` event (fired from the Content domain) is the first real
cross-domain event chain in the codebase — a 4-job chain:
transcribe → chunk → extract concepts → embed, landing in
`lesson_artifacts` / `lesson_artifact_chunks` (pgvector, HNSW index).

Retry re-runs the whole chain from the start. The chunking job clears the
lecture's existing chunks before rebuilding (fixed in the post-S3 review —
previously only ever *added* rows, so a retry after a later-stage failure
left duplicate chunks and duplicate-passage doubt-chat answers).

Doubt chat reads only from a lecture's own `lesson_artifact_chunks` —
answers are grounded per-lecture, never cross-lecture.

## 4. Enrolment (S4, complete)

`BatchEnrolmentService::enrol()` — two self-service modes (self-enrol
direct, approval-required); `addManually()` is the separate staff-only path.
`enrol()` refuses a batch that isn't published (with a published course)
before touching either mode — enforced in the mutator itself, not just
`canEnrol()`'s advisory check, because `BatchCatalog::join()` is a
directly-callable Livewire method with no role gate on `/batches`.
`lockForUpdate()` (inside `DB::transaction`) guards every path that
consumes or frees a seat: `enrolDirect()`, `approve()`, and `cancel()`'s
active branch (which also calls `promoteNextWaitlisted()`).
`EnrolmentsRelationManager` (under `BatchResource`) is the UI entry point;
every action on it — manual-add, approve, reject, cancel — goes through
`EnrolmentApprovalPolicy::canDecide()` first, gating to an instructor or
institute admin in the batch's own tenant.

`cancel()` → sets `cancelled` → (if the enrolment was `active`)
`promoteNextWaitlisted()` (oldest `waitlisted` row by `created_at`, `id` as
a tiebreak since the column is whole-second precision in Postgres) →
`Events/StudentEnrolled` / `Events/SeatReleased`. `ExpirePendingEnrolments`
(scheduled daily, `bootstrap/app.php`) sweeps `pending_approval` rows past
`expires_at` across every tenant (runs with no `CurrentTenant` bound), and
re-checks each row's status under `lockForUpdate()` immediately before
writing `expired` — otherwise a same-moment `approve()` on that row would
get silently overwritten.

## 5. Domain → domain communication

Never a direct service call across a `Domains/{X}` boundary. Always
`Events/`. To see what a domain publishes, grep its `Events/` folder; to
see what it consumes, grep for a `Listener` importing another domain's
event class.

---

New cross-domain flow, or any non-obvious call path (something that isn't
findable by just reading the file you're in) → add a section here before
moving on, not after.
