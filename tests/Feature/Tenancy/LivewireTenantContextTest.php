<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Livewire registers its /livewire/update endpoint with no domain constraint
 * and only the `web` middleware group. Every Livewire action in the app — all
 * three Filament panel logins, the student portal, any component holding a
 * tenant-scoped model — posts there. Without tenant context on that route,
 * CurrentTenant is unbound, so BelongsToTenant's global scope stops filtering
 * mid-request and tenant-scoped credential lookups throw. Livewire::test()
 * never exercises this path (it runs in-process with the tenant already
 * bound), which is why the rest of the suite cannot catch a regression here.
 */
it('keeps the livewire update route inside the web group that resolves tenants', function () {
    $updateRoute = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => str_ends_with((string) $route->getName(), 'livewire.update'));

    expect($updateRoute)->not->toBeNull();
    expect($updateRoute->gatherMiddleware())->toContain('web');
});

it('binds the current tenant on a plain web-group route reached through a tenant subdomain', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);

    Route::middleware('web')->get('/__tenant_probe', fn () => response()->json([
        'bound' => app(CurrentTenant::class)->isBound(),
        'id' => app(CurrentTenant::class)->isBound() ? app(CurrentTenant::class)->id() : null,
    ]));

    $this->get('http://physics.lms.test/__tenant_probe')
        ->assertOk()
        ->assertJson(['bound' => true, 'id' => $tenant->id]);
});

it('leaves the tenant unbound on the bare platform host instead of 404ing', function () {
    Route::middleware('web')->get('/__tenant_probe', fn () => response()->json([
        'bound' => app(CurrentTenant::class)->isBound(),
    ]));

    $this->get('http://lms.test/__tenant_probe')
        ->assertOk()
        ->assertJson(['bound' => false]);
});

it('still rejects a suspended tenant on a web-group route', function () {
    Tenant::factory()->create(['subdomain' => 'physics', 'status' => 'suspended']);

    Route::middleware('web')->get('/__tenant_probe', fn () => response()->noContent());

    $this->get('http://physics.lms.test/__tenant_probe')->assertForbidden();
});
