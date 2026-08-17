<?php

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Billing\Services\PlanChangeService;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\TenantAlreadySubscribedException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->plan = Plan::create([
        'code' => 'growth-subscribe-test',
        'name' => 'Growth',
        'seat_limit' => 15,
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
    ]);
});

it('subscribes a tenant with no existing plan', function () {
    $subscription = app(PlanChangeService::class)->subscribe($this->tenant, $this->plan);

    expect($subscription->tenant_id)->toBe($this->tenant->id)
        ->and($subscription->plan_id)->toBe($this->plan->id)
        ->and($subscription->status)->toBe(TenantSubscription::STATUS_TRIALING)
        ->and($subscription->razorpay_subscription_id)->not->toBeNull()
        ->and($subscription->razorpay_subscription_id)->toStartWith('sub_fake_');

    expect($this->tenant->fresh()->plan_id)->toBe($this->plan->id);
});

it('refuses to subscribe a tenant that already has a trialing subscription', function () {
    app(PlanChangeService::class)->subscribe($this->tenant, $this->plan);

    $otherPlan = Plan::create([
        'code' => 'enterprise-subscribe-test',
        'name' => 'Enterprise',
        'storage_limit_gb' => 500,
        'bandwidth_limit_gb_per_day' => 200,
        'ai_spend_cap_usd_per_day' => 50,
        'price_inr_per_month' => 19999,
    ]);

    expect(fn () => app(PlanChangeService::class)->subscribe($this->tenant, $otherPlan))
        ->toThrow(TenantAlreadySubscribedException::class);

    expect(TenantSubscription::withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

it('allows subscribing again once a previous subscription was cancelled', function () {
    $first = app(PlanChangeService::class)->subscribe($this->tenant, $this->plan);
    $first->update(['status' => TenantSubscription::STATUS_CANCELLED, 'cancelled_at' => now()]);
    $this->tenant->update(['plan_id' => null]);

    $second = app(PlanChangeService::class)->subscribe($this->tenant, $this->plan);

    expect($second->id)->not->toBe($first->id)
        ->and($second->status)->toBe(TenantSubscription::STATUS_TRIALING);
});
