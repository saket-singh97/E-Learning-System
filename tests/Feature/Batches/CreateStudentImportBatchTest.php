<?php

use App\Domains\Batches\Filament\Resources\StudentImportBatches\Pages\CreateStudentImportBatch;
use App\Domains\Batches\Filament\Resources\StudentImportBatches\StudentImportBatchResource;
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
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
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

    $this->actingAs($this->admin)
        ->get(StudentImportBatchResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('uploads a CSV and processes it end to end through the queued job', function () {
    // UploadedFile::fake() writes no real bytes into a fake disk (same
    // gotcha LessonContentTypesTest's own comment documents) — write the
    // real content directly to the temp path a real FileUpload field
    // would have already stored it at, then hand that path to fillForm().
    $tempPath = 'student-imports/tmp/students.csv';
    Storage::disk('local')->put($tempPath, "name,email,phone,batch\nAsha Kumar,asha@example.com,,batch-a");

    Livewire::test(CreateStudentImportBatch::class)
        ->fillForm(['csv' => [$tempPath]])
        ->call('create')
        ->assertHasNoFormErrors();

    $importBatch = StudentImportBatch::sole();
    expect($importBatch->status)->toBe('done');
    expect($importBatch->total_rows)->toBe(1);
    expect($importBatch->success_count)->toBe(1);

    $student = User::where('email', 'asha@example.com')->firstOrFail();
    expect(Enrolment::where('batch_id', $this->batch->id)->where('user_id', $student->id)->exists())->toBeTrue();
});
