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
