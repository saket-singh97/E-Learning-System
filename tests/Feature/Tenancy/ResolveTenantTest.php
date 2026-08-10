<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\MissingTenantContextException;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function registerTenantPingRoute(): void
{
    Route::domain('{subdomain}.lms.test')
        ->middleware(['web', 'resolve.tenant'])
        ->get('/__tenant_ping', fn () => app(CurrentTenant::class)->get()->subdomain);
}

it('binds the matching tenant for a known subdomain', function () {
    Tenant::factory()->create(['subdomain' => 'physics']);
    Tenant::factory()->create(['subdomain' => 'chemistry']);
    registerTenantPingRoute();

    $this->get('http://physics.lms.test/__tenant_ping')->assertOk()->assertSee('physics');
    $this->get('http://chemistry.lms.test/__tenant_ping')->assertOk()->assertSee('chemistry');
});

it('404s for an unknown subdomain', function () {
    registerTenantPingRoute();

    $this->get('http://nope.lms.test/__tenant_ping')->assertNotFound();
});

it('403s and flushes the session for a suspended tenant', function () {
    Tenant::factory()->suspended()->create(['subdomain' => 'suspended']);
    registerTenantPingRoute();

    $this->withSession(['foo' => 'bar'])
        ->get('http://suspended.lms.test/__tenant_ping')
        ->assertForbidden();

    expect(session('foo'))->toBeNull();
});

it('throws when a tenant-scoped model is created with no tenant bound', function () {
    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        use \App\Support\Tenancy\BelongsToTenant;

        protected $table = 'tenants';

        protected $fillable = ['name', 'subdomain'];
    };

    expect(fn () => $model::create(['name' => 'x', 'subdomain' => 'x']))
        ->toThrow(MissingTenantContextException::class);
});
