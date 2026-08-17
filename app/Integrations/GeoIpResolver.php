<?php

namespace App\Integrations;

interface GeoIpResolver
{
    /**
     * lat/lon null when the IP can't be resolved (loopback/private
     * ranges, an unrecognised address) — callers must treat that as "no
     * signal," never as a real coordinate.
     *
     * @return array{country: ?string, city: ?string, lat: ?float, lon: ?float}
     */
    public function resolve(string $ip): array;
}
