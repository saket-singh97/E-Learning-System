<?php

use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('blocks a student from the teach panel', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    $this->actingAs($student)
        ->get('http://physics.lms.test/teach')
        ->assertForbidden();
});

it('blocks an instructor from the admin panel', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get('http://physics.lms.test/admin')
        ->assertForbidden();
});

it('lets an instructor into the teach panel', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get('http://physics.lms.test/teach')
        ->assertOk();
});

it('lets an institute admin into the admin panel', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');

    $this->actingAs($admin)
        ->get('http://physics.lms.test/admin')
        ->assertOk();
});

it('lets a super admin reach the super panel on the bare domain, no subdomain needed', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $superAdmin->assignRole('super_admin');

    $this->actingAs($superAdmin)
        ->get('http://lms.test/super')
        ->assertOk();
});

it('does not register Content resources in the super or admin panels, only teach', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);

    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $superAdmin->assignRole('super_admin');
    $this->actingAs($superAdmin)->get('http://lms.test/super/courses')->assertNotFound();

    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');
    $this->actingAs($admin)->get('http://physics.lms.test/admin/courses')->assertNotFound();

    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');
    $this->actingAs($instructor)->get('http://physics.lms.test/teach/courses')->assertOk();
});
