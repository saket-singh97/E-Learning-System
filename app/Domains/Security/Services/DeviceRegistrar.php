<?php

namespace App\Domains\Security\Services;

use App\Domains\Security\Models\DeviceSession;
use App\Exceptions\DeviceLimitExceededException;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The single place a login turns into a `device_sessions` row. Explicit
 * tenant_id derivation from `$user->tenant_id` throughout, never ambient
 * `CurrentTenant` — same reasoning `SeatQuota`/`StorageQuota` (S11) already
 * establish for a cross-cutting service. Load-bearing here specifically:
 * Google OAuth's callback route (S9 B2) is host-agnostic, so `CurrentTenant`
 * is unbound by the time `Illuminate\Auth\Events\Login` fires there — an
 * ambient-scope read/write would silently miss the tenant filter (read) or
 * throw `MissingTenantContextException` (write, via `BelongsToTenant`'s
 * `creating()` hook), on exactly the login path most likely to be a real
 * end-user's personal device.
 */
class DeviceRegistrar
{
    public function __construct(
        private readonly DeviceFingerprintService $fingerprints,
    ) {}

    /**
     * Called from `RegisterDeviceOnLogin` on every successful login,
     * regardless of which surface authenticated the user (password, TOTP,
     * Google OAuth, any of the three Filament panels, either portal) —
     * design doc §4 decision 2's own "enforced at login time" posture,
     * and Laravel's `Login` event is the one point every one of those
     * surfaces already funnels through, so no controller needed touching.
     *
     * An already-known device (same fingerprint, not revoked) just
     * refreshes its own `last_seen_at`/ip/user-agent — no limit check, a
     * returning device was already counted. Only a genuinely new
     * fingerprint can trip the limit.
     */
    public function registerLogin(User $user, Request $request): DeviceSession
    {
        $fingerprint = $this->fingerprints->fingerprintFor($request);

        $existing = DeviceSession::withoutGlobalScope('tenant')
            ->where('user_id', $user->id)
            ->where('device_fingerprint', $fingerprint)
            ->whereNull('revoked_at')
            ->first();

        if ($existing !== null) {
            $existing->update([
                'last_seen_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return $existing;
        }

        $this->assertWithinDeviceLimit($user);

        return DeviceSession::withoutEvents(fn () => DeviceSession::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'device_fingerprint' => $fingerprint,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_seen_at' => now(),
        ]));
    }

    protected function assertWithinDeviceLimit(User $user): void
    {
        $limit = config('security.device_limit');

        $activeCount = DeviceSession::withoutGlobalScope('tenant')
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->count();

        if ($activeCount >= $limit) {
            throw DeviceLimitExceededException::forUser($user->id, $limit);
        }
    }
}
