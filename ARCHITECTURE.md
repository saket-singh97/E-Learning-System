# ARCHITECTURE

System shape — what exists and how data moves. Conventions, gotchas, and
naming rules live in `CLAUDE.md`; the reasoning behind each choice lives in
`DECISIONS.md`; request-by-request call paths live in `FLOW.md`. This file
is the map, not the rulebook — read it to reason about impact before
writing a line, not to learn how to write the line.

## Surfaces

| Surface | Route base | Who |
|---|---|---|
| `/super` | platform host, no tenant | super admin |
| `/admin` | `{subdomain}.lms.test` | institute admin |
| `/teach` | `{subdomain}.lms.test` | instructor |
| student portal | `{subdomain}.lms.test` | student/parent — Livewire, not a Filament panel |

## Domains (`app/Domains/{Domain}/`)

| Domain | Owns | Status |
|---|---|---|
| `Tenancy` | institutes, users, roles, branding, tenant scope | done (S1) |
| `Content` | course → section → lesson, video storage/streaming, watch progress | done (S1-S2) |
| `AI` | `lesson_artifacts` pipeline (transcribe/chunk/concepts/embed), doubt chat | done (S3) |
| `Batches` | batches, sessions, enrolment, waitlist, attendance | done (S4) |

Each domain carries the same subfolders:
`Models/ Services/ Filament/ Livewire/ Views/ Jobs/ Policies/ Events/`.
Cross-domain contact only via `Events/` — see `FLOW.md` §5.

## Shared / cross-domain code (outside `Domains/`)

- `app/Integrations/` — driver interfaces + Fake drivers (`AiProvider` /
  `FakeAiDriver`, video storage driver). Real drivers (an AI vendor, Bunny
  CDN) arrive per slice; none are wired up yet — deliberate, not a gap.
- `app/Support/Tenancy/` — `CurrentTenant`, `BelongsToTenant` trait,
  `TenantModelRegistry`.
- `app/Support/Security/` — `SanitizesHtml`.
- `app/Http/Middleware/` — `ResolveTenant`, `EnsureRole`.
- `app/Http/Controllers/{Domain}/` — plain HTTP endpoints a domain needs
  (video streaming, watch heartbeat), routed from `routes/{domain}.php`.
- `app/Livewire/` — full Blade-embedded components not tied to one
  Filament resource (`StudentPortal`, `LessonPlayer`).
- `app/Providers/Filament/` — the three panel providers; they span
  domains, so they stay outside `Domains/`.

## Biggest cross-cutting data flow

Lecture upload (Content domain) → `LessonVideoReady` event → AI domain's
4-job ingestion chain → `lesson_artifacts` / `lesson_artifact_chunks`
(pgvector) → doubt chat reads from there, grounded per-lecture only.
Full detail: `FLOW.md` §3.

## Multi-tenancy

Every tenant-owned table carries `tenant_id`; the `BelongsToTenant` trait
auto-scopes queries to the current institute. Isolation coverage is
registry-driven, not hand-written per model — see `CLAUDE.md` "the tenant
model registry rule." Five review rounds across S1-S3 have gone looking for
cross-tenant leaks; two were found and fixed (both in the first S1 round),
none since.

## Roadmap position

Eight sub-projects, S1-S8, one per fortnight, each ending in a demo.
S1 (Foundation), S2 (Content + Video), S3 (AI Spine), S4 (Batches +
Enrolment) complete. Full table with demo dates: `docs/PROJECT-STATUS.md`.

---

Update this file when a domain is added, a surface changes, or new shared
infra lands under `Integrations/`/`Support/`. Routine CRUD inside an
existing domain doesn't belong here — that's `FLOW.md` or the slice's plan
doc.
