<?php

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\FakeRazorpaySubscriptionDriver;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $plan = Plan::create([
        'code' => 'growth-webhook-test',
        'name' => 'Growth',
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
    ]);

    $this->subscription = TenantSubscription::create([
        'plan_id' => $plan->id,
        'status' => TenantSubscription::STATUS_TRIALING,
        'razorpay_subscription_id' => 'sub_http_test',
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);
});

it('activates a subscription end-to-end over a real HTTP POST', function () {
    $payload = json_encode([
        'event' => 'subscription.charged',
        'payload' => [
            'subscription' => ['entity' => ['id' => 'sub_http_test']],
            'payment' => ['entity' => ['id' => 'pay_http_test', 'amount' => 799900]],
        ],
    ]);
    $signature = hash_hmac('sha256', $payload, FakeRazorpaySubscriptionDriver::FAKE_SECRET);

    $this->call('POST', '/api/webhooks/razorpay-subscriptions', [], [], [], [
        'HTTP_X-Razorpay-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    expect($this->subscription->fresh()->status)->toBe(TenantSubscription::STATUS_ACTIVE);
});

it('rejects a webhook with a bad signature', function () {
    $payload = json_encode(['event' => 'subscription.charged', 'payload' => []]);

    $this->call('POST', '/api/webhooks/razorpay-subscriptions', [], [], [], [
        'HTTP_X-Razorpay-Signature' => 'forged',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertStatus(400);
});
