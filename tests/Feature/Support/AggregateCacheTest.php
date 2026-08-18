<?php

use App\Support\Caching\AggregateCache;
use Illuminate\Support\Facades\Cache;

it('remembers and forgets a gradebook score, keyed per tenant/student/course', function () {
    $cache = app(AggregateCache::class);
    $calls = 0;
    $compute = function () use (&$calls): array {
        $calls++;

        return ['weighted_percent' => 42.0];
    };

    $first = $cache->rememberGradebookScore(1, 2, 3, $compute);
    $second = $cache->rememberGradebookScore(1, 2, 3, $compute);
    expect($first)->toBe(['weighted_percent' => 42.0])
        ->and($second)->toBe($first)
        ->and($calls)->toBe(1);

    $cache->forgetGradebookScore(1, 2, 3);
    $cache->rememberGradebookScore(1, 2, 3, $compute);
    expect($calls)->toBe(2);
});

it('never mixes up two different courses for the same student', function () {
    $cache = app(AggregateCache::class);

    $cache->rememberGradebookScore(1, 2, 3, fn () => ['weighted_percent' => 10.0]);
    $forCourseFour = $cache->rememberGradebookScore(1, 2, 4, fn () => ['weighted_percent' => 20.0]);

    expect($forCourseFour)->toBe(['weighted_percent' => 20.0]);
});

it('remembers and forgets student metrics, keyed per tenant/student', function () {
    $cache = app(AggregateCache::class);
    $calls = 0;
    $compute = function () use (&$calls): array {
        $calls++;

        return ['attendance_percent' => 80.0];
    };

    $cache->rememberStudentMetrics(5, 6, $compute);
    $cache->rememberStudentMetrics(5, 6, $compute);
    expect($calls)->toBe(1);

    $cache->forgetStudentMetrics(5, 6);
    $cache->rememberStudentMetrics(5, 6, $compute);
    expect($calls)->toBe(2);
});

it('remembers and forgets the institute overview, keyed per tenant only', function () {
    $cache = app(AggregateCache::class);
    $calls = 0;
    $compute = function () use (&$calls): array {
        $calls++;

        return ['active_enrolments' => 10];
    };

    $cache->rememberInstituteOverview(7, $compute);
    $cache->rememberInstituteOverview(7, $compute);
    expect($calls)->toBe(1);

    $cache->forgetInstituteOverview(7);
    $cache->rememberInstituteOverview(7, $compute);
    expect($calls)->toBe(2);
});

it('passes the configured TTL, in seconds, through to the cache store', function () {
    config(['caching.aggregate_ttl_minutes' => 5]);
    Cache::spy();

    app(AggregateCache::class)->rememberInstituteOverview(9, fn () => ['x' => 1]);

    Cache::shouldHaveReceived('remember')
        ->once()
        ->withArgs(fn (string $key, int $ttl, callable $callback): bool => $key === 'aggregate:9:institute-overview' && $ttl === 300);
});
