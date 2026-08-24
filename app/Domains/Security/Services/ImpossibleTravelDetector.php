<?php

namespace App\Domains\Security\Services;

use App\Integrations\GeoIpResolver;
use App\Support\Geo\HaversineDistance;
use Illuminate\Support\Carbon;

/**
 * Design doc §4 decision 3 — a plain velocity check (distance / time >
 * plausible max speed), not a geolocation vendor's own "impossible
 * travel" product. Re-resolves both IPs on every call rather than
 * storing lat/lon on `login_history` itself — that table only has
 * `country`/`city` strings (B0's own schema), and this runs once per
 * login, not on a hot path, so the extra resolve is cheap.
 */
class ImpossibleTravelDetector
{
    public function __construct(
        private readonly GeoIpResolver $geoIp,
    ) {}

    /**
     * Never flags when either IP can't be resolved (loopback, private
     * ranges, most local-dev traffic) — no signal means no verdict, not a
     * false positive.
     */
    public function isImpossible(string $fromIp, Carbon $fromAt, string $toIp, Carbon $toAt): bool
    {
        $from = $this->geoIp->resolve($fromIp);
        $to = $this->geoIp->resolve($toIp);

        if ($from['lat'] === null || $from['lon'] === null || $to['lat'] === null || $to['lon'] === null) {
            return false;
        }

        $distanceKm = HaversineDistance::kilometers($from['lat'], $from['lon'], $to['lat'], $to['lon']);

        // abs() explicitly, not trusted to already be unsigned — the
        // plain default diffInSeconds() call turned out to still return a
        // *negative* value here when $toAt is after $fromAt (found by a
        // failing "should NOT flag" test: a negative diff floors straight
        // to the 0.001-hour minimum below, inflating a perfectly plausible
        // 50 km/h trip into an apparent multi-million-km/h one). Floored,
        // not zero-guarded with a return — two logins at (near) the exact
        // same instant from two real, distinct locations is exactly the
        // "impossible" case, not a division-by-zero to dodge.
        $hours = max(0.001, abs($toAt->diffInSeconds($fromAt)) / 3600);

        return ($distanceKm / $hours) > config('security.impossible_travel_max_kmh');
    }
}
