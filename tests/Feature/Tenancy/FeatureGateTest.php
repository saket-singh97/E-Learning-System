<?php

use App\Domains\Tenancy\Models\FeatureFlag;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\FeatureGate;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('blocks when the key has never been set for the tenant', function () {
    $tenant = Tenant::factory()->create();

    expect(app(FeatureGate::class)->enabled($tenant, 'doubt_chat'))->toBeFalse();
});

it('blocks when the flag exists but is explicitly false', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    FeatureFlag::create(['key' => 'doubt_chat', 'enabled' => false]);

    expect(app(FeatureGate::class)->enabled($tenant, 'doubt_chat'))->toBeFalse();
});

it('allows when the flag is true', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    FeatureFlag::create(['key' => 'doubt_chat', 'enabled' => true]);

    expect(app(FeatureGate::class)->enabled($tenant, 'doubt_chat'))->toBeTrue();
});

it('never leaks one tenant\'s flag into another tenant\'s check', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    app(CurrentTenant::class)->set($tenantA);
    FeatureFlag::create(['key' => 'doubt_chat', 'enabled' => true]);

    app(CurrentTenant::class)->set($tenantB);

    expect(app(FeatureGate::class)->enabled($tenantB, 'doubt_chat'))->toBeFalse();
    expect(app(FeatureGate::class)->enabled($tenantA, 'doubt_chat'))->toBeTrue();
});
