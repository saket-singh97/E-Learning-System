<?php

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function softDeletePlanFixture(): Plan
{
    return Plan::create([
        'code' => 'growth-softdelete-test-'.uniqid(),
        'name' => 'Growth',
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
    ]);
}

it('hides a deleted subscription and restores it (S12 B13)', function () {
    app(CurrentTenant::class)->set(Tenant::factory()->create());
    $subscription = TenantSubscription::create([
        'plan_id' => softDeletePlanFixture()->id,
        'status' => TenantSubscription::STATUS_ACTIVE,
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    $subscription->delete();

    expect(TenantSubscription::find($subscription->id))->toBeNull();
    expect(TenantSubscription::withTrashed()->find($subscription->id))->not->toBeNull();

    $subscription->restore();

    expect(TenantSubscription::find($subscription->id))->not->toBeNull();
});

it('hides a deleted subscription invoice and restores it (S12 B13)', function () {
    app(CurrentTenant::class)->set(Tenant::factory()->create());
    $plan = softDeletePlanFixture();
    $subscription = TenantSubscription::create([
        'plan_id' => $plan->id,
        'status' => TenantSubscription::STATUS_ACTIVE,
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);
    $invoice = SubscriptionInvoice::create([
        'tenant_subscription_id' => $subscription->id,
        'plan_id' => $plan->id,
        'amount' => 7999,
        'period_start' => now(),
        'period_end' => now()->addMonth(),
    ]);

    $invoice->delete();

    expect(SubscriptionInvoice::find($invoice->id))->toBeNull();
    expect(SubscriptionInvoice::withTrashed()->find($invoice->id))->not->toBeNull();

    $invoice->restore();

    expect(SubscriptionInvoice::find($invoice->id))->not->toBeNull();
});

/**
 * S12 B13 — same cross-tenant + soft-delete proof
 * `FeeInvoicePaymentSoftDeleteTest` already establishes for the Fees
 * domain, for the Billing-domain equivalent.
 */
it('never leaks another tenants soft-deleted subscription through withTrashed()', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    app(CurrentTenant::class)->set($tenantA);
    $subscriptionA = TenantSubscription::create([
        'plan_id' => softDeletePlanFixture()->id,
        'status' => TenantSubscription::STATUS_ACTIVE,
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);
    $subscriptionA->delete();

    app(CurrentTenant::class)->set($tenantB);
    $subscriptionB = TenantSubscription::create([
        'plan_id' => softDeletePlanFixture()->id,
        'status' => TenantSubscription::STATUS_ACTIVE,
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);
    $subscriptionB->delete();

    $visible = TenantSubscription::withTrashed()->pluck('id')->all();

    expect($visible)->toContain($subscriptionB->id)
        ->and($visible)->not->toContain($subscriptionA->id);
});
