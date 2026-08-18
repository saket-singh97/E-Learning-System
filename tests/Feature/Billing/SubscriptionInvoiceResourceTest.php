<?php

use App\Domains\Billing\Filament\Resources\SubscriptionInvoices\Pages\ListSubscriptionInvoices;
use App\Domains\Billing\Filament\Resources\SubscriptionInvoices\SubscriptionInvoiceResource;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Tenancy\Models\Tenant;
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

    $tenant = Tenant::factory()->create(['subdomain' => 'physics', 'name' => 'Physics Institute']);
    app(CurrentTenant::class)->set($tenant);
    $plan = Plan::create([
        'code' => 'growth-invoice-resource-test', 'name' => 'Growth', 'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50, 'ai_spend_cap_usd_per_day' => 10, 'price_inr_per_month' => 7999,
    ]);
    $subscription = TenantSubscription::create([
        'plan_id' => $plan->id,
        'status' => TenantSubscription::STATUS_ACTIVE,
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);
    $this->invoice = SubscriptionInvoice::create([
        'tenant_subscription_id' => $subscription->id,
        'plan_id' => $plan->id,
        'amount' => 7999,
        'period_start' => now(),
        'period_end' => now()->addMonth(),
    ]);
    app(CurrentTenant::class)->clear();

    $this->actingAs($this->superAdmin)
        ->get(SubscriptionInvoiceResource::getUrl('index', panel: 'super'))
        ->assertOk();
});

it('lists an invoice with its institute and plan, across tenants', function () {
    Livewire::test(ListSubscriptionInvoices::class)
        ->assertSee('Physics Institute')
        ->assertSee('Growth')
        ->assertSee('7,999.00');
});

it('hides a deleted invoice by default, shows it under the Trashed filter, and restores it (S12 B13)', function () {
    $this->invoice->delete();

    Livewire::test(ListSubscriptionInvoices::class)
        ->assertCanNotSeeTableRecords([$this->invoice]);

    Livewire::test(ListSubscriptionInvoices::class)
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$this->invoice])
        ->callTableAction('restore', $this->invoice);

    expect($this->invoice->fresh()->deleted_at)->toBeNull();
});

it('refuses a non-super_admin', function () {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    $this->actingAs($admin)
        ->get(SubscriptionInvoiceResource::getUrl('index', panel: 'super'))
        ->assertForbidden();
});
