<?php

use App\Domains\Tenancy\Models\FeatureFlag;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a feature flag for a tenant', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $flag = FeatureFlag::create(['key' => 'doubt_chat', 'enabled' => true]);

    expect($flag->tenant_id)->toBe($tenant->id);
    expect($flag->enabled)->toBeTrue();
});

it('defaults enabled to false when omitted', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $flag = FeatureFlag::create(['key' => 'analytics']);

    expect($flag->fresh()->enabled)->toBeFalse();
});

it('rejects a duplicate key for the same tenant', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    FeatureFlag::create(['key' => 'doubt_chat', 'enabled' => true]);

    expect(fn () => FeatureFlag::create(['key' => 'doubt_chat', 'enabled' => false]))
        ->toThrow(QueryException::class);
});

it('lets two tenants set the same key independently', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    app(CurrentTenant::class)->set($tenantA);
    FeatureFlag::create(['key' => 'doubt_chat', 'enabled' => true]);

    app(CurrentTenant::class)->set($tenantB);
    FeatureFlag::create(['key' => 'doubt_chat', 'enabled' => false]);

    expect(FeatureFlag::withoutGlobalScope('tenant')->where('tenant_id', $tenantA->id)->first()->enabled)->toBeTrue();
    expect(FeatureFlag::withoutGlobalScope('tenant')->where('tenant_id', $tenantB->id)->first()->enabled)->toBeFalse();
});
