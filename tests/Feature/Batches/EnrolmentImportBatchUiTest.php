<?php

use App\Domains\Batches\Filament\Resources\EnrolmentImportBatches\EnrolmentImportBatchResource;
use App\Domains\Batches\Filament\Resources\EnrolmentImportBatches\Pages\CreateEnrolmentImportBatch;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Models\EnrolmentImportBatch;
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
    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'asha@example.com']);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $this->actingAs($this->admin)
        ->get(EnrolmentImportBatchResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('uploads a CSV as a real run and creates the enrolment', function () {
    $tempPath = 'enrolment-imports/tmp/enrolments.csv';
    Storage::disk('local')->put($tempPath, "email,batch\nasha@example.com,batch-a\n");

    Livewire::test(CreateEnrolmentImportBatch::class)
        ->fillForm(['csv' => [$tempPath], 'dry_run' => false])
        ->call('create')
        ->assertHasNoFormErrors();

    $batch = EnrolmentImportBatch::sole();
    expect($batch->status)->toBe('completed');
    expect(Enrolment::where('batch_id', $this->batch->id)->where('user_id', $this->student->id)->exists())->toBeTrue();
});

it('uploads a CSV as a dry run and creates nothing', function () {
    $tempPath = 'enrolment-imports/tmp/enrolments.csv';
    Storage::disk('local')->put($tempPath, "email,batch\nasha@example.com,batch-a\n");

    Livewire::test(CreateEnrolmentImportBatch::class)
        ->fillForm(['csv' => [$tempPath], 'dry_run' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(EnrolmentImportBatch::sole()->status)->toBe('dry_run');
    expect(Enrolment::count())->toBe(0);
});

it('downloads the error report only for the tenant that owns it', function () {
    $tempPath = 'enrolment-imports/tmp/enrolments.csv';
    Storage::disk('local')->put($tempPath, "email,batch\nghost@example.com,batch-a\n");

    Livewire::test(CreateEnrolmentImportBatch::class)
        ->fillForm(['csv' => [$tempPath], 'dry_run' => true])
        ->call('create');

    $batch = EnrolmentImportBatch::sole();

    $this->actingAs($this->admin)
        ->get("http://physics.lms.test/enrolment-imports/{$batch->id}/error-report")
        ->assertOk();

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $otherAdmin = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherAdmin->assignRole('institute_admin');

    $this->actingAs($otherAdmin)
        ->get("http://physics.lms.test/enrolment-imports/{$batch->id}/error-report")
        ->assertForbidden();
});
