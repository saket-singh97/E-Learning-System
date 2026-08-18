<?php

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Exceptions\CircuitOpenException;
use App\Integrations\Drivers\CircuitBreakerGuardedSubscriptionDriver;
use App\Integrations\SubscriptionProvider;
use App\Support\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Redis;

function failingSubscriptionProvider(): SubscriptionProvider
{
    return new class implements SubscriptionProvider
    {
        public bool $webhookChecked = false;

        public function createSubscription(TenantSubscription $subscription): array
        {
            throw new RuntimeException('provider down');
        }

        public function changePlan(TenantSubscription $subscription, Plan $newPlan): array
        {
            throw new RuntimeException('provider down');
        }

        public function cancelSubscription(TenantSubscription $subscription): void
        {
            throw new RuntimeException('provider down');
        }

        public function verifyWebhookSignature(string $payload, string $signature): bool
        {
            $this->webhookChecked = true;

            return false;
        }
    };
}

beforeEach(function () {
    config(['resilience.circuit_breaker_failure_threshold' => 3]);
    config(['resilience.circuit_breaker_cooldown_seconds' => 60]);
});

afterEach(function () {
    Redis::del('circuit_breaker:subscription-provider:failures');
    Redis::del('circuit_breaker:subscription-provider:open');
});

it('trips the shared subscription-provider breaker across create/changePlan/cancel alike', function () {
    $driver = new CircuitBreakerGuardedSubscriptionDriver(failingSubscriptionProvider(), app(CircuitBreaker::class));

    foreach ([
        fn () => $driver->createSubscription(new TenantSubscription),
        fn () => $driver->changePlan(new TenantSubscription, new Plan),
        fn () => $driver->cancelSubscription(new TenantSubscription),
    ] as $call) {
        try {
            $call();
        } catch (RuntimeException) {
        }
    }

    expect(fn () => $driver->createSubscription(new TenantSubscription))->toThrow(CircuitOpenException::class);
});

it('never wraps verifyWebhookSignature', function () {
    $inner = failingSubscriptionProvider();
    $driver = new CircuitBreakerGuardedSubscriptionDriver($inner, app(CircuitBreaker::class));

    expect($driver->verifyWebhookSignature('payload', 'sig'))->toBeFalse();
    expect($inner->webhookChecked)->toBeTrue();
    expect(app(CircuitBreaker::class)->isOpen('subscription-provider'))->toBeFalse();
});
