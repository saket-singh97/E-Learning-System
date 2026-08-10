<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Filament\Support\Colors\Color;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('renders the admin panel with its own tenant primary color', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics', 'primary_color' => '#ff0000']);
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry', 'primary_color' => '#0000ff']);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    $response = $this->actingAs($admin)->get('http://physics.lms.test/admin');
    $response->assertOk();

    $ownShade = Color::generatePalette($tenant->primary_color)[600];
    $otherShade = Color::generatePalette($otherTenant->primary_color)[600];

    expect($response->getContent())->toContain($ownShade);
    expect($response->getContent())->not->toContain($otherShade);
});

it('does not break the bare-domain super panel, which has no tenant bound', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $superAdmin->assignRole('super_admin');

    $this->actingAs($superAdmin)
        ->get('http://lms.test/super')
        ->assertOk();
});
