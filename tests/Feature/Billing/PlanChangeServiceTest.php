<?php

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Billing\Services\PlanChangeService;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\PlanDowngradeBlockedException;
use App\Exceptions\TenantAlreadySubscribedException;
use App\Exceptions\TenantNotSubscribedException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // B10's changePlan() always calls guardDowngradeCapacity(), which
    // always calls SeatQuota::usedNow() — Spatie's role() query scope
    // throws RoleDoesNotExist the moment it's asked to filter by a role
    // name with no matching Role row at all, not just "zero matches", so
    // every test that reaches changePlan() needs roles seeded even when
    // it never assigns one to a user (found by two of B10's own new tests
    // failing on this, not assumed in advance).
    $this->seed(RoleSeeder::class);

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

it('refuses to change the plan for a tenant with no live subscription', function () {
    expect(fn () => app(PlanChangeService::class)->changePlan($this->tenant, $this->plan))
        ->toThrow(TenantNotSubscribedException::class);
});

it('upgrades a plan and records a positive prorated invoice for the price difference', function () {
    $subscription = app(PlanChangeService::class)->subscribe($this->tenant, $this->plan); // price 7999
    $subscription->update([
        'status' => TenantSubscription::STATUS_ACTIVE,
        'current_period_start' => now()->subDays(10),
        'current_period_end' => now()->addDays(20),
    ]);

    $enterprise = Plan::create([
        'code' => 'enterprise-changeplan-test',
        'name' => 'Enterprise',
        'storage_limit_gb' => 500,
        'bandwidth_limit_gb_per_day' => 200,
        'ai_spend_cap_usd_per_day' => 50,
        'price_inr_per_month' => 22999, // exactly +15000 over the fixture plan's 7999
    ]);

    app(PlanChangeService::class)->changePlan($this->tenant, $enterprise);

    expect($this->tenant->fresh()->plan_id)->toBe($enterprise->id)
        ->and($subscription->fresh()->plan_id)->toBe($enterprise->id);

    $invoice = SubscriptionInvoice::withoutGlobalScope('tenant')
        ->where('tenant_subscription_id', $subscription->id)
        ->where('kind', SubscriptionInvoice::KIND_PRORATION)
        ->sole();

    // (20/30) x 15000 = 10000.00 — a small float tolerance covers the few
    // milliseconds of real elapsed time between setting current_period_end
    // above and prorate() reading now() again inside changePlan().
    expect((float) $invoice->amount)->toBeGreaterThan(9990.0)
        ->and((float) $invoice->amount)->toBeLessThan(10010.0)
        ->and($invoice->status)->toBe(SubscriptionInvoice::STATUS_PENDING)
        ->and($invoice->plan_id)->toBe($enterprise->id);
});

it('downgrades a plan and records a negative prorated invoice (a credit)', function () {
    $growth = Plan::create([
        'code' => 'growth-downgrade-source-test',
        'name' => 'Growth',
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 22999,
    ]);
    $subscription = app(PlanChangeService::class)->subscribe($this->tenant, $growth);
    $subscription->update([
        'status' => TenantSubscription::STATUS_ACTIVE,
        'current_period_start' => now()->subDays(10),
        'current_period_end' => now()->addDays(20),
    ]);

    $starter = Plan::create([
        'code' => 'starter-downgrade-test',
        'name' => 'Starter',
        'storage_limit_gb' => 25,
        'bandwidth_limit_gb_per_day' => 20,
        'ai_spend_cap_usd_per_day' => 5,
        'price_inr_per_month' => 7999,
    ]);

    app(PlanChangeService::class)->changePlan($this->tenant, $starter);

    $invoice = SubscriptionInvoice::withoutGlobalScope('tenant')
        ->where('tenant_subscription_id', $subscription->id)
        ->where('kind', SubscriptionInvoice::KIND_PRORATION)
        ->sole();

    // (20/30) x (7999 - 22999) = -10000.00
    expect((float) $invoice->amount)->toBeLessThan(-9990.0)
        ->and((float) $invoice->amount)->toBeGreaterThan(-10010.0);
});

it('blocks a downgrade that would leave the tenant over the new plans seat limit', function () {
    $subscription = app(PlanChangeService::class)->subscribe($this->tenant, $this->plan);
    $subscription->update(['status' => TenantSubscription::STATUS_ACTIVE]);

    User::factory()->create(['tenant_id' => $this->tenant->id])->assignRole('institute_admin');
    User::factory()->create(['tenant_id' => $this->tenant->id])->assignRole('instructor');

    $tinyPlan = Plan::create([
        'code' => 'tiny-seat-test',
        'name' => 'Tiny',
        'seat_limit' => 1,
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 999,
    ]);

    expect(fn () => app(PlanChangeService::class)->changePlan($this->tenant, $tinyPlan))
        ->toThrow(PlanDowngradeBlockedException::class);

    expect($this->tenant->fresh()->plan_id)->toBe($this->plan->id);
});

