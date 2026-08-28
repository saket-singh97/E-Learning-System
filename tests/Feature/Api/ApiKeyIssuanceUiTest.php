<?php

use App\Domains\Api\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Domains\Api\Filament\Resources\ApiKeys\Pages\ListApiKeys;
use App\Domains\Api\Models\ApiKey;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('issues a key for the acting tenant from the Admin panel', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    $this->actingAs($admin)
        ->get(ApiKeyResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(ListApiKeys::class)
        ->callAction('issue', data: ['name' => 'Zapier']);

    $key = ApiKey::where('tenant_id', $tenant->id)->sole();
    expect($key->name)->toBe('Zapier')
        ->and($key->isRevoked())->toBeFalse();
});

it('revokes a key via the row action', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');
    $key = ApiKey::create(['name' => 'Zapier', 'key_hash' => hash('sha256', 'plain')]);

    $this->actingAs($admin)
        ->get(ApiKeyResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(ListApiKeys::class)
        ->callTableAction('revoke', $key);

    expect($key->fresh()->isRevoked())->toBeTrue();
});
