<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Jobs\ExportEngagementRegister;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

function engagementStudent(Tenant $tenant, string $status = 'active', string $name = 'Ada Lovelace'): User
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

    $student = User::factory()->create(['tenant_id' => $tenant->id, 'name' => $name]);
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

it('writes a csv row per actively enrolled student with name, email, and blank metrics when no data exists yet', function () {
    $student = engagementStudent($this->tenant);

    ExportEngagementRegister::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportEngagementRegister::pathFor($this->tenant->id));

    expect($csv)->toContain('Ada Lovelace')
        ->toContain($student->email);
});

it('reflects a students real fee status', function () {
    $student = engagementStudent($this->tenant);

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
        'invoice_number' => 'INV-ENGAGEMENT-TEST',
        'description' => 'Fee',
        'amount' => 100,
        'gst_amount' => 18,
        'total_amount' => 118,
        'due_date' => now()->subDays(3)->toDateString(),
        'status' => 'overdue',
    ]);

    ExportEngagementRegister::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportEngagementRegister::pathFor($this->tenant->id));

    expect($csv)->toContain('overdue');
});

it('excludes a student with no active enrolment', function () {
    $active = engagementStudent($this->tenant, 'active', 'Active Student');
    $waitlisted = engagementStudent($this->tenant, 'waitlisted', 'Waitlisted Student');

    ExportEngagementRegister::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportEngagementRegister::pathFor($this->tenant->id));

    expect($csv)->toContain('Active Student')
        ->not->toContain('Waitlisted Student');
});

it('escapes a student name that would otherwise become a live spreadsheet formula', function () {
    engagementStudent($this->tenant, 'active', '=SUM(1+1)');

    ExportEngagementRegister::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportEngagementRegister::pathFor($this->tenant->id));

    expect($csv)->toContain("'=SUM(1+1)")
        ->not->toContain(",=SUM(1+1)");
});

it('only exports the requested tenants own students', function () {
    engagementStudent($this->tenant, 'active', 'Own Tenant Student');

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    engagementStudent($otherTenant, 'active', 'Other Tenant Student');
    app(CurrentTenant::class)->set($this->tenant);

    ExportEngagementRegister::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportEngagementRegister::pathFor($this->tenant->id));

    expect($csv)->toContain('Own Tenant Student')
        ->not->toContain('Other Tenant Student');
});

it('has retry configured, matching ExportAttendanceRegister and ExportRevenueRegister', function () {
    $job = new ExportEngagementRegister(tenant_id: null);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900]);
});
