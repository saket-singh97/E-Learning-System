<?php

use App\Domains\Tenancy\Models\RoleAssignmentAudit;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Policies\RoleAssignmentPolicy;
use App\Exceptions\PrivilegeEscalationException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function registerAssignRoleRoute(): void
{
    Route::domain('{subdomain}.lms.test')
        ->middleware(['web', 'resolve.tenant'])
        ->post('/__assign_role/{actor}/{target}/{role}', function (string $subdomain, User $actor, User $target, string $role) {
            app(RoleAssignmentPolicy::class)->assign($actor, $target, $role);

            return response()->json(['status' => 'assigned']);
        });
}

function userWithRole(Tenant $tenant, string $role): User
{
    app(CurrentTenant::class)->set($tenant);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);

    return $user;
}

it('blocks an institute admin from creating a super admin, and audits it', function () {
    registerAssignRoleRoute();
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $admin = userWithRole($tenant, 'institute_admin');
    $target = userWithRole($tenant, 'student');

    $response = $this->postJson("http://physics.lms.test/__assign_role/{$admin->id}/{$target->id}/super_admin");

    $response->assertForbidden();
    expect($target->fresh()->hasRole('super_admin'))->toBeFalse();

    $audit = RoleAssignmentAudit::withoutGlobalScope('tenant')->latest('id')->first();
    expect($audit->allowed)->toBeFalse();
    expect($audit->actor_role)->toBe('institute_admin');
    expect($audit->attempted_role)->toBe('super_admin');
});

it('blocks an institute admin from creating another institute admin, and audits it', function () {
    registerAssignRoleRoute();
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $admin = userWithRole($tenant, 'institute_admin');
    $target = userWithRole($tenant, 'student');

    $response = $this->postJson("http://physics.lms.test/__assign_role/{$admin->id}/{$target->id}/institute_admin");

    $response->assertForbidden();
    expect($target->fresh()->hasRole('institute_admin'))->toBeFalse();

    $audit = RoleAssignmentAudit::withoutGlobalScope('tenant')->latest('id')->first();
    expect($audit->allowed)->toBeFalse();
});

it('blocks an instructor from assigning any role, and audits it', function () {
    registerAssignRoleRoute();
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $instructor = userWithRole($tenant, 'instructor');
    $target = userWithRole($tenant, 'student');

    $response = $this->postJson("http://physics.lms.test/__assign_role/{$instructor->id}/{$target->id}/student");

    $response->assertForbidden();

    $audit = RoleAssignmentAudit::withoutGlobalScope('tenant')->latest('id')->first();
    expect($audit->allowed)->toBeFalse();
    expect($audit->actor_role)->toBe('instructor');
});

it('lets an institute admin assign instructor, student, and parent', function (string $role) {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $admin = userWithRole($tenant, 'institute_admin');
    $target = userWithRole($tenant, 'student');
    $target->syncRoles([]);

    app(RoleAssignmentPolicy::class)->assign($admin, $target, $role);

    expect($target->fresh()->hasRole($role))->toBeTrue();
})->with(['instructor', 'student', 'parent']);

it('blocks an institute admin from assigning a role to a user in a different tenant, and audits it', function () {
    registerAssignRoleRoute();
    $tenantA = Tenant::factory()->create(['subdomain' => 'physics']);
    $tenantB = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $admin = userWithRole($tenantA, 'institute_admin');
    $target = userWithRole($tenantB, 'student');

    $response = $this->postJson("http://physics.lms.test/__assign_role/{$admin->id}/{$target->id}/instructor");

    $response->assertForbidden();
    expect($target->fresh()->hasRole('instructor'))->toBeFalse();

    $audit = RoleAssignmentAudit::withoutGlobalScope('tenant')->latest('id')->first();
    expect($audit->allowed)->toBeFalse();
});

it('lets a platform-level super admin (no tenant_id) assign a role across tenants', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $platformSuper = User::factory()->create(['tenant_id' => null]);
    $platformSuper->assignRole('super_admin');
    $target = userWithRole($tenant, 'student');
    $target->syncRoles([]);

    app(RoleAssignmentPolicy::class)->assign($platformSuper, $target, 'institute_admin');

    expect($target->fresh()->hasRole('institute_admin'))->toBeTrue();
});

it('lets a super admin assign institute_admin, but not another super_admin', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $super = userWithRole($tenant, 'super_admin');
    $target = userWithRole($tenant, 'student');
    $target->syncRoles([]);

    app(RoleAssignmentPolicy::class)->assign($super, $target, 'institute_admin');
    expect($target->fresh()->hasRole('institute_admin'))->toBeTrue();

    expect(fn () => app(RoleAssignmentPolicy::class)->assign($super, $target, 'super_admin'))
        ->toThrow(PrivilegeEscalationException::class);
});
