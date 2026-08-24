<?php

namespace App\Support\Geo;

/**
 * Extracted from ImpossibleTravelDetector (S12 B2) once a second consumer
 * (S16 B2's GeofenceCheckInService) needed the exact same formula —
 * Support/ is this app's home for cross-domain shared code (CLAUDE.md),
 * so a plain distance calculation with no domain state lives here rather
 * than one domain service calling into another's.
 */
class HaversineDistance
{
    private const EARTH_RADIUS_KM = 6371.0;

    public static function kilometers(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return self::EARTH_RADIUS_KM * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    public static function meters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        return self::kilometers($lat1, $lon1, $lat2, $lon2) * 1000;
    }
}
