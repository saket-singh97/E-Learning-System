<?php

namespace App\Http\Middleware;

use App\Domains\Tenancy\Models\ImpersonationAudit;
use App\Domains\Tenancy\Services\ImpersonationService;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appended to the global `web` group AND each panel's own ->middleware()
 * stack (not authMiddleware — this has to run before EnsureRole's own check
 * sees the impersonated user's role, not after). A single ResolveTenant-
 * style registration on the web group alone is not enough here: unlike
 * ResolveTenant, Filament panel page loads don't list the bare 'web' string
 * in their own middleware array (they list each concrete class), so a
 * global-group-only append would apply on Livewire's own /livewire/update
 * round-trips but never on a panel's actual page load — the one moment
 * EnsureRole needs to already see the swapped identity.
 *
 * Session-based, matching this app's existing session-cookie auth model
 * (design doc §2.4 decision 4) rather than a second auth mechanism:
 * Auth::setUser() only overrides identity resolution for this one request,
 * it never touches the persisted session's own real login state — so if
 * this middleware is ever skipped on some future route, the safe fallback
 * is the real actor staying logged in as themselves, not silently as
 * whoever they were impersonating.
 */
class ResolveImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        $targetId = $request->session()->get('impersonating_user_id');

        if ($targetId === null || ! Auth::check()) {
            return $next($request);
        }

        if ($this->hasExpired($request)) {
            app(ImpersonationService::class)->stop();

            return $next($request);
        }

        $target = User::find($targetId);

        if ($target === null) {
            return $next($request);
        }

        $currentTenant = app(CurrentTenant::class);

        // Same "does the acting identity's own tenant match the resolved
        // host" guard S9 B0 built for every other surface (SESSION_DOMAIN is
        // a shared parent domain) — an impersonation session started for a
        // tenant A user must not silently also apply on tenant B's own
        // subdomain just because the cookie is still valid there.
        //
        // Requires a *bound* tenant, not just a non-matching one — found by
        // the S9 B14 review pass: the bare Super-panel host never binds
        // CurrentTenant at all (no ResolveTenant there by design), so the
        // original `isBound() && mismatch` form never skipped the swap on
        // that host regardless of the target's own tenant. The swapped-in
        // target (never a super_admin — ImpersonationService::start()
        // refuses that) then failed EnsureRole's own super_admin check
        // there, locking the real actor out of the Super panel entirely
        // while impersonating anyone, until they navigated back to the
        // tenant panel they started from to use the banner's "Stop
        // impersonating" instead. Impersonation only ever makes sense
        // *within* a resolved tenant's own surfaces to begin with — the
        // bare host is never one of them.
        if (! $currentTenant->isBound() || $target->tenant_id !== $currentTenant->id()) {
            return $next($request);
        }

        Auth::setUser($target);

        return $next($request);
    }

    /**
     * docs/FEATURES.md's A6 row ("60-min auto-expiry") — found unimplemented
     * anywhere by the S9 B14 review pass; see ImpersonationService's own
     * docblock on MAX_DURATION_MINUTES for why it's fixed here rather than
     * flagged. Missing audit row (session references one that's gone) is
     * treated the same as expired, not the same as "not impersonating" —
     * fails closed rather than silently keeping a session alive with
     * nothing left to check its age against.
     */
    protected function hasExpired(Request $request): bool
    {
        $auditId = $request->session()->get('impersonation_audit_id');

        if ($auditId === null) {
            return true;
        }

        $audit = ImpersonationAudit::find($auditId);

        if ($audit === null) {
            return true;
        }

        return $audit->started_at->lt(now()->subMinutes(ImpersonationService::MAX_DURATION_MINUTES));
    }
}
