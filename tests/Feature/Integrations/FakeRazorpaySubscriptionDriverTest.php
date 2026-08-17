<?php

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\FakeRazorpaySubscriptionDriver;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $this->plan = Plan::create([
        'code' => 'growth-fake-test',
        'name' => 'Growth',
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
    ]);

    $this->subscription = TenantSubscription::create([
        'plan_id' => $this->plan->id,
        'status' => 'created',
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    $this->driver = new FakeRazorpaySubscriptionDriver;
});

it('creates a subscription with a created status and a short_url', function () {
    $result = $this->driver->createSubscription($this->subscription);

    expect($result['status'])->toBe('created')
        ->and($result['subscription_id'])->toStartWith('sub_fake_')
        ->and($result['short_url'])->toContain($result['subscription_id']);
});

it('never repeats a subscription_id across calls, even for the same tenant', function () {
    $first = $this->driver->createSubscription($this->subscription);
    $second = $this->driver->createSubscription($this->subscription);

    expect($first['subscription_id'])->not->toBe($second['subscription_id']);
});

it('reports an active status after a plan change', function () {
    $this->subscription->update(['razorpay_subscription_id' => 'sub_fake_abc']);

    $result = $this->driver->changePlan($this->subscription->fresh(), $this->plan);

    expect($result)->toBe(['subscription_id' => 'sub_fake_abc', 'status' => 'active']);
});

it('verifies a payload signed with the fake secret and rejects one that is not', function () {
    $payload = json_encode(['event' => 'subscription.charged']);
    $validSignature = hash_hmac('sha256', $payload, FakeRazorpaySubscriptionDriver::FAKE_SECRET);

    expect($this->driver->verifyWebhookSignature($payload, $validSignature))->toBeTrue()
        ->and($this->driver->verifyWebhookSignature($payload, 'not-a-real-signature'))->toBeFalse();
});
