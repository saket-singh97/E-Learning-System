<?php

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Pages\InstituteSubscriptions;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->superAdmin = User::factory()->create(['tenant_id' => null]);
    $this->superAdmin->assignRole('super_admin');

    $this->plan = Plan::create([
        'code' => 'growth-institute-subs-test',
        'name' => 'Growth',
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
    ]);
});

function subscribedTenantFixture(string $name, string $subdomain, string $status): Tenant
{
    $tenant = Tenant::factory()->create(['name' => $name, 'subdomain' => $subdomain]);
    app(CurrentTenant::class)->set($tenant);

    TenantSubscription::create([
        'plan_id' => test()->plan->id,
        'status' => $status,
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    app(CurrentTenant::class)->clear();

    return $tenant;
}

it('lists every tenants subscription in one cross-tenant table', function () {
    subscribedTenantFixture('Physics Academy', 'physics', TenantSubscription::STATUS_ACTIVE);
    subscribedTenantFixture('Chemistry Academy', 'chemistry', TenantSubscription::STATUS_GRACE);

    $this->actingAs($this->superAdmin)
        ->get(InstituteSubscriptions::getUrl(panel: 'super'))
        ->assertOk()
        ->assertSee('Physics Academy')
        ->assertSee('Chemistry Academy');
});

it('shows every subscription regardless of which tenant CurrentTenant happens to be bound to', function () {
    $tenantA = subscribedTenantFixture('Physics Academy', 'physics', TenantSubscription::STATUS_ACTIVE);
    subscribedTenantFixture('Chemistry Academy', 'chemistry', TenantSubscription::STATUS_ACTIVE);

    // Simulates the exact stale-binding risk the block's own docblock
    // warns about — a prior request/job left CurrentTenant bound to one
    // specific tenant, and this page must not silently show only that
    // tenant's row.
    app(CurrentTenant::class)->set($tenantA);

    Livewire::test(InstituteSubscriptions::class)
        ->assertCanSeeTableRecords(TenantSubscription::withoutGlobalScope('tenant')->get());
});

it('filters the list by subscription status', function () {
    subscribedTenantFixture('Physics Academy', 'physics', TenantSubscription::STATUS_ACTIVE);
    subscribedTenantFixture('Chemistry Academy', 'chemistry', TenantSubscription::STATUS_GRACE);

    Livewire::test(InstituteSubscriptions::class)
        ->filterTable('status', TenantSubscription::STATUS_GRACE)
        ->assertCanSeeTableRecords(TenantSubscription::withoutGlobalScope('tenant')->where('status', TenantSubscription::STATUS_GRACE)->get())
        ->assertCanNotSeeTableRecords(TenantSubscription::withoutGlobalScope('tenant')->where('status', TenantSubscription::STATUS_ACTIVE)->get());
});
