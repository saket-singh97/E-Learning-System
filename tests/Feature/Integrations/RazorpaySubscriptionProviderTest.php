<?php

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\RazorpaySubscriptionProvider;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $this->plan = Plan::create([
        'code' => 'growth-provider-test',
        'name' => 'Growth',
        'seat_limit' => 15,
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
        'razorpay_plan_id' => 'plan_live_growth',
    ]);

    $this->subscription = TenantSubscription::create([
        'plan_id' => $this->plan->id,
        'status' => 'created',
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    $this->driver = new RazorpaySubscriptionProvider;
});

it('refuses to create a subscription when Razorpay credentials are not configured', function () {
    config(['services.razorpay.key_id' => null, 'services.razorpay.key_secret' => null]);

    expect(fn () => $this->driver->createSubscription($this->subscription))->toThrow(RuntimeException::class);
});

it('refuses to create a subscription when the plan has no razorpay_plan_id', function () {
    config(['services.razorpay.key_id' => 'test-key', 'services.razorpay.key_secret' => 'test-secret']);
    $this->plan->update(['razorpay_plan_id' => null]);

    expect(fn () => $this->driver->createSubscription($this->subscription))->toThrow(RuntimeException::class);
});

it('creates a real subscription via the Razorpay API, plan_id and notes correct', function () {
    config(['services.razorpay.key_id' => 'test-key', 'services.razorpay.key_secret' => 'test-secret']);

    Http::fake([
        'api.razorpay.com/v1/subscriptions' => Http::response([
            'id' => 'sub_live_abc123',
            'status' => 'created',
            'short_url' => 'https://rzp.io/i/abc123',
        ], 200),
    ]);

    $result = $this->driver->createSubscription($this->subscription);

    expect($result)->toBe([
        'subscription_id' => 'sub_live_abc123',
        'status' => 'created',
        'short_url' => 'https://rzp.io/i/abc123',
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.razorpay.com/v1/subscriptions'
            && $request['plan_id'] === 'plan_live_growth'
            && $request['total_count'] === 100
            && $request['notes']['tenant_subscription_id'] === (string) $this->subscription->id;
    });
});

it('changes a subscriptions plan immediately, not at cycle end', function () {
    config(['services.razorpay.key_id' => 'test-key', 'services.razorpay.key_secret' => 'test-secret']);
    $this->subscription->update(['razorpay_subscription_id' => 'sub_live_abc123']);

    $newPlan = Plan::create([
        'code' => 'enterprise-provider-test',
        'name' => 'Enterprise',
        'storage_limit_gb' => 500,
        'bandwidth_limit_gb_per_day' => 200,
        'ai_spend_cap_usd_per_day' => 50,
        'price_inr_per_month' => 19999,
        'razorpay_plan_id' => 'plan_live_enterprise',
    ]);

    Http::fake([
        'api.razorpay.com/v1/subscriptions/sub_live_abc123' => Http::response([
            'id' => 'sub_live_abc123',
            'status' => 'active',
        ], 200),
    ]);

    $result = $this->driver->changePlan($this->subscription->fresh(), $newPlan);

    expect($result)->toBe(['subscription_id' => 'sub_live_abc123', 'status' => 'active']);

    Http::assertSent(fn ($request) => $request['plan_id'] === 'plan_live_enterprise'
        && $request['schedule_change_at'] === 'now');
});

it('cancels a subscription by posting to its cancel endpoint', function () {
    config(['services.razorpay.key_id' => 'test-key', 'services.razorpay.key_secret' => 'test-secret']);
    $this->subscription->update(['razorpay_subscription_id' => 'sub_live_abc123']);

    Http::fake([
        'api.razorpay.com/v1/subscriptions/sub_live_abc123/cancel' => Http::response([], 200),
    ]);

    $this->driver->cancelSubscription($this->subscription->fresh());

    Http::assertSent(fn ($request) => $request->url() === 'https://api.razorpay.com/v1/subscriptions/sub_live_abc123/cancel');
});

it('refuses to verify a webhook when the subscription webhook secret is not configured', function () {
    config(['services.razorpay.subscription_webhook_secret' => null]);

    expect(fn () => $this->driver->verifyWebhookSignature('{}', 'sig'))->toThrow(RuntimeException::class);
});

it('verifies a subscription webhook signature against its own configured secret', function () {
    config(['services.razorpay.subscription_webhook_secret' => 'test-subscription-webhook-secret']);

    $payload = json_encode(['event' => 'subscription.charged']);
    $validSignature = hash_hmac('sha256', $payload, 'test-subscription-webhook-secret');

    expect($this->driver->verifyWebhookSignature($payload, $validSignature))->toBeTrue()
        ->and($this->driver->verifyWebhookSignature($payload, 'wrong'))->toBeFalse();
});
