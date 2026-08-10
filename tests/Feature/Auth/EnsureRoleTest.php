<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function registerRoleGatedRoute(): void
{
    Route::domain('{subdomain}.lms.test')
        ->middleware(['web', 'resolve.tenant', 'auth', 'role:instructor,institute_admin'])
        ->get('/__gated', fn () => 'ok');
}

it('lets a user with an allowed role through', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    registerRoleGatedRoute();

    $this->actingAs($instructor)
        ->get('http://physics.lms.test/__gated')
        ->assertOk()
        ->assertSee('ok');
});

it('blocks a user without an allowed role', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    registerRoleGatedRoute();

    $this->actingAs($student)
        ->get('http://physics.lms.test/__gated')
        ->assertForbidden();
});

it('blocks a guest', function () {
    Tenant::factory()->create(['subdomain' => 'physics']);
    registerRoleGatedRoute();

    $this->getJson('http://physics.lms.test/__gated')->assertUnauthorized();
});

it('blocks a user with an allowed role whose own tenant does not match the resolved host', function () {
    // Spatie roles carry no tenant_id, and SESSION_DOMAIN is a shared parent
    // domain, so a session cookie from tenant A stays valid on tenant B's
    // subdomain. An instructor logged in at tenant A who simply opens tenant
    // B's URL must not pass just because the "instructor" role matches.
    $tenantA = Tenant::factory()->create(['subdomain' => 'physics']);
    $tenantB = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($tenantA);
    $instructor = User::factory()->create(['tenant_id' => $tenantA->id]);
    $instructor->assignRole('instructor');

    registerRoleGatedRoute();

    app(CurrentTenant::class)->set($tenantB);

    $this->actingAs($instructor)
        ->get('http://chemistry.lms.test/__gated')
        ->assertForbidden();
});
