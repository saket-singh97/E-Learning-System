<?php

namespace App\Http\Middleware;

use App\Domains\Security\Models\AccountSharingFlag;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * S12 B4 (FEATURES.md B12) — design doc §4 decision 4's own "forces
 * re-auth on its next request (session invalidated, not the account
 * locked — a false positive shouldn't lock a real user out)". Same
 * dual-registration shape `EnsureDeviceNotRevoked` (B2) already
 * established: appended to the global `web` group (covers Livewire's
 * `/livewire/update`, both portals — a shared-account signal applies to
 * a student/parent every bit as much as staff, unlike B2's own
 * Admin/Teach-only scope) *and* listed explicitly on Admin/Teach's own
 * panel middleware arrays (a real Filament page load doesn't route
 * through the literal `web` group string at all).
 *
 * `reviewed_at` is repurposed here as "already enforced," not only "an
 * admin looked at it" — the middleware sets it itself the moment the
 * forced logout fires, so the *same* flag doesn't force a logout loop on
 * every subsequent request. The user simply logs back in normally
 * afterward; a later sweep run creates a fresh flag if the pattern
 * continues. A no-op for a guest request or a platform-level user
 * (`tenant_id === null`) — same exemption B1/B2 already established, for
 * the identical reason: no `device_sessions`/flag row can exist for that
 * user in the first place.
 */
class EnsureAccountNotFlaggedForSharing
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null || $user->tenant_id === null) {
            return $next($request);
        }

        $flag = AccountSharingFlag::withoutGlobalScope('tenant')
            ->where('user_id', $user->id)
            ->whereNull('reviewed_at')
            ->first();

        if ($flag !== null) {
            $flag->update(['reviewed_at' => now()]);

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(401, 'Unusual account activity detected — please log in again.');
        }

        return $next($request);
    }
}
