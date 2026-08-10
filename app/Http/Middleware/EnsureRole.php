<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Panel gate: Route::middleware('role:instructor,institute_admin').
     * Any one of the listed roles is sufficient.
     *
     * Role alone is not enough: Spatie roles carry no tenant_id (by design,
     * see S1 design doc §4.3), so an instructor's role check passes on ANY
     * host. Because SESSION_DOMAIN is a shared parent domain, a session
     * cookie from tenant A's subdomain is also valid on tenant B's — a
     * logged-in user who merely opens tenant B's /teach or /admin URL would
     * otherwise pass this middleware and land inside CurrentTenant's
     * (host-derived) scope with a role that has nothing to do with that
     * tenant. Cross-checking the user's own tenant_id against the resolved
     * CurrentTenant closes that gap. Skipped when CurrentTenant is unbound
     * (the super panel has no ResolveTenant middleware at all).
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasAnyRole($roles)) {
            abort(403);
        }

        $currentTenant = app(CurrentTenant::class);

        if ($currentTenant->isBound() && $user->tenant_id !== $currentTenant->id()) {
            abort(403);
        }

        return $next($request);
    }
}
