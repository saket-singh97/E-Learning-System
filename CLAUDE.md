# CLAUDE.md

Guidance for working in this codebase. Full design: `docs/superpowers/specs/2026-08-08-s1-foundation-design.md`. Full plan and block-by-block build log: `docs/superpowers/specs/2026-08-09-s1-implementation-plan.md`.

**Read `HANDOVER.md` first, every session** — live snapshot of what's done, in progress, broken, and to avoid. `DECISIONS.md` (why things are built this way), `FLOW.md` (how execution travels across domains), and `ARCHITECTURE.md` (system map) sit beside it at the repo root; `docs/bugs/` and `docs/features/` hold per-item trace files. Update the relevant one whenever you finish a block, hit a bug, or settle a "why X over Y" question — don't wait to be asked.

## Domain layout

Code is organised **by domain, not by layer**. Each domain under `app/Domains/{Domain}/` carries the same subfolders:

```
app/Domains/{Domain}/
  Models/
  Services/
  Filament/
  Livewire/
  Views/
  Jobs/
  Policies/
  Events/
```

Current domains: `Tenancy` (institutes, users, roles, branding, tenant scope) and `Content` (course → section → lesson). More domains land per S-phase — see the plan.

**Domains talk to each other through `Events/` only, never by calling into another domain's services directly.**

Shared, cross-domain code lives outside `Domains/`:

```
app/
  Integrations/       interfaces + FakeDrivers; real drivers arrive per slice
  Support/
    Tenancy/           CurrentTenant, BelongsToTenant trait, TenantModelRegistry
    Security/          SanitizesHtml trait
  Http/
    Middleware/         ResolveTenant, EnsureRole
    Controllers/{Domain}/ plain (non-Filament) HTTP endpoints a domain needs — e.g. Content/StreamLessonController, Content/WatchHeartbeatController. Routed from routes/{domain}.php, included from routes/web.php inside the Route::domain('{subdomain}.lms.test') group.
  Livewire/            full Blade-embedded components not tied to one Filament resource (e.g. StudentPortal, LessonPlayer) — embedded via `<livewire:x />` in a domain's page view, not routed directly.
  Providers/Filament/   panel providers (AdminPanelProvider, TeachPanelProvider, SuperPanelProvider) — these span domains, so they stay outside Domains/
```

Route param gotcha: on a `Route::domain('{subdomain}.lms.test')` route, don't type-hint a plain scalar route parameter (e.g. `string $lesson`) in a controller method or closure — Laravel's dependency-injection splicing can hand it the domain's `{subdomain}` value instead once a class-typehinted parameter (`Request`, a service) sits alongside it, because parameters get spliced back in by leftover position, not by name. Pull it via `$request->route('paramName')` instead.

## Tenant context is resolved twice, on purpose

`ResolveTenant` runs in two places and both are load-bearing:

- **Strict** (`resolve.tenant`) on the tenant route groups and Filament panels — an unknown subdomain is a `TenantNotFoundException`, i.e. a 404.
- **Lenient** (`ResolveTenant::class.':optional'`, appended to the `web` group in `bootstrap/app.php`) — binds when the host maps to a tenant, stays silent when it doesn't. This exists because **Livewire registers its own `/livewire/update` endpoint with no domain constraint and only the `web` group**. Every Livewire action in the app posts there — all three panel logins, the student portal, `LessonPlayer`. Without it, `CurrentTenant` is unbound on those requests, tenant-scoped `Auth::attempt` throws, and `BelongsToTenant`'s global scope silently stops filtering during model hydration. The bare platform host (super panel) has no tenant by design, which is why this mode must not 404.

Do **not** "optimise" the double resolution away with an early return when `CurrentTenant` is already bound: it is a singleton that tests bind directly in `beforeEach`, so an early return lets a cross-tenant HTTP test keep the wrong tenant bound and silently pass a test whose whole job is catching leaks.

`Livewire::test()` bypasses this route entirely (it runs in-process), so component-level tests can never catch a regression here. `tests/Feature/Tenancy/LivewireTenantContextTest.php` guards the route wiring instead.

## Lesson access rules live in one class

`app/Domains/Content/Services/LessonAccess.php` is the only place that answers "may this viewer see this lesson, and can it play?". The stream route, the player page, and the watch heartbeat all call it. They were once separate inline checks, and the player page shipped with no check at all as a result — so add new visibility rules there, never inline in a controller.

## Naming conventions

- Domain folder names: PascalCase, singular concept (`Tenancy`, `Content`), lowercased for the view namespace (`tenancy::`, `content::`).
- Filament resources: `Domain/Filament/Resources/{PluralModelName}/{ModelName}Resource.php`, with `Pages/` and `RelationManagers/` subfolders beside it. A child resource reached only through a relation manager (e.g. `CourseSectionResource`, `LessonResource`) is hidden from the main nav rather than removed — it still needs its own `EditRecord` page to support the relation manager's edit action.
- Panel providers: `{Panel}PanelProvider.php` in `app/Providers/Filament/`.
- Exceptions: `app/Exceptions/{Name}Exception.php`, registered in `bootstrap/app.php`'s `withExceptions()`, one `$exceptions->render()` per class — no catch-all handler.

## The `View::addNamespace()` deviation

Domain views live at `app/Domains/{Domain}/Views/`, not `resources/views/`. `AppServiceProvider::registerDomainViewNamespaces()` loops `glob(app_path('Domains/*'), GLOB_ONLYDIR)` at boot and calls `View::addNamespace($domain, "{$domainPath}/Views")` for each one. Reference a domain view as `view('tenancy::ping')`, not `view('ping')`. Adding a new domain folder is enough — no manual namespace registration needed.

## The tenant model registry rule

**Every model using the `BelongsToTenant` trait must be listed in `App\Support\Tenancy\TenantModelRegistry::MODELS`.**

`tests/Isolation/CrossTenantTest.php` generates the cross-tenant isolation suite from that array — cross-tenant read, update, and the no-tenant-context guard, once per registered model. A model missing from the registry fails a guard test in the same file, so isolation coverage cannot silently regress as domains grow. The suite is generated, not hand-written per model — do not add per-model isolation tests by hand; add the model (and its `seedAttributes()` / `updateAttributes()` entries) to the registry instead.

## Test command

```
composer test
```

Runs `php artisan config:clear` then `php artisan test` (Pest). Full setup, including the second `lms_test` database the suite needs: `SETUP.md`.

## Conventions enforced by tests, not just style

- No `$guarded = []` on any domain model — every model declares explicit `$fillable`.
- No `DB::raw`, `DB::statement`, or `whereRaw` inside `app/Domains/` — enforced by `tests/Feature/Security/SecurityBaselineTest.php`.
- User-supplied rich-text fields are sanitised on save via the `SanitizesHtml` trait (`app/Support/Security/SanitizesHtml.php`), not sanitised ad hoc in controllers.