it('blocks a downgrade that would leave the tenant over the new plans storage limit', function () {
    $subscription = app(PlanChangeService::class)->subscribe($this->tenant, $this->plan);
    $subscription->update(['status' => TenantSubscription::STATUS_ACTIVE]);

    app(App\Domains\Billing\Services\StorageQuota::class)->adjust($this->tenant, 50 * 1024 * 1024 * 1024);

    $tinyPlan = Plan::create([
        'code' => 'tiny-storage-test',
        'name' => 'Tiny',
        'storage_limit_gb' => 10,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 999,
    ]);

    expect(fn () => app(PlanChangeService::class)->changePlan($this->tenant, $tinyPlan))
        ->toThrow(PlanDowngradeBlockedException::class);
});

it('allows a downgrade that stays within the new plans limits', function () {
    $subscription = app(PlanChangeService::class)->subscribe($this->tenant, $this->plan);
    $subscription->update(['status' => TenantSubscription::STATUS_ACTIVE]);

    $modestPlan = Plan::create([
        'code' => 'modest-downgrade-test',
        'name' => 'Modest',
        'seat_limit' => 5,
        'storage_limit_gb' => 50,
        'bandwidth_limit_gb_per_day' => 20,
        'ai_spend_cap_usd_per_day' => 5,
        'price_inr_per_month' => 2999,
    ]);

    app(PlanChangeService::class)->changePlan($this->tenant, $modestPlan);

    expect($this->tenant->fresh()->plan_id)->toBe($modestPlan->id);
});

it('refuses to cancel a tenant with no live subscription', function () {
    expect(fn () => app(PlanChangeService::class)->cancel($this->tenant))
        ->toThrow(TenantNotSubscribedException::class);
});

it('cancels a live subscription and reverts the tenant to the legacy fallback (S11 B13)', function () {
    $subscription = app(PlanChangeService::class)->subscribe($this->tenant, $this->plan);
    $subscription->update(['status' => TenantSubscription::STATUS_ACTIVE]);

    app(PlanChangeService::class)->cancel($this->tenant);

    expect($subscription->fresh()->status)->toBe(TenantSubscription::STATUS_CANCELLED)
        ->and($subscription->fresh()->cancelled_at)->not->toBeNull()
        // Decision 11 — plan_id = null, not a special "cancelled plan" row.
        ->and($this->tenant->fresh()->plan_id)->toBeNull();
});

it('allows subscribing again after a cancellation', function () {
    $subscription = app(PlanChangeService::class)->subscribe($this->tenant, $this->plan);
    $subscription->update(['status' => TenantSubscription::STATUS_ACTIVE]);

    app(PlanChangeService::class)->cancel($this->tenant);
    $fresh = app(PlanChangeService::class)->subscribe($this->tenant, $this->plan);

    expect($fresh->status)->toBe(TenantSubscription::STATUS_TRIALING)
        ->and($this->tenant->fresh()->plan_id)->toBe($this->plan->id);
});

/**
 * S11 B16 review pass — liveSubscriptionFor() originally excluded
 * SUSPENDED, so cancel() threw TenantNotSubscribedException for exactly
 * the tenant a super_admin is most likely to want to cancel outright.
 * EditTenant's own cancelSubscription header action stays visible for a
 * suspended tenant (its ->visible() check is only `plan_id !== null`),
 * so this was a real visible-but-broken button, not just a theoretical
 * gap.
 */
it('cancels a suspended subscription instead of refusing it (S11 B16)', function () {
    $subscription = app(PlanChangeService::class)->subscribe($this->tenant, $this->plan);
    $subscription->update(['status' => TenantSubscription::STATUS_SUSPENDED]);

    app(PlanChangeService::class)->cancel($this->tenant);

    expect($subscription->fresh()->status)->toBe(TenantSubscription::STATUS_CANCELLED)
        ->and($this->tenant->fresh()->plan_id)->toBeNull();
});

/**
 * Same liveSubscriptionFor() fix, the changePlan() side — a suspended
 * tenant fixing itself by moving to an affordable plan must not be
 * refused either.
 */
it('changes the plan for a suspended subscription instead of refusing it (S11 B16)', function () {
    $subscription = app(PlanChangeService::class)->subscribe($this->tenant, $this->plan);
    $subscription->update(['status' => TenantSubscription::STATUS_SUSPENDED]);

    $cheaperPlan = Plan::create([
        'code' => 'starter-suspended-recovery-test',
        'name' => 'Starter',
        'seat_limit' => 5,
        'storage_limit_gb' => 20,
        'bandwidth_limit_gb_per_day' => 10,
        'ai_spend_cap_usd_per_day' => 2,
        'price_inr_per_month' => 1999,
    ]);

    app(PlanChangeService::class)->changePlan($this->tenant, $cheaperPlan);

    expect($subscription->fresh()->plan_id)->toBe($cheaperPlan->id)
        ->and($this->tenant->fresh()->plan_id)->toBe($cheaperPlan->id);
});
