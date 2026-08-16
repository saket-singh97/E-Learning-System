<?php

use App\Domains\Assignments\Jobs\ExportGradebookPdf;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
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

function gradebookPdfStudent(Tenant $tenant, string $name = 'Ada Lovelace'): User
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
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    return $student;
}

it('generates a real pdf file', function () {
    gradebookPdfStudent($this->tenant);

    ExportGradebookPdf::dispatch($this->tenant->id);

    $path = ExportGradebookPdf::pathFor($this->tenant->id);

    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $bytes = Storage::disk('local')->get($path);

    // The PDF magic number — confirms dompdf actually rendered a real
    // document, not just wrote an empty or placeholder file.
    expect(substr($bytes, 0, 4))->toBe('%PDF');
});

it('has retry configured, matching every other export job in this slice', function () {
    $job = new ExportGradebookPdf(tenant_id: null);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900]);
});
