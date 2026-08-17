<?php

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Billing\Models\TenantStorageUsage;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Billing\Services\StorageQuota;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * S11 B15 — the "sweep vs. a soft-deleted parent" class of bug S10 B15
 * found (Batch/Course are SoftDeletes with no delete guard, so a stale
 * enrolment could point at a null relation) does not apply anywhere in
 * S11: neither Tenant nor Plan use SoftDeletes at all (confirmed by
 * grepping both models), and every FK involved is either cascadeOnDelete()
 * (Tenant -> its own billing rows) or left at the Postgres default
 * restrict (subscriptions/invoices -> Plan) rather than nullOnDelete() —
 * so an orphaned reference genuinely cannot exist to begin with. This
 * file exists to prove that claim, not just assert it in a comment.
 */
it('cascades a tenant force-delete to its subscriptions, invoices, and storage usage row', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $plan = Plan::create([
        'code' => 'cascade-test',
        'name' => 'Growth',
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
    ]);
    $subscription = TenantSubscription::create([
        'plan_id' => $plan->id,
        'status' => TenantSubscription::STATUS_ACTIVE,
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);
    SubscriptionInvoice::create([
        'tenant_subscription_id' => $subscription->id,
        'plan_id' => $plan->id,
        'amount' => 7999,
        'kind' => SubscriptionInvoice::KIND_RECURRING,
        'status' => SubscriptionInvoice::STATUS_PAID,
        'period_start' => now(),
        'period_end' => now()->addMonth(),
        'paid_at' => now(),
    ]);
    app(StorageQuota::class)->adjust($tenant, 1024);

    $tenant->forceDelete();

    expect(TenantSubscription::withoutGlobalScope('tenant')->where('id', $subscription->id)->exists())->toBeFalse()
        ->and(SubscriptionInvoice::withoutGlobalScope('tenant')->where('tenant_subscription_id', $subscription->id)->exists())->toBeFalse()
        ->and(TenantStorageUsage::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->exists())->toBeFalse()
        // The plan itself is untouched — only the tenant's own rows cascade.
        ->and(Plan::find($plan->id))->not->toBeNull();
});

it('refuses to delete a plan still referenced by a subscription, at the database level', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $plan = Plan::create([
        'code' => 'restrict-test',
        'name' => 'Growth',
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
    ]);
    TenantSubscription::create([
        'plan_id' => $plan->id,
        'status' => TenantSubscription::STATUS_ACTIVE,
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    // Bypasses PlanResource's own UI guard on purpose — this asserts the
    // real safety net is the database constraint, not just the button
    // being hidden.
    expect(fn () => $plan->delete())->toThrow(QueryException::class);
});
