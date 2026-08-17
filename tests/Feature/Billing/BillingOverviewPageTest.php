<?php

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Billing\Services\StorageQuota;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Admin\Pages\BillingOverview;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');
});

it('shows "no plan assigned" for a tenant on the legacy fallback', function () {
    $this->actingAs($this->admin)
        ->get(BillingOverview::getUrl(['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk()
        ->assertSee('No plan assigned');
});

it('shows the current plan name and seat usage once subscribed', function () {
    $plan = Plan::create([
        'code' => 'growth-overview-test',
        'name' => 'Growth',
        'seat_limit' => 15,
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
    ]);
    $this->tenant->update(['plan_id' => $plan->id]);
    TenantSubscription::create([
        'plan_id' => $plan->id,
        'status' => TenantSubscription::STATUS_ACTIVE,
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    $this->actingAs($this->admin)
        ->get(BillingOverview::getUrl(['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk()
        ->assertSee('Growth')
        ->assertSee('Active')
        // The admin itself is one active institute_admin seat.
        ->assertSee('1 / 15');
});

it('shows the grace deadline when the subscription is in grace', function () {
    $plan = Plan::create([
        'code' => 'growth-overview-grace-test',
        'name' => 'Growth',
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
    ]);
    $this->tenant->update(['plan_id' => $plan->id]);
    TenantSubscription::create([
        'plan_id' => $plan->id,
        'status' => TenantSubscription::STATUS_GRACE,
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now(),
        'grace_ends_at' => now()->addDays(3),
    ]);

    $this->actingAs($this->admin)
        ->get(BillingOverview::getUrl(['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk()
        ->assertSee('grace period ends');
});

it('lists invoice history', function () {
    $plan = Plan::create([
        'code' => 'growth-overview-invoice-test',
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

    $this->actingAs($this->admin)
        ->get(BillingOverview::getUrl(['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk()
        ->assertSee('recurring')
        ->assertSee('paid');
});

it('reflects real storage usage in gigabytes', function () {
    app(StorageQuota::class)->adjust($this->tenant, 2 * 1024 * 1024 * 1024);

    $this->actingAs($this->admin)
        ->get(BillingOverview::getUrl(['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk()
        ->assertSee('2.00 GB');
});
