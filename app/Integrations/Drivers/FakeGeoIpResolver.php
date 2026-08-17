<?php

namespace App\Integrations\Drivers;

use App\Integrations\GeoIpResolver;

/**
 * S12 B3 — the only driver for `GeoIpResolver`, at least for now. Design
 * doc §4 decision 3 named a local MaxMind GeoLite2 database as the real
 * driver's own data source, matching `RealDnsResolver`'s "no vendor
 * account needed" posture — but MaxMind now gates even the free GeoLite2
 * tier behind account registration to download the `.mmdb` file itself,
 * so unlike `RealDnsResolver` (a plain PHP builtin, bindable immediately),
 * there is no real database file to build or test a real driver against
 * in this environment. A stub `RealGeoIpResolver` with zero test coverage
 * and zero way to verify it actually works would be worse than not
 * building it — deferred, matching the design doc's own already-drawn
 * V6/V7 boundary ("provisioning a real credential/file is a deployment
 * concern, not application code"). This Fake stays the bound driver until
 * a real `.mmdb` file is provisioned.
 */
class FakeGeoIpResolver implements GeoIpResolver
{
    /**
     * A handful of real-world city coordinates, keyed by an arbitrary IP
     * string a test can pass directly to get a genuinely distant
     * location pair — not a real MaxMind lookup. `127.0.0.1` deliberately
     * resolves to nulls, matching real GeoIP behaviour for loopback/
     * private ranges (nothing to geolocate).
     *
     * @var array<string, array{country: ?string, city: ?string, lat: ?float, lon: ?float}>
     */
    private const KNOWN = [
        '203.0.113.10' => ['country' => 'IN', 'city' => 'Mumbai', 'lat' => 19.0760, 'lon' => 72.8777],
        '203.0.113.20' => ['country' => 'US', 'city' => 'New York', 'lat' => 40.7128, 'lon' => -74.0060],
        '203.0.113.30' => ['country' => 'IN', 'city' => 'Delhi', 'lat' => 28.7041, 'lon' => 77.1025],
        '203.0.113.40' => ['country' => 'IN', 'city' => 'Pune', 'lat' => 18.5204, 'lon' => 73.8567],
        '127.0.0.1' => ['country' => null, 'city' => null, 'lat' => null, 'lon' => null],
    ];

    public function resolve(string $ip): array
    {
        if (isset(self::KNOWN[$ip])) {
            return self::KNOWN[$ip];
        }

        // Deterministic-but-arbitrary coordinates for any other IP, so a
        // test using an unlisted address still gets a stable, repeatable
        // result rather than being forced to pick from the known list.
        $seed = crc32($ip);

        return [
            'country' => 'IN',
            'city' => 'Unknown',
            'lat' => -90 + (($seed % 18000) / 100),
            'lon' => -180 + (($seed % 36000) / 100),
        ];
    }
}
