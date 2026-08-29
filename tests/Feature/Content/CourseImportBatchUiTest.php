<?php

use App\Domains\Content\Filament\Resources\CourseImportBatches\CourseImportBatchResource;
use App\Domains\Content\Filament\Resources\CourseImportBatches\Pages\CreateCourseImportBatch;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseImportBatch;
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

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->admin->assignRole('institute_admin');

    $this->actingAs($this->admin)
        ->get(CourseImportBatchResource::getUrl('create', ['subdomain' => 'physics'], panel: 'admin'))
        ->assertOk();
});

it('uploads a CSV as a real run and creates the course', function () {
    $tempPath = 'course-imports/tmp/courses.csv';
    Storage::disk('local')->put($tempPath, "title,slug,category\nPhysics 101,physics-101,Science\n");

    Livewire::test(CreateCourseImportBatch::class)
        ->fillForm(['csv' => [$tempPath], 'dry_run' => false])
        ->call('create')
        ->assertHasNoFormErrors();

    $batch = CourseImportBatch::sole();
    expect($batch->status)->toBe('completed');
    expect(Course::where('slug', 'physics-101')->exists())->toBeTrue();
});

it('uploads a CSV as a dry run and creates nothing', function () {
    $tempPath = 'course-imports/tmp/courses.csv';
    Storage::disk('local')->put($tempPath, "title,slug,category\nPhysics 101,physics-101,Science\n");

    Livewire::test(CreateCourseImportBatch::class)
        ->fillForm(['csv' => [$tempPath], 'dry_run' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $batch = CourseImportBatch::sole();
    expect($batch->status)->toBe('dry_run');
    expect(Course::count())->toBe(0);
});

it('downloads the error report only for the tenant that owns it', function () {
    $tempPath = 'course-imports/tmp/courses.csv';
    Storage::disk('local')->put($tempPath, "title,slug,category\n,no-title,\n");

    Livewire::test(CreateCourseImportBatch::class)
        ->fillForm(['csv' => [$tempPath], 'dry_run' => true])
        ->call('create');

    $batch = CourseImportBatch::sole();

    $this->actingAs($this->admin)
        ->get("http://physics.lms.test/course-imports/{$batch->id}/error-report")
        ->assertOk();

    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    $otherAdmin = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherAdmin->assignRole('institute_admin');

    // Same shape as DownloadStudentImportErrorReportControllerTest's own
    // cross-tenant case: hits the *original* tenant's subdomain (so
    // CurrentTenant resolves to physics, the batch's real owner) as an
    // admin whose own tenant_id is chemistry — the controller's explicit
    // tenant_id check is what catches this, not BelongsToTenant's global
    // scope (which would 404, not 403, for a genuinely different subdomain).
    $this->actingAs($otherAdmin)
        ->get("http://physics.lms.test/course-imports/{$batch->id}/error-report")
        ->assertForbidden();
});
