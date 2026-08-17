<?php

use App\Domains\Security\Services\ImpossibleTravelDetector;

it('flags a physically impossible speed between two known distant cities', function () {
    $detector = app(ImpossibleTravelDetector::class);

    // Mumbai -> New York (~12,500 km) in 10 minutes.
    expect($detector->isImpossible('203.0.113.10', now(), '203.0.113.20', now()->addMinutes(10)))->toBeTrue();
});

it('does not flag a plausible speed between two nearby cities', function () {
    $detector = app(ImpossibleTravelDetector::class);

    // Mumbai -> Pune (~150 km) over 3 hours.
    expect($detector->isImpossible('203.0.113.10', now(), '203.0.113.40', now()->addHours(3)))->toBeFalse();
});

it('never flags when either ip cannot be resolved', function () {
    $detector = app(ImpossibleTravelDetector::class);

    expect($detector->isImpossible('127.0.0.1', now(), '203.0.113.20', now()->addMinutes(1)))->toBeFalse()
        ->and($detector->isImpossible('203.0.113.10', now(), '127.0.0.1', now()->addMinutes(1)))->toBeFalse();
});

it('respects the configured max plausible speed', function () {
    config(['security.impossible_travel_max_kmh' => 100000]);
    $detector = app(ImpossibleTravelDetector::class);

    expect($detector->isImpossible('203.0.113.10', now(), '203.0.113.20', now()->addMinutes(10)))->toBeFalse();
});

it('flags two logins at the same instant from two distinct real locations', function () {
    $detector = app(ImpossibleTravelDetector::class);
    $now = now();

    expect($detector->isImpossible('203.0.113.10', $now, '203.0.113.20', $now))->toBeTrue();
});

it('does not flag two logins at the same instant from the same location', function () {
    $detector = app(ImpossibleTravelDetector::class);
    $now = now();

    expect($detector->isImpossible('203.0.113.10', $now, '203.0.113.10', $now))->toBeFalse();
});
