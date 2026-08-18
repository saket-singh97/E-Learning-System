<?php

namespace App\Integrations\Drivers;

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Integrations\SubscriptionProvider;
use App\Support\Resilience\CircuitBreaker;

/**
 * S12 B11 — decorates the bound SubscriptionProvider (Razorpay's
 * Subscriptions API), same shape as `CircuitBreakerGuardedPaymentDriver`
 * sitting beside it. `verifyWebhookSignature()` left unwrapped for the
 * identical reason — a pure local check, never an outbound call.
 */
class CircuitBreakerGuardedSubscriptionDriver implements SubscriptionProvider
{
    private const BREAKER_KEY = 'subscription-provider';

    public function __construct(
        private readonly SubscriptionProvider $inner,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function createSubscription(TenantSubscription $subscription): array
    {
        return $this->breaker->call(self::BREAKER_KEY, fn (): array => $this->inner->createSubscription($subscription));
    }

    public function changePlan(TenantSubscription $subscription, Plan $newPlan): array
    {
        return $this->breaker->call(self::BREAKER_KEY, fn (): array => $this->inner->changePlan($subscription, $newPlan));
    }

    public function cancelSubscription(TenantSubscription $subscription): void
    {
        $this->breaker->call(self::BREAKER_KEY, function () use ($subscription): void {
            $this->inner->cancelSubscription($subscription);
        });
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        return $this->inner->verifyWebhookSignature($payload, $signature);
    }
}
