<?php

use App\Domains\Assignments\Jobs\ExportGradebookExcel;
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
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

function gradebookExcelStudent(Tenant $tenant, string $name = 'Ada Lovelace'): User
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

it('generates a real xlsx file with the header row and a student row', function () {
    $student = gradebookExcelStudent($this->tenant);

    ExportGradebookExcel::dispatch($this->tenant->id);

    $path = Storage::disk('local')->path(ExportGradebookExcel::pathFor($this->tenant->id));
    expect(file_exists($path))->toBeTrue();

    $sheet = IOFactory::load($path)->getActiveSheet();

    expect($sheet->getCell('A1')->getValue())->toBe('Student name')
        ->and($sheet->getCell('A2')->getValue())->toBe($student->name);
});

it('escapes a student name that would otherwise become a live spreadsheet formula', function () {
    gradebookExcelStudent($this->tenant, '=SUM(1+1)');

    ExportGradebookExcel::dispatch($this->tenant->id);

    $path = Storage::disk('local')->path(ExportGradebookExcel::pathFor($this->tenant->id));
    $sheet = IOFactory::load($path)->getActiveSheet();

    expect($sheet->getCell('A2')->getValue())->toBe("'=SUM(1+1)");
});

it('only exports the requested tenants own students', function () {
    gradebookExcelStudent($this->tenant, 'Own Tenant Student');

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    gradebookExcelStudent($otherTenant, 'Other Tenant Student');
    app(CurrentTenant::class)->set($this->tenant);

    ExportGradebookExcel::dispatch($this->tenant->id);

    $path = Storage::disk('local')->path(ExportGradebookExcel::pathFor($this->tenant->id));
    $sheet = IOFactory::load($path)->getActiveSheet();

    expect($sheet->getCell('A2')->getValue())->toBe('Own Tenant Student')
        ->and($sheet->getCell('A3')->getValue())->toBeNull();
});

it('has retry configured, matching every other export job in this slice', function () {
    $job = new ExportGradebookExcel(tenant_id: null);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900]);
});
