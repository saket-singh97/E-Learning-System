<?php

use App\Domains\Api\Services\ApiKeyService;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the plain key once and stores only its hash', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $result = app(ApiKeyService::class)->issue($tenant, 'Zapier');

    expect($result['plainKey'])->toStartWith('lms_');
    expect($result['model']->key_hash)->toBe(hash('sha256', $result['plainKey']))
        ->and($result['model']->key_hash)->not->toBe($result['plainKey']);
});

it('verifies the correct plain key and records last use', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $result = app(ApiKeyService::class)->issue($tenant, 'Zapier');

    $resolved = app(ApiKeyService::class)->verify($result['plainKey']);

    expect($resolved->id)->toBe($result['model']->id)
        ->and($resolved->tenant_id)->toBe($tenant->id)
        ->and($resolved->fresh()->last_used_at)->not->toBeNull();
});

it('refuses a garbage key', function () {
    expect(app(ApiKeyService::class)->verify('not-a-real-key'))->toBeNull();
});

it('refuses a revoked key', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $result = app(ApiKeyService::class)->issue($tenant, 'Zapier');

    app(ApiKeyService::class)->revoke($result['model']);

    expect(app(ApiKeyService::class)->verify($result['plainKey']))->toBeNull();
});

it('never resolves one tenant\'s key against another tenant\'s records', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    app(CurrentTenant::class)->set($tenantA);
    $keyA = app(ApiKeyService::class)->issue($tenantA, 'A key');

    app(CurrentTenant::class)->set($tenantB);
    $keyB = app(ApiKeyService::class)->issue($tenantB, 'B key');

    $resolved = app(ApiKeyService::class)->verify($keyA['plainKey']);

    expect($resolved->tenant_id)->toBe($tenantA->id)
        ->and($resolved->tenant_id)->not->toBe($tenantB->id)
        ->and($resolved->id)->not->toBe($keyB['model']->id);
});
