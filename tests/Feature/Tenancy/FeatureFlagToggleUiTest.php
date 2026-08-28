<?php

use App\Domains\Tenancy\Filament\Resources\FeatureFlags\FeatureFlagResource;
use App\Domains\Tenancy\Filament\Resources\FeatureFlags\Pages\CreateFeatureFlag;
use App\Domains\Tenancy\Filament\Resources\FeatureFlags\Pages\ListFeatureFlags;
use App\Domains\Tenancy\Models\FeatureFlag;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function ffSuperAdmin(): User
{
    $user = User::factory()->create(['tenant_id' => null]);
    $user->assignRole('super_admin');

    return $user;
}

it('refuses an institute admin the create-flag page (cannot self-grant)', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    $this->actingAs($admin)
        ->get(FeatureFlagResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertForbidden();
});

it('lets a super admin create a flag for a chosen tenant', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $superAdmin = ffSuperAdmin();

    $this->actingAs($superAdmin)
        ->get(FeatureFlagResource::getUrl('index', panel: 'super'))
        ->assertOk();

    Livewire::test(CreateFeatureFlag::class)
        ->fillForm(['tenant_id' => $tenant->id, 'key' => 'doubt_chat', 'enabled' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $flag = FeatureFlag::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->sole();
    expect($flag->key)->toBe('doubt_chat')
        ->and($flag->enabled)->toBeTrue();
});

it('lets a super admin toggle an existing flag off', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $flag = FeatureFlag::create(['key' => 'doubt_chat', 'enabled' => true]);
    app(CurrentTenant::class)->clear();
    $superAdmin = ffSuperAdmin();

    $this->actingAs($superAdmin)
        ->get(FeatureFlagResource::getUrl('index', panel: 'super'))
        ->assertOk();

    Livewire::test(ListFeatureFlags::class)
        ->call('updateTableColumnState', 'enabled', (string) $flag->getKey(), false);

    expect($flag->fresh()->enabled)->toBeFalse();
});

it('scopes the flag list per tenant: admin sees only its own, super sees every tenant', function () {
    $tenantA = Tenant::factory()->create(['subdomain' => 'physics']);
    $tenantB = Tenant::factory()->create(['subdomain' => 'chemistry']);

    app(CurrentTenant::class)->set($tenantA);
    FeatureFlag::create(['key' => 'doubt_chat', 'enabled' => true]);

    app(CurrentTenant::class)->set($tenantB);
    FeatureFlag::create(['key' => 'analytics', 'enabled' => false]);

    $adminA = User::factory()->create(['tenant_id' => $tenantA->id]);
    $adminA->assignRole('institute_admin');

    app(CurrentTenant::class)->set($tenantA);
    Livewire::actingAs($adminA)
        ->test(ListFeatureFlags::class)
        ->assertCanSeeTableRecords(FeatureFlag::all())
        ->assertCountTableRecords(1);

    app(CurrentTenant::class)->clear();
    Livewire::actingAs(ffSuperAdmin())
        ->test(ListFeatureFlags::class)
        ->assertCountTableRecords(2);
});
