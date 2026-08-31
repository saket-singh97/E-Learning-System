<?php

use App\Exceptions\CircuitOpenException;
use App\Integrations\CommsProvider;
use App\Integrations\Drivers\CircuitBreakerGuardedCommsDriver;
use App\Support\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Redis;

function failingCommsProvider(): CommsProvider
{
    return new class implements CommsProvider
    {
        public function sendTemplate(string $toE164, string $template, array $params): array
        {
            throw new RuntimeException('provider down');
        }

        public function sendSessionMessage(string $toE164, string $text): array
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
    Redis::del('circuit_breaker:comms-provider:failures');
    Redis::del('circuit_breaker:comms-provider:open');
});

it('trips the comms-provider breaker after repeated sendTemplate failures', function () {
    $driver = new CircuitBreakerGuardedCommsDriver(failingCommsProvider(), app(CircuitBreaker::class));

    foreach (range(1, 3) as $i) {
        try {
            $driver->sendTemplate('+911234567890', 'reminder', []);
        } catch (RuntimeException) {
        }
    }

    expect(fn () => $driver->sendTemplate('+911234567890', 'reminder', []))->toThrow(CircuitOpenException::class);
});
