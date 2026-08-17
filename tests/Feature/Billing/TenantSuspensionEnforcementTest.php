<?php

use App\Domains\Billing\Jobs\SuspendOverdueTenantSubscriptions;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/**
 * S11 B9 — confirms decision 8 ("reuse Tenant::STATUS_SUSPENDED, don't add
 * a second flag") actually holds true end-to-end, not just that the sweep
 * job writes the right column. ResolveTenant's own suspension gate
 * (Tenancy domain, built in S1) already has generic coverage
 * (ResolveTenantTest's "403s and flushes the session for a suspended
 * tenant") — this is the S11-specific chain nothing has exercised yet:
 * a real subscribe -> grace -> sweep sequence, confirmed to genuinely
 * lock the tenant out afterward, not just flip a column nothing reads.
 */
uses(RefreshDatabase::class);

function registerBillingTenantPingRoute(): void
{
    Route::domain('{subdomain}.lms.test')
        ->middleware(['web', 'resolve.tenant'])
        ->get('/__billing_tenant_ping', fn () => 'ok');
}

it('genuinely locks a tenant out after subscribe -> grace -> sweep, not just in the database', function () {
    $this->seed(RoleSeeder::class);
    registerBillingTenantPingRoute();

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $plan = Plan::create([
        'code' => 'growth-enforcement-test',
        'name' => 'Growth',
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
    ]);

    // Reachable before suspension.
    $this->get('http://physics.lms.test/__billing_tenant_ping')->assertOk();

    $subscription = TenantSubscription::create([
        'plan_id' => $plan->id,
        'status' => TenantSubscription::STATUS_GRACE,
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now(),
        'grace_ends_at' => now()->subDay(),
    ]);

    app(CurrentTenant::class)->clear();
    (new SuspendOverdueTenantSubscriptions)->handle();

    expect($tenant->fresh()->status)->toBe(Tenant::STATUS_SUSPENDED)
        ->and($subscription->fresh()->status)->toBe(TenantSubscription::STATUS_SUSPENDED);

    // The actual assertion this block exists for: real HTTP access is
    // blocked, and an active session is torn down — the same behaviour
    // ResolveTenantTest already proves for a directly-factory-suspended
    // tenant, now proven for one that arrived there via the real billing
    // chain instead.
    $this->withSession(['some' => 'state'])
        ->get('http://physics.lms.test/__billing_tenant_ping')
        ->assertForbidden();

    expect(session('some'))->toBeNull();
});

it('also blocks a suspended tenant from the Admin panel login itself', function () {
    $this->seed(RoleSeeder::class);

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $plan = Plan::create([
        'code' => 'growth-panel-enforcement-test',
        'name' => 'Growth',
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
    ]);

    TenantSubscription::create([
        'plan_id' => $plan->id,
        'status' => TenantSubscription::STATUS_GRACE,
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now(),
        'grace_ends_at' => now()->subDay(),
    ]);

    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    app(CurrentTenant::class)->clear();
    (new SuspendOverdueTenantSubscriptions)->handle();

    $this->actingAs($admin)
        ->get('http://physics.lms.test/admin')
        ->assertForbidden();
});
