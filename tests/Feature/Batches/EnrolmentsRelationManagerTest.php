<?php

use App\Domains\Batches\Filament\Resources\Batches\BatchResource;
use App\Domains\Batches\Filament\Resources\Batches\Pages\EditBatch;
use App\Domains\Batches\Filament\Resources\Batches\RelationManagers\EnrolmentsRelationManager;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\EnrolmentApprovalDeniedException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);

    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'self_enrol_approval',
        'status' => 'published',
    ]);

    $this->actingAs($this->admin)
        ->get(BatchResource::getUrl('edit', ['record' => $this->batch, 'subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('adds a student manually via the relation manager, landing active', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    Livewire::test(EnrolmentsRelationManager::class, [
        'ownerRecord' => $this->batch,
        'pageClass' => EditBatch::class,
    ])->callTableAction('create', data: ['user_id' => $student->id])
        ->assertHasNoTableActionErrors();

    $enrolment = Enrolment::where('batch_id', $this->batch->id)->where('user_id', $student->id)->first();

    expect($enrolment)->not->toBeNull()
        ->and($enrolment->status)->toBe('active')
        ->and($enrolment->source)->toBe('manual_add');
});

it('approves a pending_approval enrolment as the institute_admin', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $enrolment = Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $student->id,
        'status' => 'pending_approval',
        'source' => 'self_enrol_approval',
        'requested_at' => now(),
        'expires_at' => now()->addDays(7),
    ]);

    Livewire::test(EnrolmentsRelationManager::class, [
        'ownerRecord' => $this->batch,
        'pageClass' => EditBatch::class,
    ])->callTableAction('approve', $enrolment);

    expect($enrolment->fresh()->status)->toBe('active')
        ->and($enrolment->fresh()->decided_by)->toBe($this->admin->id);
});

it('rejects a pending_approval enrolment as the institute_admin', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $enrolment = Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $student->id,
        'status' => 'pending_approval',
        'source' => 'self_enrol_approval',
        'requested_at' => now(),
        'expires_at' => now()->addDays(7),
    ]);

    Livewire::test(EnrolmentsRelationManager::class, [
        'ownerRecord' => $this->batch,
        'pageClass' => EditBatch::class,
    ])->callTableAction('reject', $enrolment);

    expect($enrolment->fresh()->status)->toBe('rejected');
});

it('cancels an active enrolment via the relation manager, promoting the waitlisted student', function () {
    $activeStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $activeStudent->assignRole('student');
    $waitlistedStudent = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $waitlistedStudent->assignRole('student');

    $active = Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $activeStudent->id,
        'status' => 'active',
        'source' => 'self_enrol_direct',
        'requested_at' => now(),
    ]);
    $waitlisted = Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $waitlistedStudent->id,
        'status' => 'waitlisted',
        'source' => 'self_enrol_direct',
        'requested_at' => now(),
    ]);

    Livewire::test(EnrolmentsRelationManager::class, [
        'ownerRecord' => $this->batch,
        'pageClass' => EditBatch::class,
    ])->callTableAction('cancel', $active);

    expect($active->fresh()->status)->toBe('cancelled')
        ->and($waitlisted->fresh()->status)->toBe('active');
});

it('denies adding a student manually from an actor in a different tenant', function () {
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $outsider = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $outsider->assignRole('instructor');

    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $this->actingAs($outsider);

    expect(fn () => Livewire::test(EnrolmentsRelationManager::class, [
        'ownerRecord' => $this->batch,
        'pageClass' => EditBatch::class,
    ])->callTableAction('create', data: ['user_id' => $student->id]))
        ->toThrow(EnrolmentApprovalDeniedException::class);

    expect(Enrolment::where('batch_id', $this->batch->id)->where('user_id', $student->id)->count())->toBe(0);
});

it('denies approval from an instructor in a different tenant', function () {
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $outsider = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $outsider->assignRole('instructor');

    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $enrolment = Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $student->id,
        'status' => 'pending_approval',
        'source' => 'self_enrol_approval',
        'requested_at' => now(),
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($outsider);

    expect(fn () => Livewire::test(EnrolmentsRelationManager::class, [
        'ownerRecord' => $this->batch,
        'pageClass' => EditBatch::class,
    ])->callTableAction('approve', $enrolment))->toThrow(EnrolmentApprovalDeniedException::class);

    expect($enrolment->fresh()->status)->toBe('pending_approval');
});
