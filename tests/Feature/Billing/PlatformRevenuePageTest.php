<?php

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Pages\PlatformRevenue;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows MRR/ARR figures to a super admin, aggregated across every institute', function () {
    test()->seed(RoleSeeder::class);

    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $superAdmin->assignRole('super_admin');

    $plan = Plan::create([
        'code' => 'growth-revenue-page-test',
        'name' => 'Growth',
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 3000,
    ]);

    $tenant = Tenant::factory()->create(['name' => 'Physics Academy', 'subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    TenantSubscription::create([
        'plan_id' => $plan->id,
        'status' => TenantSubscription::STATUS_ACTIVE,
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);
    app(CurrentTenant::class)->clear();

    test()->actingAs($superAdmin)
        ->get(PlatformRevenue::getUrl(panel: 'super'))
        ->assertOk()
        ->assertSee('3,000.00')
        ->assertSee('36,000.00');
});
