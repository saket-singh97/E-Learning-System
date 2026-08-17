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

/**
 * S11 B16 review pass — adjust() switched from a read-then-write update()
 * to an atomic increment()/decrement() to close a lost-update race (two
 * concurrent adjust() calls for the same tenant could otherwise overwrite
 * each other). A single-connection test can't reproduce the race itself
 * (same limitation B15's own concurrency tests already state plainly),
 * but this proves the sequential behaviour the atomic path must still
 * get right: repeated increments accumulate exactly, not just once.
 */
it('accumulates many sequential adjustments exactly via the atomic increment path', function () {
    $tenant = Tenant::factory()->create();
    $quota = app(StorageQuota::class);

    for ($i = 0; $i < 5; $i++) {
        $quota->adjust($tenant, 100);
    }

    expect($quota->usedNow($tenant))->toBe(500);
});

/**
 * decrement() has no "not below zero" variant the way the old
 * update()-based clamp did — the floor moved to usedNow()'s read side
 * instead (see the class docblock). This proves that split: the raw row
 * is allowed to go negative, but nothing reading through usedNow() ever
 * sees it.
 */
it('floors a transient negative row value at read time, not write time', function () {
    $tenant = Tenant::factory()->create();
    $quota = app(StorageQuota::class);

    $quota->adjust($tenant, 100);
    $quota->adjust($tenant, -500);

    $row = TenantStorageUsage::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->sole();
    expect((int) $row->bytes_used)->toBeLessThan(0)
        ->and($quota->usedNow($tenant))->toBe(0)
        ->and($quota->remainingFor($tenant))->toBe($quota->capFor($tenant));
});

/**
 * The create-new-row branch races too: two concurrent first-ever
 * adjust() calls for a tenant could both find no row and both attempt
 * create() against tenant_id's unique constraint. Can't force true
 * concurrency here either, but this proves the retry path itself is
 * correct in isolation — calling the protected retry method a second
 * time (as if the first create() had lost the race) still lands the
 * delta on the winner's row via the atomic increment path instead of
 * throwing.
 */
it('recovers via retry when the create-row path finds a row already won the race', function () {
    $tenant = Tenant::factory()->create();
    $quota = app(StorageQuota::class);

    TenantStorageUsage::withoutEvents(fn () => TenantStorageUsage::create([
        'tenant_id' => $tenant->id,
        'bytes_used' => 1000,
    ]));

    $reflection = new ReflectionMethod($quota, 'adjustWithRetry');
    $reflection->invoke($quota, $tenant, 250, false);

    expect($quota->usedNow($tenant))->toBe(1250)
        ->and(TenantStorageUsage::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(1);
});
