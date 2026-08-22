<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Filament\Resources\InstructorInvitations\InstructorInvitationResource;
use App\Domains\Tenancy\Filament\Resources\InstructorInvitations\Pages\CreateInstructorInvitation;
use App\Domains\Tenancy\Models\InstructorInvitation;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\InstructorInvitationService;
use App\Domains\Tenancy\Services\TenantAuthenticator;
use App\Livewire\InstructorInvitationAccept;
use App\Models\User;
use App\Notifications\InstructorInvitationMail;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function makeBatch(Tenant $tenant): Batch
{
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid()]);

    return Batch::create([
        'tenant_id' => $tenant->id,
        'course_id' => $course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
}

it('invites an instructor with a 14-day expiry, stores assigned batches, and emails the token', function () {
    Notification::fake();

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');
    $batch = makeBatch($tenant);

    $invitation = app(InstructorInvitationService::class)->invite('teacher@example.com', [$batch->id], $admin);

    expect($invitation->tenant_id)->toBe($tenant->id);
    expect($invitation->status)->toBe('pending');
    expect($invitation->batch_ids)->toBe([$batch->id]);
    expect($invitation->expires_at->isSameDay(now()->addDays(14)))->toBeTrue();

    Notification::assertSentOnDemand(InstructorInvitationMail::class);
});

it('creates an invitation from the Filament admin page', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');
    $batch = makeBatch($tenant);

    $this->actingAs($admin)
        ->get(InstructorInvitationResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Notification::fake();

    Livewire::test(CreateInstructorInvitation::class)
        ->fillForm([
            'email' => 'new-teacher@example.com',
            'batch_ids' => [$batch->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(InstructorInvitation::where('email', 'new-teacher@example.com')->exists())->toBeTrue();
});

it('accepting a pending invitation creates the user, grants the instructor role, and assigns the batch', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');
    $batch = makeBatch($tenant);
    $invitation = app(InstructorInvitationService::class)->invite('teacher@example.com', [$batch->id], $admin);
    app(CurrentTenant::class)->clear();

    Livewire::test(InstructorInvitationAccept::class, ['token' => $invitation->token])
        ->set('name', 'Teacher One')
        ->set('password', 'secret-password')
        ->call('accept')
        ->assertHasNoErrors();

    $instructor = User::where('email', 'teacher@example.com')->firstOrFail();
    expect($instructor->tenant_id)->toBe($tenant->id);
    expect($instructor->hasRole('instructor'))->toBeTrue();
    expect($batch->fresh()->instructor_id)->toBe($instructor->id);
    expect($invitation->fresh()->status)->toBe('accepted');
    expect($invitation->fresh()->accepted_user_id)->toBe($instructor->id);
    $this->assertAuthenticatedAs($instructor);
});

it('accepting an invitation for an already-registered email links that account instead of duplicating it', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');
    $existing = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'teacher@example.com']);
    $existing->assignRole('instructor');
    $batch = makeBatch($tenant);
    $invitation = app(InstructorInvitationService::class)->invite('teacher@example.com', [$batch->id], $admin);
    app(CurrentTenant::class)->clear();

    Livewire::test(InstructorInvitationAccept::class, ['token' => $invitation->token])
        ->call('accept')
        ->assertHasNoErrors();

    expect(User::where('email', 'teacher@example.com')->count())->toBe(1);
    expect($batch->fresh()->instructor_id)->toBe($existing->id);
    $this->assertAuthenticatedAs($existing);
});

it('rejects an expired invitation and marks it expired', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');
    $batch = makeBatch($tenant);
    $invitation = app(InstructorInvitationService::class)->invite('teacher@example.com', [$batch->id], $admin);
    $invitation->forceFill(['expires_at' => now()->subDay()])->save();
    app(CurrentTenant::class)->clear();

    Livewire::test(InstructorInvitationAccept::class, ['token' => $invitation->token])
        ->set('name', 'Teacher One')
        ->set('password', 'secret-password')
        ->call('accept')
        ->assertHasErrors('token');

    expect($invitation->fresh()->status)->toBe('expired');
    $this->assertGuest();
});

it('rejects an already-accepted invitation on a second attempt', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole('institute_admin');
    $batch = makeBatch($tenant);
    $invitation = app(InstructorInvitationService::class)->invite('teacher@example.com', [$batch->id], $admin);

    app(InstructorInvitationService::class)->accept($invitation->token, [
        'name' => 'Teacher One',
        'password' => 'secret-password',
    ]);
    app(CurrentTenant::class)->clear();

    Livewire::test(InstructorInvitationAccept::class, ['token' => $invitation->token])
        ->call('accept')
        ->assertHasErrors('token');
});

it('rejects accepting when the invited email is already registered under a different tenant', function () {
    $tenantA = Tenant::factory()->create(['subdomain' => 'physics']);
    $tenantB = Tenant::factory()->create(['subdomain' => 'chemistry']);
    User::factory()->create(['tenant_id' => $tenantB->id, 'email' => 'teacher@example.com']);

    app(CurrentTenant::class)->set($tenantA);
    $admin = User::factory()->create(['tenant_id' => $tenantA->id]);
    $admin->assignRole('institute_admin');
    $batch = makeBatch($tenantA);
    $invitation = app(InstructorInvitationService::class)->invite('teacher@example.com', [$batch->id], $admin);
    app(CurrentTenant::class)->clear();

    expect(fn () => app(InstructorInvitationService::class)->accept($invitation->token, [
        'name' => 'Teacher One',
        'password' => 'secret-password',
    ]))->toThrow(ValidationException::class);
});

it('deactivating an instructor blocks tenant login; reactivating restores it', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $instructor->assignRole('instructor');

    app(InstructorInvitationService::class)->deactivate($instructor);
    expect($instructor->fresh()->is_active)->toBeFalse();

    expect(fn () => app(TenantAuthenticator::class)->validate($instructor->email, 'password', $tenant->id, '127.0.0.1'))
        ->toThrow(ValidationException::class);

    app(InstructorInvitationService::class)->reactivate($instructor);
    expect($instructor->fresh()->is_active)->toBeTrue();

    $authenticated = app(TenantAuthenticator::class)->validate($instructor->email, 'password', $tenant->id, '127.0.0.1');
    expect($authenticated->id)->toBe($instructor->id);
});
