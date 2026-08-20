<?php

namespace App\Http\Middleware;

use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\TenantNotFoundException;
use App\Exceptions\TenantSuspendedException;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * `optional` mode binds the tenant when the host maps to one and stays
     * silent when it doesn't, instead of 404ing. It exists for host-agnostic
     * routes that still need tenant context — above all Livewire's own
     * /livewire/update endpoint, which Livewire registers with no domain
     * constraint and only the `web` group. Without tenant context there,
     * every Livewire action (all three panel logins, the student portal)
     * hits an unbound CurrentTenant, and BelongsToTenant's global scope
     * silently stops filtering during model hydration. Platform-level hosts
     * (the super panel on the bare domain) legitimately have no tenant, so
     * this mode must not treat "no tenant" as an error.
     *
     * `:optional` is also prepended to the app's GLOBAL middleware stack
     * (bootstrap/app.php withMiddleware — not the `web` group append below
     * it) for a second reason: Filament always slots its own Authenticate
     * middleware at a fixed early position in a panel's pipeline regardless
     * of where ResolveTenant sits in ->middleware() — confirmed via
     * `route:list -v`, actual order is SetUpPanel, session middleware,
     * Authenticate, *then* everything the panel provider declared,
     * ResolveTenant included. An unauthenticated visit to /admin or /teach
     * therefore hit Authenticate first, which tries to build the login
     * redirect URL — a `Route::domain('{subdomain}...')` route — before
     * URL::defaults(['subdomain' => ...]) had ever been set, throwing
     * UrlGenerationException instead of showing the login page. Global
     * middleware runs before every route group's own stack, panels
     * included, so the prepended pass sets the URL default in time; the
     * panel-level pass (in ->middleware()) still runs afterwards and does
     * the real CurrentTenant binding + suspension check once a session
     * exists, same as the existing `web`-group append already did for
     * Livewire — this is a third, deliberate call site, not a redundancy
     * to clean up.
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $host = $request->getHost();
        $subdomain = explode('.', $host)[0];

        // Subdomain match stays the fast, common-case path (S9 design doc
        // §3.2) — a custom domain's own first label (e.g. "learn" in
        // learn.theirschool.com) essentially never collides with a real
        // tenant subdomain, but even if it did, the subdomain match still
        // wins, same ordering as this data flow was designed with.
        $tenant = Tenant::where('subdomain', $subdomain)->first()
            ?? Tenant::where('custom_domain', $host)->whereNotNull('custom_domain_verified_at')->first();

        if (! $tenant) {
            if ($mode === 'optional') {
                return $next($request);
            }

            throw TenantNotFoundException::forSubdomain($subdomain);
        }

        if ($tenant->isSuspended()) {
            // This class also runs prepended to the app's global middleware
            // stack (bootstrap/app.php), before StartSession — no session
            // exists yet to flush there. Throwing anyway would win the race
            // against the later, session-aware pass (web-group append /
            // panel ->middleware()) and skip the flush entirely, since the
            // exception aborts the pipeline before that later pass ever
            // runs. So the session-less pass only sets the URL default
            // (still needed so a same-tenant redirect doesn't itself crash)
            // and defers the actual 403 + flush to the later pass, which
            // sees the same suspended tenant again once a session exists.
            if (! $request->hasSession()) {
                URL::defaults(['subdomain' => $tenant->subdomain]);

                return $next($request);
            }

            $request->session()->flush();

            throw TenantSuspendedException::forSubdomain($subdomain);
        }

        app(CurrentTenant::class)->set($tenant);

        // Always the tenant's own *.lms.test subdomain, even when this
        // request itself arrived on a custom domain — every route this app
        // generates internally (route('student.dashboard', ['subdomain' =>
        // ...]) and friends) is still Route::domain('{subdomain}.lms.test'),
        // unchanged by this block; custom domains only affect inbound
        // resolution, not how this app builds its own links.
        URL::defaults(['subdomain' => $tenant->subdomain]);

        return $next($request);
    }
}
