<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Teach\Pages\StudentProgress;
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

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $this->actingAs($this->instructor)
        ->get(StudentProgress::getUrl(['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();
});

function enrolStudent(Tenant $tenant, string $status = 'active'): User
{
    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => $status,
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    return $student;
}

it('lists actively enrolled students in the current tenant', function () {
    $active = enrolStudent($this->tenant, 'active');

    Livewire::test(StudentProgress::class)
        ->assertCanSeeTableRecords([$active]);
});

it('excludes a student with no active enrolment', function () {
    $active = enrolStudent($this->tenant, 'active');
    $waitlisted = enrolStudent($this->tenant, 'waitlisted');

    Livewire::test(StudentProgress::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$waitlisted]);
});

it('excludes a student actively enrolled in a different tenant', function () {
    $active = enrolStudent($this->tenant, 'active');

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    $otherStudent = enrolStudent($otherTenant, 'active');
    app(CurrentTenant::class)->set($this->tenant);

    Livewire::test(StudentProgress::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$otherStudent]);
});

it('shows a placeholder rather than crashing for a student with no metrics data yet', function () {
    $student = enrolStudent($this->tenant, 'active');

    Livewire::test(StudentProgress::class)
        ->assertSee($student->name)
        ->assertSee('—');
});

it('shows the fee status for a student with an overdue invoice', function () {
    $student = enrolStudent($this->tenant, 'active');

    $course = Course::create(['title' => 'Fees Course', 'slug' => 'fees-course-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Fees Batch',
        'slug' => 'fees-batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
        'is_paid' => true,
        'price' => 100,
    ]);
    $enrolment = Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);
    FeeInvoice::create([
        'batch_id' => $batch->id,
        'enrolment_id' => $enrolment->id,
        'user_id' => $student->id,
        'sequence_number' => 1,
        'invoice_number' => 'INV-PROGRESS-TEST',
        'description' => 'Fee',
        'amount' => 100,
        'gst_amount' => 18,
        'total_amount' => 118,
        'due_date' => now()->subDays(3)->toDateString(),
        'status' => 'overdue',
    ]);

    Livewire::test(StudentProgress::class)
        ->assertSee($student->name)
        ->assertSee('Overdue');
});
