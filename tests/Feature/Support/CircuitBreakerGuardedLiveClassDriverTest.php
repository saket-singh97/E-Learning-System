<?php

use App\Domains\Batches\Models\BatchSession;
use App\Exceptions\CircuitOpenException;
use App\Integrations\Drivers\CircuitBreakerGuardedLiveClassDriver;
use App\Integrations\LiveClassProvider;
use App\Models\User;
use App\Support\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Redis;

function failingLiveClassProvider(): LiveClassProvider
{
    return new class implements LiveClassProvider
    {
        public function createRoom(BatchSession $session): string
        {
            throw new RuntimeException('provider down');
        }

        public function joinUrl(BatchSession $session, User $viewer): string
        {
            throw new RuntimeException('provider down');
        }

        public function markEnded(BatchSession $session): void
        {
            throw new RuntimeException('provider down');
        }
    };
}

beforeEach(function () {
    config(['resilience.circuit_breaker_failure_threshold' => 3]);
    config(['resilience.circuit_breaker_cooldown_seconds' => 60]);
});

afterEach(function () {
    Redis::del('circuit_breaker:live-class-provider:failures');
    Redis::del('circuit_breaker:live-class-provider:open');
});

it('trips the shared live-class-provider breaker across createRoom/joinUrl/markEnded alike', function () {
    $driver = new CircuitBreakerGuardedLiveClassDriver(failingLiveClassProvider(), app(CircuitBreaker::class));

    foreach ([
        fn () => $driver->createRoom(new BatchSession),
        fn () => $driver->joinUrl(new BatchSession, new User),
        fn () => $driver->markEnded(new BatchSession),
    ] as $call) {
        try {
            $call();
        } catch (RuntimeException) {
        }
    }

    expect(fn () => $driver->createRoom(new BatchSession))->toThrow(CircuitOpenException::class);
});
