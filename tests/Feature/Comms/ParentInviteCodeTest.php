<?php

use App\Domains\Comms\Models\ParentGuardianLink;
use App\Domains\Comms\Models\ParentInviteCode;
use App\Domains\Comms\Services\ParentInviteCodeService;
use App\Domains\Tenancy\Models\RoleAssignmentAudit;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Admin\Pages\GenerateParentInviteCode;
use App\Livewire\ParentInviteRedemption;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('generates an 8-character invite code that expires in 7 days', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    app(CurrentTenant::class)->set($tenant);

    $invite = app(ParentInviteCodeService::class)->generate($student, $admin);

    expect($invite->code)->toHaveLength(8);
    expect($invite->tenant_id)->toBe($tenant->id);
    expect($invite->student_user_id)->toBe($student->id);
    expect($invite->created_by)->toBe($admin->id);
    expect($invite->expires_at->isSameDay(now()->addDays(7)))->toBeTrue();
    expect($invite->isRedeemed())->toBeFalse();
});

it('generates a code from the Filament admin page', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    app(CurrentTenant::class)->set($tenant);
    $this->actingAs($admin);

    Livewire::test(GenerateParentInviteCode::class)
        ->set('data.student_user_id', $student->id)
        ->callAction('generate');

    expect(ParentInviteCode::where('student_user_id', $student->id)->count())->toBe(1);
});

it('redeems a valid code, creates a parent account, links the student, and writes an audit row', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    app(CurrentTenant::class)->set($tenant);
    $invite = app(ParentInviteCodeService::class)->generate($student, $admin);
    app(CurrentTenant::class)->clear();

    $component = Livewire::test(ParentInviteRedemption::class)
        ->set('code', $invite->code)
        ->set('name', 'Priya Sharma')
        ->set('email', 'priya@example.com')
        ->set('phone', '+919876543210')
        ->set('password', 'secret-password')
        ->set('relationship', 'mother')
        ->call('redeem');

    $component->assertHasNoErrors();

    $parent = User::where('email', 'priya@example.com')->firstOrFail();
    expect($parent->tenant_id)->toBe($tenant->id);
    expect($parent->hasRole('parent'))->toBeTrue();
    $this->assertAuthenticatedAs($parent);

    expect(ParentGuardianLink::where('parent_user_id', $parent->id)
        ->where('student_user_id', $student->id)
        ->where('relationship', 'mother')
        ->exists())->toBeTrue();

    expect($invite->fresh()->isRedeemed())->toBeTrue();
    expect($invite->fresh()->redeemed_by)->toBe($parent->id);

    expect(RoleAssignmentAudit::where('target_user_id', $parent->id)
        ->where('attempted_role', 'parent')
        ->where('allowed', true)
        ->exists())->toBeTrue();
});

it('redeems correctly for the codes own tenant even when a different tenant is the current one', function () {
    $physics = Tenant::factory()->create(['subdomain' => 'physics']);
    $chemistry = Tenant::factory()->create(['subdomain' => 'chemistry']);

    app(CurrentTenant::class)->set($physics);
    $admin = User::factory()->create(['tenant_id' => $physics->id]);
    $admin->assignRole('institute_admin');
    $student = User::factory()->create(['tenant_id' => $physics->id]);
    $student->assignRole('student');
    $invite = app(ParentInviteCodeService::class)->generate($student, $admin);

    // Simulate landing on a *different* tenant's own subdomain to redeem —
    // the plan note's own point: the code carries the tenant, not the host.
    app(CurrentTenant::class)->set($chemistry);

    Livewire::test(ParentInviteRedemption::class)
        ->set('code', $invite->code)
        ->set('name', 'Priya Sharma')
        ->set('email', 'priya@example.com')
        ->set('phone', '+919876543210')
        ->set('password', 'secret-password')
        ->call('redeem')
        ->assertHasNoErrors();

    $parent = User::where('email', 'priya@example.com')->firstOrFail();
    expect($parent->tenant_id)->toBe($physics->id);
});

it('rejects an unknown invite code', function () {
    Livewire::test(ParentInviteRedemption::class)
        ->set('code', 'NOTREAL1')
        ->set('name', 'Priya Sharma')
        ->set('email', 'priya@example.com')
        ->set('phone', '+919876543210')
        ->set('password', 'secret-password')
        ->call('redeem')
        ->assertHasErrors('code');

    $this->assertGuest();
});

it('rejects an already-redeemed code', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');
    $invite = app(ParentInviteCodeService::class)->generate($student, $admin);

    app(ParentInviteCodeService::class)->redeem($invite->code, [
        'name' => 'First Parent',
        'email' => 'first@example.com',
        'phone' => '+919876543210',
        'password' => 'secret-password',
    ]);

    Livewire::test(ParentInviteRedemption::class)
        ->set('code', $invite->code)
        ->set('name', 'Second Parent')
        ->set('email', 'second@example.com')
        ->set('phone', '+919876543211')
        ->set('password', 'secret-password')
        ->call('redeem')
        ->assertHasErrors('code');

    expect(User::where('email', 'second@example.com')->exists())->toBeFalse();
});

it('rejects an expired code', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');
    $invite = app(ParentInviteCodeService::class)->generate($student, $admin);
    $invite->forceFill(['expires_at' => now()->subDay()])->save();

    Livewire::test(ParentInviteRedemption::class)
        ->set('code', $invite->code)
        ->set('name', 'Priya Sharma')
        ->set('email', 'priya@example.com')
        ->set('phone', '+919876543210')
        ->set('password', 'secret-password')
        ->call('redeem')
        ->assertHasErrors('code');

    $this->assertGuest();
});

it('only lets an institute_admin reach the generate-invite-code page', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get('http://physics.lms.test/admin/generate-parent-invite-code')
        ->assertForbidden();
});
