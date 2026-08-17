<?php

namespace App\Integrations\Drivers;

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Integrations\SubscriptionProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real driver against Razorpay's Subscriptions API — unit-testable without
 * a live account via Http::fake(), same posture RazorpayProvider (Orders
 * API) already established. Not the bound SubscriptionProvider yet:
 * AppServiceProvider still binds FakeRazorpaySubscriptionDriver, since no
 * real RAZORPAY_KEY_ID/SECRET or a Plan's real razorpay_plan_id exist.
 */
class RazorpaySubscriptionProvider implements SubscriptionProvider
{
    public function createSubscription(TenantSubscription $subscription): array
    {
        [$keyId, $keySecret] = $this->credentials();
        $plan = $subscription->plan()->firstOrFail();

        $response = Http::withBasicAuth($keyId, $keySecret)
            ->post('https://api.razorpay.com/v1/subscriptions', [
                'plan_id' => $this->razorpayPlanId($plan),
                // Razorpay requires a finite total_count even for an
                // effectively open-ended subscription — 100 cycles at a
                // monthly plan is ~8 years, renewed by a fresh
                // subscription well before it would ever lapse from this.
                'total_count' => 100,
                'customer_notify' => 1,
                'notes' => [
                    'tenant_id' => (string) $subscription->tenant_id,
                    'tenant_subscription_id' => (string) $subscription->id,
                ],
            ])
            ->throw()
            ->json();

        return [
            'subscription_id' => $response['id'],
            'status' => $response['status'],
            'short_url' => $response['short_url'] ?? '',
        ];
    }

    public function changePlan(TenantSubscription $subscription, Plan $newPlan): array
    {
        [$keyId, $keySecret] = $this->credentials();

        $response = Http::withBasicAuth($keyId, $keySecret)
            ->post("https://api.razorpay.com/v1/subscriptions/{$subscription->razorpay_subscription_id}", [
                'plan_id' => $this->razorpayPlanId($newPlan),
                // Immediate, not 'cycle_end' — this app's own proration
                // ledger (design doc §2.4 decision 9) already accounts for
                // the switch happening now, not at the next renewal.
                'schedule_change_at' => 'now',
            ])
            ->throw()
            ->json();

        return [
            'subscription_id' => $response['id'],
            'status' => $response['status'],
        ];
    }

    public function cancelSubscription(TenantSubscription $subscription): void
    {
        [$keyId, $keySecret] = $this->credentials();

        Http::withBasicAuth($keyId, $keySecret)
            ->post("https://api.razorpay.com/v1/subscriptions/{$subscription->razorpay_subscription_id}/cancel")
            ->throw();
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $webhookSecret = config('services.razorpay.subscription_webhook_secret');

        if (blank($webhookSecret)) {
            throw new RuntimeException(
                'Razorpay subscription webhook secret is not configured (RAZORPAY_SUBSCRIPTION_WEBHOOK_SECRET) — '.
                'this is exactly why it is not yet the bound SubscriptionProvider.'
            );
        }

        return hash_equals(hash_hmac('sha256', $payload, $webhookSecret), $signature);
    }

    protected function razorpayPlanId(Plan $plan): string
    {
        return $plan->razorpay_plan_id ?? throw new RuntimeException(
            "Plan [{$plan->code}] has no razorpay_plan_id set — push it to Razorpay and record the id on the plan before subscribing a tenant to it."
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function credentials(): array
    {
        $keyId = config('services.razorpay.key_id');
        $keySecret = config('services.razorpay.key_secret');

        if (blank($keyId) || blank($keySecret)) {
            throw new RuntimeException(
                'Razorpay credentials are not configured (RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET) — '.
                'this is exactly why it is not yet the bound SubscriptionProvider.'
            );
        }

        return [$keyId, $keySecret];
    }
}
