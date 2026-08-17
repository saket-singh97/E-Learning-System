<?php

namespace App\Domains\Security\Listeners;

use App\Domains\Security\Models\LoginHistory;
use App\Domains\Security\Services\DeviceFingerprintService;
use App\Domains\Security\Services\ImpossibleTravelDetector;
use App\Integrations\GeoIpResolver;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * S12 B3 (FEATURES.md B11) — same `Login` event chokepoint
 * `RegisterDeviceOnLogin` (B1) already established as the one point every
 * login surface funnels through. A sibling listener, not a merge into
 * B1's own class — same "one listener per concern" shape `AttendanceRecorded`
 * already has two independent listeners for (`SendAbsenceAlert`,
 * `SendLowAttendanceAlert`).
 *
 * **Successful logins only — deliberately, not a stale oversight.**
 * `Illuminate\Auth\Events\Login` only fires on success by construction;
 * recording *failed* attempts too would need hooking
 * `TenantAuthenticator::validate()`'s own failure branch, which (unlike
 * this event) genuinely isn't reachable from Google OAuth's own flow at
 * all — a different, real chokepoint problem this block deliberately
 * doesn't try to solve. Impossible-travel detection only makes sense
 * comparing successful sessions anyway (a rejected password attempt was
 * never really "there"), so the smallest version that delivers this
 * block's actual value doesn't need it.
 */
class RecordLoginHistory
{
    public function __construct(
        private readonly GeoIpResolver $geoIp,
        private readonly ImpossibleTravelDetector $detector,
        private readonly DeviceFingerprintService $fingerprints,
    ) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User || $event->user->tenant_id === null) {
            return;
        }

        $request = request();
        $ip = (string) ($request->ip() ?? '');

        $previous = LoginHistory::withoutGlobalScope('tenant')
            ->where('user_id', $event->user->id)
            ->where('successful', true)
            ->latest('id')
            ->first();

        $flagged = $previous !== null
            && $previous->ip_address !== null
            && $this->detector->isImpossible($previous->ip_address, $previous->created_at, $ip, now());

        $location = $this->geoIp->resolve($ip);

        LoginHistory::withoutEvents(fn () => LoginHistory::create([
            'tenant_id' => $event->user->tenant_id,
            'user_id' => $event->user->id,
            'ip_address' => $ip,
            'country' => $location['country'],
            'city' => $location['city'],
            'device_fingerprint' => $this->fingerprints->fingerprintFor($request),
            'successful' => true,
            'flagged_impossible_travel' => $flagged,
        ]));
    }
}
