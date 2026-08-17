<?php

use App\Domains\Billing\Jobs\SuspendOverdueTenantSubscriptions;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->plan = Plan::create([
        'code' => 'growth-sweep-test',
        'name' => 'Growth',
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
    ]);
});

function tenantSubscriptionFixture(string $status, ?string $graceEndsAt): array
{
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $subscription = TenantSubscription::create([
        'plan_id' => test()->plan->id,
        'status' => $status,
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now(),
        'grace_ends_at' => $graceEndsAt,
    ]);

    app(CurrentTenant::class)->clear();

    return [$tenant, $subscription];
}

it('suspends a subscription and its tenant once the grace deadline has passed', function () {
    [$tenant, $subscription] = tenantSubscriptionFixture(TenantSubscription::STATUS_GRACE, now()->subDay()->toDateTimeString());

    (new SuspendOverdueTenantSubscriptions)->handle();

    expect($subscription->fresh()->status)->toBe(TenantSubscription::STATUS_SUSPENDED)
        ->and($tenant->fresh()->status)->toBe(Tenant::STATUS_SUSPENDED);
});

it('leaves a subscription alone while the grace deadline is still in the future', function () {
    [$tenant, $subscription] = tenantSubscriptionFixture(TenantSubscription::STATUS_GRACE, now()->addDays(3)->toDateTimeString());

    (new SuspendOverdueTenantSubscriptions)->handle();

    expect($subscription->fresh()->status)->toBe(TenantSubscription::STATUS_GRACE)
        ->and($tenant->fresh()->status)->toBe(Tenant::STATUS_ACTIVE);
});

it('never touches an active subscription, even one carrying a stale past grace_ends_at', function () {
    [$tenant, $subscription] = tenantSubscriptionFixture(TenantSubscription::STATUS_ACTIVE, now()->subDay()->toDateTimeString());

    (new SuspendOverdueTenantSubscriptions)->handle();

    expect($subscription->fresh()->status)->toBe(TenantSubscription::STATUS_ACTIVE)
        ->and($tenant->fresh()->status)->toBe(Tenant::STATUS_ACTIVE);
});

it('never touches a cancelled subscription', function () {
    [$tenant, $subscription] = tenantSubscriptionFixture(TenantSubscription::STATUS_CANCELLED, now()->subDay()->toDateTimeString());

    (new SuspendOverdueTenantSubscriptions)->handle();

    expect($subscription->fresh()->status)->toBe(TenantSubscription::STATUS_CANCELLED)
        ->and($tenant->fresh()->status)->toBe(Tenant::STATUS_ACTIVE);
});

it('runs cleanly for a tenant with no subscription at all', function () {
    Tenant::factory()->create();

    expect(fn () => (new SuspendOverdueTenantSubscriptions)->handle())->not->toThrow(Throwable::class);
});

it('suspends every overdue tenant in one unscoped pass, regardless of CurrentTenant', function () {
    [$tenantA, $subscriptionA] = tenantSubscriptionFixture(TenantSubscription::STATUS_GRACE, now()->subDay()->toDateTimeString());
    [$tenantB, $subscriptionB] = tenantSubscriptionFixture(TenantSubscription::STATUS_GRACE, now()->subHours(2)->toDateTimeString());

    $unrelated = Tenant::factory()->create();
    app(CurrentTenant::class)->set($unrelated);

    (new SuspendOverdueTenantSubscriptions)->handle();

    expect($subscriptionA->fresh()->status)->toBe(TenantSubscription::STATUS_SUSPENDED)
        ->and($subscriptionB->fresh()->status)->toBe(TenantSubscription::STATUS_SUSPENDED)
        ->and($tenantA->fresh()->status)->toBe(Tenant::STATUS_SUSPENDED)
        ->and($tenantB->fresh()->status)->toBe(Tenant::STATUS_SUSPENDED);
});
