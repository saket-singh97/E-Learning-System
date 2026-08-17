<?php

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\TenantStorageUsage;
use App\Domains\Billing\Services\StorageQuota;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults a fresh tenant to zero usage against the legacy free-tier cap', function () {
    $tenant = Tenant::factory()->create();
    $quota = app(StorageQuota::class);

    expect($quota->usedNow($tenant))->toBe(0)
        ->and($quota->capFor($tenant))->toBe(5 * 1024 * 1024 * 1024)
        ->and($quota->remainingFor($tenant))->toBe(5 * 1024 * 1024 * 1024);
});

it('reads the storage cap from a real plan once assigned', function () {
    $plan = Plan::create([
        'code' => 'storage-plan-test',
        'name' => 'Custom',
        'storage_limit_gb' => 20,
        'bandwidth_limit_gb_per_day' => 10,
        'ai_spend_cap_usd_per_day' => 1,
        'price_inr_per_month' => 0,
    ]);
    $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

    expect(app(StorageQuota::class)->capFor($tenant))->toBe(20 * 1024 * 1024 * 1024);
});

it('creates the usage row on first adjust and increments it', function () {
    $tenant = Tenant::factory()->create();
    $quota = app(StorageQuota::class);

    $quota->adjust($tenant, 1000);
    $quota->adjust($tenant, 500);

    expect($quota->usedNow($tenant))->toBe(1500)
        ->and(TenantStorageUsage::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('decrements usage on a negative adjust, floored at zero', function () {
    $tenant = Tenant::factory()->create();
    $quota = app(StorageQuota::class);

    $quota->adjust($tenant, 1000);
    $quota->adjust($tenant, -1500);

    expect($quota->usedNow($tenant))->toBe(0);
});

it('reads and adjusts the correct tenant regardless of what CurrentTenant is bound to', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $quota = app(StorageQuota::class);

    app(App\Support\Tenancy\CurrentTenant::class)->set($other);
    $quota->adjust($tenant, 2000);

    expect($quota->usedNow($tenant))->toBe(2000)
        ->and($quota->usedNow($other))->toBe(0);
});
