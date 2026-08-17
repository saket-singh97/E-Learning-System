<?php

namespace App\Integrations;

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;

/**
 * Razorpay's Subscriptions API — a recurring mandate + billing cycle, not
 * the one-shot Orders API PaymentProvider already wraps (design doc §2.4
 * decision 6). Deliberately a separate interface: forcing one interface to
 * cover both would leak Orders-specific assumptions (a single total_amount)
 * into a shape that actually needs plan_id + period dates instead.
 */
interface SubscriptionProvider
{
    /**
     * Starts a new subscription against $subscription->plan's
     * razorpay_plan_id. The customer completes the mandate out of band
     * (Razorpay's own hosted checkout page, returned as short_url) — this
     * app never touches card/UPI details itself.
     *
     * @return array{subscription_id: string, status: string, short_url: string}
     */
    public function createSubscription(TenantSubscription $subscription): array;

    /**
     * Moves an already-active subscription onto $newPlan's own
     * razorpay_plan_id. Proration itself is computed in this app's own
     * ledger (design doc §2.4 decision 9), not delegated to Razorpay's own
     * plan-change proration — this only tells Razorpay which plan the
     * subscription bills against going forward.
     *
     * @return array{subscription_id: string, status: string}
     */
    public function changePlan(TenantSubscription $subscription, Plan $newPlan): array;

    public function cancelSubscription(TenantSubscription $subscription): void;

    /**
     * Verifies a subscription-webhook payload was genuinely signed by
     * Razorpay, using this endpoint's own webhook secret — a separate
     * secret from PaymentProvider's Orders-API webhook (config('services.
     * razorpay.subscription_webhook_secret'), not .webhook_secret).
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool;
}
