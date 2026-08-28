<?php

use App\Domains\Api\Services\ApiKeyService;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets a valid key through and resolves the calling tenant', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $result = app(ApiKeyService::class)->issue($tenant, 'Zapier');

    $this->withHeader('Authorization', "Bearer {$result['plainKey']}")
        ->getJson('/api/v1/health')
        ->assertOk()
        ->assertJson(['ok' => true, 'tenant' => 'physics']);
});

it('refuses a request with no bearer token', function () {
    $this->getJson('/api/v1/health')->assertUnauthorized();
});

it('refuses an invalid bearer token', function () {
    $this->withHeader('Authorization', 'Bearer not-a-real-key')
        ->getJson('/api/v1/health')
        ->assertUnauthorized();
});

it('refuses a revoked key', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $result = app(ApiKeyService::class)->issue($tenant, 'Zapier');
    app(ApiKeyService::class)->revoke($result['model']);

    $this->withHeader('Authorization', "Bearer {$result['plainKey']}")
        ->getJson('/api/v1/health')
        ->assertUnauthorized();
});

it('never lets one tenant\'s key resolve into another tenant\'s scope', function () {
    $tenantA = Tenant::factory()->create(['subdomain' => 'physics']);
    $tenantB = Tenant::factory()->create(['subdomain' => 'chemistry']);

    app(CurrentTenant::class)->set($tenantA);
    $keyA = app(ApiKeyService::class)->issue($tenantA, 'A key');

    app(CurrentTenant::class)->clear();

    $this->withHeader('Authorization', "Bearer {$keyA['plainKey']}")
        ->getJson('/api/v1/health')
        ->assertOk()
        ->assertJson(['tenant' => 'physics'])
        ->assertJsonMissing(['tenant' => 'chemistry']);
});
