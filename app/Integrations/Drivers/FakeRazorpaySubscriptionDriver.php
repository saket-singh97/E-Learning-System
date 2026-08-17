<?php

namespace App\Integrations\Drivers;

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Integrations\SubscriptionProvider;

/**
 * Hash-seeded fake, same "traceable but never repeating across calls"
 * posture FakeRazorpayDriver already established for Orders — a real
 * Razorpay subscription_id is unique per API call even for the same
 * tenant.
 */
class FakeRazorpaySubscriptionDriver implements SubscriptionProvider
{
    public const FAKE_SECRET = 'fake-razorpay-subscription-webhook-secret';

    public function createSubscription(TenantSubscription $subscription): array
    {
        $id = 'sub_fake_'.dechex(crc32("tenant#{$subscription->tenant_id}#".uniqid('', true)));

        return [
            'subscription_id' => $id,
            'status' => 'created',
            'short_url' => "https://rzp.io/i/{$id}",
        ];
    }

    public function changePlan(TenantSubscription $subscription, Plan $newPlan): array
    {
        return [
            'subscription_id' => $subscription->razorpay_subscription_id ?? 'sub_fake_'.dechex(crc32((string) $subscription->id)),
            'status' => 'active',
        ];
    }

    public function cancelSubscription(TenantSubscription $subscription): void
    {
        // No vendor state to mutate — the caller (PlanChangeService, B13)
        // owns updating TenantSubscription.status itself, same division
        // of responsibility FakeRazorpayDriver leaves to PaymentReconciler.
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        return hash_equals(hash_hmac('sha256', $payload, self::FAKE_SECRET), $signature);
    }
}
