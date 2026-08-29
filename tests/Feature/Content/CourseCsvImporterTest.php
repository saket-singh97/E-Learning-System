<?php

use App\Domains\Content\Models\Category;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseImportBatch;
use App\Domains\Content\Services\CourseCsvImporter;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\InvalidCourseImportFileException;
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
    $this->importer = app(CourseCsvImporter::class);
});

it('rejects a file with no data rows', function () {
    expect(fn () => $this->importer->import("title,slug,category\n", $this->uploader, true))
        ->toThrow(InvalidCourseImportFileException::class);
});

it('rejects a file missing a required header', function () {
    expect(fn () => $this->importer->import("title,slug\nPhysics,physics,\n", $this->uploader, true))
        ->toThrow(InvalidCourseImportFileException::class);
});

it('dry run reports row-level errors and writes zero courses or categories', function () {
    Course::create(['title' => 'Existing', 'slug' => 'existing']);

    $csv = "title,slug,category\n"
        ."Physics 101,physics-101,Science\n" // valid
        .",no-title,\n" // missing title
        ."Duplicate Slug,physics-101,Science\n" // duplicate slug in-file
        ."Already Used,existing,Science\n"; // slug already in DB

    $batch = $this->importer->import($csv, $this->uploader, true);

    expect($batch->status)->toBe('dry_run')
        ->and($batch->dry_run)->toBeTrue()
        ->and($batch->total_rows)->toBe(4)
        ->and($batch->error_rows)->toBe(3)
        ->and($batch->error_report_path)->not->toBeNull();

    // Zero writes: only the pre-existing "Existing" course, no new rows.
    expect(Course::count())->toBe(1)
        ->and(Category::count())->toBe(0);

    Storage::disk('local')->assertExists($batch->error_report_path);
});

it('a real run creates courses and categories, skipping only the bad rows', function () {
    $csv = "title,slug,category\n"
        ."Physics 101,physics-101,Science\n"
        .",no-title,\n"
        ."Chemistry 101,chemistry-101,Science\n";

    $batch = $this->importer->import($csv, $this->uploader, false);

    expect($batch->status)->toBe('completed')
        ->and($batch->dry_run)->toBeFalse()
        ->and($batch->total_rows)->toBe(3)
        ->and($batch->error_rows)->toBe(1);

    expect(Course::count())->toBe(2)
        ->and(Course::where('slug', 'physics-101')->exists())->toBeTrue()
        ->and(Course::where('slug', 'chemistry-101')->exists())->toBeTrue()
        ->and(Course::where('slug', 'no-title')->exists())->toBeFalse();

    $science = Category::where('slug', 'science')->sole();
    expect($science->courses()->count())->toBe(2);
});

it('creates a course with no category when the column is blank', function () {
    $csv = "title,slug,category\nStandalone,standalone,\n";

    $this->importer->import($csv, $this->uploader, false);

    $course = Course::where('slug', 'standalone')->sole();
    expect($course->categories()->count())->toBe(0);
});

it('writes no error report when every row is valid', function () {
    $csv = "title,slug,category\nPhysics 101,physics-101,\n";

    $batch = $this->importer->import($csv, $this->uploader, false);

    expect($batch->error_report_path)->toBeNull();
});
