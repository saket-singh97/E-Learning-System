<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Models\EnrolmentImportBatch;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Domains\Batches\Services\EnrolmentCsvImporter;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\InvalidEnrolmentImportFileException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
    $this->uploader = User::factory()->create(['tenant_id' => $this->tenant->id]);

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
    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'asha@example.com']);

    $this->importer = app(EnrolmentCsvImporter::class);
});

it('rejects a file with no data rows', function () {
    expect(fn () => $this->importer->import("email,batch\n", $this->uploader, true))
        ->toThrow(InvalidEnrolmentImportFileException::class);
});

it('rejects a file missing a required header', function () {
    expect(fn () => $this->importer->import("email\nasha@example.com\n", $this->uploader, true))
        ->toThrow(InvalidEnrolmentImportFileException::class);
});

it('dry run catches a reference to a non-existent student or batch, with zero writes', function () {
    $csv = "email,batch\n"
        ."asha@example.com,batch-a\n" // valid
        ."ghost@example.com,batch-a\n" // no such student
        ."asha@example.com,no-such-batch\n"; // no such batch

    $batch = $this->importer->import($csv, $this->uploader, true);

    expect($batch->status)->toBe('dry_run')
        ->and($batch->total_rows)->toBe(3)
        ->and($batch->error_rows)->toBe(2)
        ->and($batch->error_report_path)->not->toBeNull();

    expect(Enrolment::count())->toBe(0);
});

it('a real run creates valid enrolments only, skipping bad rows', function () {
    $ghostBatchRow = 'no-such-batch';
    $csv = "email,batch\n"
        ."asha@example.com,{$this->batch->slug}\n"
        ."ghost@example.com,{$this->batch->slug}\n"
        ."asha@example.com,{$ghostBatchRow}\n";

    $result = $this->importer->import($csv, $this->uploader, false);

    expect($result->status)->toBe('completed')
        ->and($result->total_rows)->toBe(3)
        ->and($result->error_rows)->toBe(2);

    expect(Enrolment::where('batch_id', $this->batch->id)->where('user_id', $this->student->id)->exists())->toBeTrue()
        ->and(Enrolment::count())->toBe(1);
});

it('catches a duplicate email+batch pair within the same file', function () {
    $csv = "email,batch\n"
        ."asha@example.com,{$this->batch->slug}\n"
        ."asha@example.com,{$this->batch->slug}\n";

    $batch = $this->importer->import($csv, $this->uploader, true);

    expect($batch->error_rows)->toBe(1);
});

it('reuses an existing enrolment instead of erroring or duplicating it', function () {
    app(BatchEnrolmentService::class)->addManually($this->batch, $this->student, $this->uploader);

    $csv = "email,batch\nasha@example.com,{$this->batch->slug}\n";
    $result = $this->importer->import($csv, $this->uploader, false);

    expect($result->error_rows)->toBe(0)
        ->and(Enrolment::count())->toBe(1);
});
