<?php

use App\Domains\Batches\Filament\Resources\Batches\BatchResource;
use App\Domains\Batches\Filament\Resources\Batches\Pages\EditBatch;
use App\Domains\Batches\Filament\Resources\Batches\Pages\ListBatches;
use App\Domains\Batches\Filament\Resources\Batches\RelationManagers\EnrolmentsRelationManager;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
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

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);

    $this->instructorA = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructorA->assignRole('instructor');
    $this->instructorB = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructorB->assignRole('instructor');

    $this->batchA = Batch::create([
        'course_id' => $course->id,
        'instructor_id' => $this->instructorA->id,
        'title' => 'Batch A',
        'slug' => 'batch-a',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $this->batchB = Batch::create([
        'course_id' => $course->id,
        'instructor_id' => $this->instructorB->id,
        'title' => 'Batch B',
        'slug' => 'batch-b',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
});

it('lists only the instructors own assigned batch in the Teach panel', function () {
    $this->actingAs($this->instructorA)
        ->get(BatchResource::getUrl('index', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    Livewire::test(ListBatches::class)
        ->assertCanSeeTableRecords([$this->batchA])
        ->assertCanNotSeeTableRecords([$this->batchB]);
});

it('404s an instructor trying to reach another instructors batch edit page', function () {
    $this->actingAs($this->instructorA)
        ->get(BatchResource::getUrl('edit', ['record' => $this->batchB, 'subdomain' => 'physics'], panel: 'teach'))
        ->assertNotFound();
});

it('still lets an institute_admin see every batch regardless of instructor_id', function () {
    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $admin->assignRole('institute_admin');

    $this->actingAs($admin)
        ->get(BatchResource::getUrl('index', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();

    Livewire::test(ListBatches::class)
        ->assertCanSeeTableRecords([$this->batchA, $this->batchB]);
});

it('forbids an instructor from creating a batch in the Teach panel', function () {
    $this->actingAs($this->instructorA)
        ->get(BatchResource::getUrl('create', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertForbidden();
});

it('lets the assigned instructor approve an enrolment for their own batch', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $enrolment = Enrolment::create([
        'batch_id' => $this->batchA->id,
        'user_id' => $student->id,
        'status' => 'pending_approval',
        'source' => 'self_enrol_approval',
        'requested_at' => now(),
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($this->instructorA)
        ->get(BatchResource::getUrl('edit', ['record' => $this->batchA, 'subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    Livewire::test(EnrolmentsRelationManager::class, [
        'ownerRecord' => $this->batchA,
        'pageClass' => EditBatch::class,
    ])->callTableAction('approve', $enrolment);

    expect($enrolment->fresh()->status)->toBe('active');
});
