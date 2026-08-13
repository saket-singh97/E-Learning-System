<?php

use App\Domains\Tenancy\Models\ImpersonationAudit;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\ImpersonationService;
use App\Exceptions\CannotImpersonateException;
use App\Filament\Pages\ImpersonateUser;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('starts an impersonation session, writes an audit row, and sets the session flags', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $superAdmin->assignRole('super_admin');

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $audit = app(ImpersonationService::class)->start($superAdmin, $instructor);

    expect($audit->tenant_id)->toBe($tenant->id);
    expect($audit->actor_id)->toBe($superAdmin->id);
    expect($audit->target_user_id)->toBe($instructor->id);
    expect($audit->ended_at)->toBeNull();

    expect(session('impersonating_user_id'))->toBe($instructor->id);
    expect(session('impersonator_id'))->toBe($superAdmin->id);
});

it('refuses to impersonate another super_admin and writes no audit row', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $superAdmin->assignRole('super_admin');

    $otherSuperAdmin = User::factory()->create(['tenant_id' => null]);
    $otherSuperAdmin->assignRole('super_admin');

    expect(fn () => app(ImpersonationService::class)->start($superAdmin, $otherSuperAdmin))
        ->toThrow(CannotImpersonateException::class);

    expect(ImpersonationAudit::count())->toBe(0);
});

it('stops an impersonation session by stamping ended_at and clearing the session', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $superAdmin->assignRole('super_admin');

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    $audit = app(ImpersonationService::class)->start($superAdmin, $student);

    app(ImpersonationService::class)->stop();

    expect($audit->fresh()->ended_at)->not->toBeNull();
    expect(session()->has('impersonating_user_id'))->toBeFalse();
});

it('resolves the impersonated user as Auth::user() on the target tenants own panel', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $superAdmin->assignRole('super_admin');

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($superAdmin);
    app(ImpersonationService::class)->start($superAdmin, $instructor);

    $this->get('http://physics.lms.test/teach')->assertOk();

    expect(auth()->id())->toBe($instructor->id);
});

it('stops resolving the impersonated identity once the session is older than 60 minutes', function () {
    // S9 B14 review pass: docs/FEATURES.md's A6 row claims "60-min
    // auto-expiry" explicitly — found unimplemented anywhere in the
    // original S9 B3 build (ResolveImpersonation had no time check at
    // all). Fixed as part of this review pass, not just flagged.
    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $superAdmin->assignRole('super_admin');

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($superAdmin);
    $audit = app(ImpersonationService::class)->start($superAdmin, $instructor);
    $audit->update(['started_at' => now()->subMinutes(61)]);

    $this->get('http://physics.lms.test/teach')->assertForbidden();

    expect(auth()->id())->toBe($superAdmin->id)
        ->and($audit->fresh()->ended_at)->not->toBeNull()
        ->and(session()->has('impersonating_user_id'))->toBeFalse();
});

it('still resolves the impersonated identity at 59 minutes, just under the cutoff', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $superAdmin->assignRole('super_admin');

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($superAdmin);
    $audit = app(ImpersonationService::class)->start($superAdmin, $instructor);
    $audit->update(['started_at' => now()->subMinutes(59)]);

    $this->get('http://physics.lms.test/teach')->assertOk();

    expect(auth()->id())->toBe($instructor->id);
});

it('fails closed when the session references an audit row that no longer exists', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $superAdmin->assignRole('super_admin');

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($superAdmin);
    $audit = app(ImpersonationService::class)->start($superAdmin, $instructor);
    ImpersonationAudit::whereKey($audit->id)->delete();

    $this->get('http://physics.lms.test/teach')->assertForbidden();

    expect(auth()->id())->toBe($superAdmin->id);
});

it('does not lock the real super_admin out of the Super panel while impersonating a tenant user', function () {
    // S9 B14 review pass: the bare Super-panel host never binds
    // CurrentTenant at all, so the original mismatch-only skip condition
    // never fired there — the swap always applied, and the swapped-in
    // target (never a super_admin) then failed EnsureRole's own check,
    // locking the real actor out of the one panel they'd need to manage
    // impersonation from.
    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $superAdmin->assignRole('super_admin');

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($superAdmin);
    app(ImpersonationService::class)->start($superAdmin, $instructor);

    $this->get('http://lms.test/super')->assertOk();

    expect(auth()->id())->toBe($superAdmin->id);
});

it('does not apply the impersonated identity on a different tenants own subdomain', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $superAdmin->assignRole('super_admin');

    $physics = Tenant::factory()->create(['subdomain' => 'physics']);
    $chemistry = Tenant::factory()->create(['subdomain' => 'chemistry']);

    $physicsInstructor = User::factory()->create(['tenant_id' => $physics->id]);
    $physicsInstructor->assignRole('instructor');

    $chemistryAdmin = User::factory()->create(['tenant_id' => $chemistry->id]);
    $chemistryAdmin->assignRole('institute_admin');

    $this->actingAs($superAdmin);
    app(ImpersonationService::class)->start($superAdmin, $physicsInstructor);

    // A session cookie valid on chemistry.lms.test too (SESSION_DOMAIN is
    // shared) must not silently apply the physics-tenant impersonation
    // there — EnsureRole rejects the real actor (super_admin, no role
    // matching institute_admin) instead.
    $this->get('http://chemistry.lms.test/admin')->assertForbidden();
});

it('only lets a super_admin reach the impersonate page, same as every other Super panel route', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get('http://lms.test/super/impersonate-user')
        ->assertForbidden();
});

it('starts impersonation from the Filament page and redirects to the targets own panel', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $superAdmin->assignRole('super_admin');

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    $this->actingAs($superAdmin);

    Livewire::test(ImpersonateUser::class)
        ->set('data.target_user_id', $instructor->id)
        ->callAction('impersonate');

    expect(session('impersonating_user_id'))->toBe($instructor->id);
    expect(ImpersonationAudit::where('target_user_id', $instructor->id)->exists())->toBeTrue();
});

it('shows the impersonation banner only while impersonating, and stop ends the session', function () {
    $superAdmin = User::factory()->create(['tenant_id' => null]);
    $superAdmin->assignRole('super_admin');

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    app(CurrentTenant::class)->set($tenant);
    $this->actingAs($superAdmin);

    Livewire::test(\App\Livewire\ImpersonationBanner::class)
        ->assertDontSee('Stop impersonating');

    app(ImpersonationService::class)->start($superAdmin, $student);

    Livewire::test(\App\Livewire\ImpersonationBanner::class)
        ->assertSee('Stop impersonating')
        ->call('stop');

    expect(session()->has('impersonating_user_id'))->toBeFalse();
});
