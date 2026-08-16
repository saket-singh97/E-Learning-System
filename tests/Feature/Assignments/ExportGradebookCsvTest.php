<?php

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assignments\Jobs\ExportGradebookCsv;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
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

function gradebookExportStudent(Tenant $tenant, string $status = 'active', string $name = 'Ada Lovelace'): array
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

    return ['course' => $course, 'student' => $student, 'batch' => $batch];
}

it('writes a csv row per actively enrolled student with course name and blank scores when no data exists yet', function () {
    ['course' => $course, 'student' => $student] = gradebookExportStudent($this->tenant);

    ExportGradebookCsv::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportGradebookCsv::pathFor($this->tenant->id));

    expect($csv)->toContain('Ada Lovelace')
        ->toContain($student->email)
        ->toContain($course->title)
        // Not "On track" — a student with nothing graded yet must not
        // read as passing (real bug this exact test caught: standingFor()
        // originally collapsed "no data" into "Failing" instead).
        ->toContain('No data yet')
        ->not->toContain('Failing')
        ->not->toContain('On track');
});

it('reflects a real weighted score and standing', function () {
    ['course' => $course, 'student' => $student] = gradebookExportStudent($this->tenant);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $quizLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz', 'type' => 'quiz', 'position' => 0]);
    $quiz = Quiz::create(['lesson_id' => $quizLesson->id, 'title' => 'Checkpoint', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 3]);

    QuizAttempt::create([
        'quiz_id' => $quiz->id, 'user_id' => $student->id, 'question_ids' => [],
        'status' => 'submitted', 'started_at' => now(), 'expires_at' => now()->addMinutes(20),
        'submitted_at' => now(), 'score' => 3, 'max_score' => 10,
    ]);

    ExportGradebookCsv::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportGradebookCsv::pathFor($this->tenant->id));

    expect($csv)->toContain('30')
        ->toContain('Failing');
});

it('excludes a student with no active enrolment', function () {
    ['student' => $active] = gradebookExportStudent($this->tenant, 'active', 'Active Student');
    gradebookExportStudent($this->tenant, 'waitlisted', 'Waitlisted Student');

    ExportGradebookCsv::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportGradebookCsv::pathFor($this->tenant->id));

    expect($csv)->toContain('Active Student')
        ->not->toContain('Waitlisted Student');
});

it('escapes a student name that would otherwise become a live spreadsheet formula', function () {
    gradebookExportStudent($this->tenant, 'active', '=SUM(1+1)');

    ExportGradebookCsv::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportGradebookCsv::pathFor($this->tenant->id));

    expect($csv)->toContain("'=SUM(1+1)")
        ->not->toContain(",=SUM(1+1)");
});

it('only exports the requested tenants own students', function () {
    gradebookExportStudent($this->tenant, 'active', 'Own Tenant Student');

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    gradebookExportStudent($otherTenant, 'active', 'Other Tenant Student');
    app(CurrentTenant::class)->set($this->tenant);

    ExportGradebookCsv::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportGradebookCsv::pathFor($this->tenant->id));

    expect($csv)->toContain('Own Tenant Student')
        ->not->toContain('Other Tenant Student');
});

it('has retry configured, matching every other S9/S10 export job', function () {
    $job = new ExportGradebookCsv(tenant_id: null);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900]);
});

it('excludes an enrolment whose batch was soft-deleted instead of crashing the export', function () {
    // S10 B15 review pass — same BuildsGradebookRows fix the sweep job and
    // both Gradebook Filament pages also needed.
    ['student' => $orphaned, 'batch' => $orphanedBatch] = gradebookExportStudent($this->tenant, 'active', 'Orphaned Student');
    ['student' => $normal] = gradebookExportStudent($this->tenant, 'active', 'Normal Student');

    $orphanedBatch->delete();

    ExportGradebookCsv::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportGradebookCsv::pathFor($this->tenant->id));

    expect($csv)->toContain('Normal Student')
        ->not->toContain('Orphaned Student');
});
