<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\StudentImportBatch;
use App\Domains\Batches\Services\StudentCsvImporter;
use App\Domains\Content\Models\Course;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\InvalidStudentImportFileException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
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
    $this->importer = new StudentCsvImporter;
});

it('parses a valid CSV, resolving each row batch to a real Batch', function () {
    $csv = "name,email,phone,batch\nAsha Kumar,asha@example.com,+919876543210,batch-a\nRavi Shah,ravi@example.com,,batch-a";

    $result = $this->importer->parse($csv, $this->admin);

    expect($result['importBatch']->total_rows)->toBe(2);
    expect($result['importBatch']->error_count)->toBe(0);
    expect($result['importBatch']->status)->toBe('pending');
    expect($result['errors'])->toBe([]);
    expect($result['valid'])->toHaveCount(2);
    expect($result['valid'][0]['batch_id'])->toBe($this->batch->id);
    expect($result['valid'][0]['email'])->toBe('asha@example.com');
    expect($result['valid'][1]['phone'])->toBeNull();
});

it('refuses a file with the wrong headers before writing any row', function () {
    $csv = "full_name,email\nAsha,asha@example.com";

    expect(fn () => $this->importer->parse($csv, $this->admin))
        ->toThrow(InvalidStudentImportFileException::class);

    expect(StudentImportBatch::count())->toBe(0);
});

it('refuses a file with no data rows', function () {
    $csv = "name,email,phone,batch\n";

    expect(fn () => $this->importer->parse($csv, $this->admin))
        ->toThrow(InvalidStudentImportFileException::class);
});

it('collects per-row errors without refusing the whole file', function () {
    $csv = implode("\n", [
        'name,email,phone,batch',
        ',missing-name@example.com,,batch-a',
        'Bad Email,not-an-email,,batch-a',
        'Unknown Batch,unknown@example.com,,no-such-batch',
        'Asha Kumar,asha@example.com,,batch-a',
        'Duplicate,asha@example.com,,batch-a',
    ]);

    $result = $this->importer->parse($csv, $this->admin);

    expect($result['importBatch']->total_rows)->toBe(5);
    expect($result['importBatch']->error_count)->toBe(4);
    expect($result['valid'])->toHaveCount(1);
    expect($result['valid'][0]['email'])->toBe('asha@example.com');

    $messages = array_column($result['errors'], 'message');
    expect($messages[0])->toContain('Name is required');
    expect($messages[1])->toContain('valid email');
    expect($messages[2])->toContain('No batch found');
    expect($messages[3])->toContain('Duplicate email');
});
