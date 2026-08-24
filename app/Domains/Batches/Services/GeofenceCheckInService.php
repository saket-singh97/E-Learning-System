<?php

namespace App\Domains\Batches\Services;

use App\Domains\Batches\Models\BatchGeofence;
use App\Support\Geo\HaversineDistance;

/**
 * S16 B2 (H11 pt.1) — reuses ImpossibleTravelDetector's own haversine
 * formula (S12 B2, now shared via App\Support\Geo\HaversineDistance), not
 * a second hand-rolled copy (design doc §3.2/B2 note).
 */
class GeofenceCheckInService
{
    public function isWithinRadius(BatchGeofence $geofence, float $latitude, float $longitude): bool
    {
        $distanceMeters = HaversineDistance::meters(
            $geofence->latitude,
            $geofence->longitude,
            $latitude,
            $longitude,
        );

        return $distanceMeters <= $geofence->radius_meters;
    }
}
