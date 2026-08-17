<?php

use App\Integrations\Drivers\FakeGeoIpResolver;

it('resolves known test ips to real city coordinates', function () {
    $result = (new FakeGeoIpResolver)->resolve('203.0.113.10');

    expect($result['country'])->toBe('IN')
        ->and($result['city'])->toBe('Mumbai')
        ->and($result['lat'])->toBe(19.0760)
        ->and($result['lon'])->toBe(72.8777);
});

it('resolves loopback to nulls, matching real GeoIP behaviour for unresolvable addresses', function () {
    $result = (new FakeGeoIpResolver)->resolve('127.0.0.1');

    expect($result['country'])->toBeNull()
        ->and($result['city'])->toBeNull()
        ->and($result['lat'])->toBeNull()
        ->and($result['lon'])->toBeNull();
});

it('produces a stable, repeatable result for an unlisted ip', function () {
    $resolver = new FakeGeoIpResolver;

    expect($resolver->resolve('198.51.100.7'))->toBe($resolver->resolve('198.51.100.7'));
});

it('produces different results for different unlisted ips', function () {
    $resolver = new FakeGeoIpResolver;

    expect($resolver->resolve('198.51.100.7'))->not->toBe($resolver->resolve('198.51.100.8'));
});
