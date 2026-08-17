<?php

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\GracePeriodBanner;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->plan = Plan::create([
        'code' => 'growth-banner-test',
        'name' => 'Growth',
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
    ]);
});

it('shows the days remaining while the subscription is in grace', function () {
    TenantSubscription::create([
        'plan_id' => $this->plan->id,
        'status' => TenantSubscription::STATUS_GRACE,
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now(),
        'grace_ends_at' => now()->addDays(4),
    ]);

    Livewire::test(GracePeriodBanner::class)
        ->assertSee('4 days')
        ->assertSee('grace period');
});

it('renders nothing when the subscription is active', function () {
    TenantSubscription::create([
        'plan_id' => $this->plan->id,
        'status' => TenantSubscription::STATUS_ACTIVE,
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    Livewire::test(GracePeriodBanner::class)
        ->assertDontSee('grace period');
});

it('renders nothing when no tenant is bound', function () {
    app(CurrentTenant::class)->clear();

    Livewire::test(GracePeriodBanner::class)
        ->assertDontSee('grace period');
});

it('floors days remaining at zero once the deadline has already passed', function () {
    TenantSubscription::create([
        'plan_id' => $this->plan->id,
        'status' => TenantSubscription::STATUS_GRACE,
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now(),
        'grace_ends_at' => now()->subHour(),
    ]);

    Livewire::test(GracePeriodBanner::class)
        ->assertSee('0 days');
});

it('is visible to an institute admin on the real admin panel page', function () {
    TenantSubscription::create([
        'plan_id' => $this->plan->id,
        'status' => TenantSubscription::STATUS_GRACE,
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now(),
        'grace_ends_at' => now()->addDays(2),
    ]);

    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $admin->assignRole('institute_admin');

    $this->actingAs($admin)
        ->get('http://physics.lms.test/admin')
        ->assertOk()
        ->assertSee('grace period');
});
