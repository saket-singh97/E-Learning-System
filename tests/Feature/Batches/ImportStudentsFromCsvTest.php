<?php

use App\Domains\Batches\Jobs\ImportStudentsFromCsv;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Models\StudentImportBatch;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a',
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $this->importBatch = StudentImportBatch::create([
        'uploaded_by_user_id' => $this->admin->id,
        'status' => 'pending',
        'total_rows' => 2,
        'error_count' => 0,
    ]);
});

it('creates a new student, grants the student role, and enrols them', function () {
    ImportStudentsFromCsv::dispatchSync($this->tenant->id, $this->importBatch->id, [
        ['row' => 2, 'name' => 'Asha Kumar', 'email' => 'asha@example.com', 'phone' => null, 'batch_id' => $this->batch->id],
    ], []);

    $student = User::where('email', 'asha@example.com')->firstOrFail();
    expect($student->tenant_id)->toBe($this->tenant->id);
    expect($student->hasRole('student'))->toBeTrue();
    expect(Enrolment::where('batch_id', $this->batch->id)->where('user_id', $student->id)->exists())->toBeTrue();

    expect($this->importBatch->fresh()->status)->toBe('done');
    expect($this->importBatch->fresh()->success_count)->toBe(1);
    expect($this->importBatch->fresh()->error_count)->toBe(0);
});

it('links an existing same-tenant user by email instead of duplicating', function () {
    $existing = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'asha@example.com']);

    ImportStudentsFromCsv::dispatchSync($this->tenant->id, $this->importBatch->id, [
        ['row' => 2, 'name' => 'Asha Kumar', 'email' => 'asha@example.com', 'phone' => null, 'batch_id' => $this->batch->id],
    ], []);

    expect(User::where('email', 'asha@example.com')->count())->toBe(1);
    expect(Enrolment::where('batch_id', $this->batch->id)->where('user_id', $existing->id)->exists())->toBeTrue();
});

it('errors a row whose email is already registered under a different tenant', function () {
    $otherTenant = Tenant::factory()->create();
    User::factory()->create(['tenant_id' => $otherTenant->id, 'email' => 'asha@example.com']);

    ImportStudentsFromCsv::dispatchSync($this->tenant->id, $this->importBatch->id, [
        ['row' => 2, 'name' => 'Asha Kumar', 'email' => 'asha@example.com', 'phone' => null, 'batch_id' => $this->batch->id],
    ], []);

    $fresh = $this->importBatch->fresh();
    expect($fresh->success_count)->toBe(0);
    expect($fresh->error_count)->toBe(1);
    expect(Storage::disk('local')->exists($fresh->error_report_disk_path))->toBeTrue();
    expect(Storage::disk('local')->get($fresh->error_report_disk_path))->toContain('different institute');
});

it('combines phase-1 validation errors with phase-2 failures in one report, sorted by row', function () {
    ImportStudentsFromCsv::dispatchSync($this->tenant->id, $this->importBatch->id, [
        ['row' => 4, 'name' => 'Valid Student', 'email' => 'valid@example.com', 'phone' => null, 'batch_id' => $this->batch->id],
    ], [
        ['row' => 2, 'message' => 'Name is required.'],
        ['row' => 3, 'message' => 'A valid email is required.'],
    ]);

    $fresh = $this->importBatch->fresh();
    expect($fresh->success_count)->toBe(1);
    expect($fresh->error_count)->toBe(2);

    $report = Storage::disk('local')->get($fresh->error_report_disk_path);
    expect($report)->toContain('Name is required');
    expect($report)->toContain('A valid email is required');

    $rowTwoLine = strpos($report, '2,');
    $rowThreeLine = strpos($report, '3,');
    expect($rowTwoLine)->toBeLessThan($rowThreeLine);
});
