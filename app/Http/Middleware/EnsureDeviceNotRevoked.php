<?php

namespace App\Http\Middleware;

use App\Domains\Security\Models\DeviceSession;
use App\Domains\Security\Services\DeviceFingerprintService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * S12 B2 (FEATURES.md B10) — design doc §4 decision 2: a revoked device is
 * checked lazily on its *next* request, not enforced instantly the moment
 * another device clicks "log out" — the same lazy-check tradeoff this app
 * already accepts for `Tenant::isSuspended()` in `ResolveTenant`. Mirrors
 * that middleware's own dual-registration shape exactly (`CLAUDE.md`'s own
 * documented reason): appended to the global `web` group (covers Livewire's
 * `/livewire/update` endpoint and both portals) *and* listed explicitly in
 * each tenant-scoped panel's own middleware array (a global-append-only
 * registration would never fire on an actual Filament page load, only on
 * its Livewire AJAX round-trips).
 *
 * A no-op for a guest request (no fingerprint to check against) and for a
 * platform-level user (`tenant_id === null`, i.e. `super_admin`) — B1's own
 * exemption from device tracking applies here too, for the identical
 * reason: no `device_sessions` row can exist for that user to revoke.
 */
class EnsureDeviceNotRevoked
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null || $user->tenant_id === null) {
            return $next($request);
        }

        $fingerprint = app(DeviceFingerprintService::class)->fingerprintFor($request);

        // Latest row for this fingerprint, not "any row ever revoked" —
        // DeviceRegistrar::registerLogin() inserts a fresh row (rather than
        // reviving the old one) when a revoked device logs back in, so an
        // unscoped exists() check would keep matching that stale revoked
        // row forever and permanently lock the fingerprint out even after a
        // genuinely valid relogin (found in S12 B14's own review pass).
        $latest = DeviceSession::withoutGlobalScope('tenant')
            ->where('user_id', $user->id)
            ->where('device_fingerprint', $fingerprint)
            ->latest('id')
            ->first();

        if ($latest !== null && $latest->isRevoked()) {
            // Single 'web' guard app-wide (config/auth.php) — every real
            // caller here (portals, all three Filament panels, the JSON
            // endpoint) already authenticates through it, same as
            // RegisterDeviceOnLogin's own guard handling.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(401, 'This device was logged out remotely.');
        }

        return $next($request);
    }
}
