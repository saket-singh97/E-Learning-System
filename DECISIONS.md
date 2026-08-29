# DECISIONS

Append-only log of meaningful technical decisions and the reasoning behind
them. Code shows *what* changed; this shows *why* — so the argument doesn't
get re-litigated six months from now. Newest entry on top.

## Format

```
### YYYY-MM-DD — decision
Chose X over Y.
Why: the reasoning, constraint, or tradeoff that forced it.
Reversible: yes/no — what undoing it would cost.
```

## Log

### 2026-08-29 — Stateless-readiness audit (S21 B13, V9): clean, no fixes needed
Why: horizontal scaling (a second app server) is a one-way door the moment
some request path writes a file the *next* request — on a different
server — needs to read back. Audited every `app/Domains/**` and
`app/Http/**` file for a raw filesystem write bypassing the `Storage`
facade (`file_put_contents`, `fopen()` against a real path, `mkdir`,
`copy`/`rename`/`unlink`, a direct `Illuminate\Filesystem\Filesystem` or
`League\Flysystem` use) — anchored grep, not a bare substring match, same
discipline `TenantModelRegistry`'s own sweep already uses. Found zero: every
one of the app's 97 `Storage::disk(...)` call sites (53 files — every
export/import/PDF/certificate/branding/lesson operation) already routes
through the facade; the only raw `fopen()` calls anywhere are
`fopen('php://temp', 'r+')` — an in-memory stream every CSV
builder in this app already uses to assemble content before handing it to
`Storage::disk('local')->put(...)`, never a real file. No code changed by
this block — inventing a fix for a problem that doesn't exist would be
worse than doing nothing. Recorded as a load-bearing constraint in
`ARCHITECTURE.md` for anyone adding a future feature, not just this
finding.
Reversible: n/a — this is a verified state, not a code change. Constraint
in `ARCHITECTURE.md` reflects it.

### 2026-08-10 — Embedding model: `text-embedding-3-small`, 1536 dimensions
Why: the vector column's dimension is a one-way door once the first lesson
is ingested, so it couldn't stay open through S3. Delegated to the builder
rather than left as a blocking question. Recorded in the S3 design doc §2.4.
Reversible: no — not without re-embedding every ingested lecture.

### 2026-08-09 — Domain-driven folder structure, not layered
Why: S1 Block 2. Each domain (`Tenancy`, `Content`, `Batches`, …) carries
the same `Models/Services/Filament/Livewire/Views/Jobs/Policies/Events`
subfolders; domains talk to each other only through `Events/`, never by
calling another domain's service directly. Keeps a domain's blast radius
contained as more sub-projects land.
Reversible: yes in theory, high cost — touches every domain.

### 2026-08-09 — Tenant context resolved twice: strict + lenient
Why: Livewire registers its own `/livewire/update` endpoint with no domain
constraint, joining only the `web` group. Every Livewire action in the app
(panel logins, student portal, `LessonPlayer`) posts there. Without a
lenient resolution pass appended to `web`, `CurrentTenant` is unbound on
those requests and `BelongsToTenant`'s global scope silently stops
filtering during hydration. Full detail: `CLAUDE.md` "Tenant context is
resolved twice, on purpose."
Reversible: yes, but removing the lenient pass breaks every panel login and
the student portal — found the hard way in the S2 post-build review.

### 2026-08-08 — Livewire 3 for the learner UI, not Inertia + React
Why: settled in the office-hours design session, folded back into
`DESIGN.md` v2.
Reversible: yes, high cost — full frontend rewrite.

### 2026-08-08 — Live classes start on `ExternalLinkProvider`, not self-hosted BigBlueButton
Why: same design session — avoids standing up and operating BBB
infrastructure before there's a paying institute to justify it.
Reversible: yes — swappable driver, matches the S2/S3 fake-driver pattern.

### 2026-08-08 — Built from scratch; competitor codebases studied for features only
Why: Frappe LMS (69 doctypes studied as the most complete production data
model available) is AGPL-3.0. Owner decision: features studied, no code
copied. `DESIGN.md` §0.
Reversible: n/a — licensing constraint, not a technical preference.

---

Add a new entry whenever a "why this over that" question gets settled —
don't wait to be asked, and don't wait for the decision to be challenged
before writing down the reasoning.
