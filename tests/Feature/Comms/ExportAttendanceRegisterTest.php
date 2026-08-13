<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Comms\Jobs\ExportAttendanceRegister;
use App\Domains\Content\Models\AttendanceRecord;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

function batchSessionAttendance(Tenant $tenant, User $student, string $status = 'present'): void
{
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $liveLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live Q&A', 'type' => 'live_session', 'position' => 0]);
    $session = BatchSession::create(['batch_id' => $batch->id, 'lesson_id' => $liveLesson->id, 'starts_at' => now(), 'duration_minutes' => 60, 'position' => 0]);

    AttendanceRecord::create([
        'attendable_type' => BatchSession::class,
        'attendable_id' => $session->id,
        'user_id' => $student->id,
        'status' => $status,
        'source' => 'manual',
        'recorded_at' => '2026-08-01 10:00:00',
    ]);
}

it('writes a csv row per attendance record with student, context, status, source, and recorded_at', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Riya Sharma', 'email' => 'riya@example.com']);
    batchSessionAttendance($this->tenant, $student, 'present');

    ExportAttendanceRegister::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportAttendanceRegister::pathFor($this->tenant->id));

    expect($csv)->toContain('Riya Sharma')
        ->toContain('riya@example.com')
        ->toContain('Live Q&A')
        ->toContain('present')
        ->toContain('manual')
        ->toContain('2026-08-01 10:00:00');
});

it('labels a video-coverage record by its lesson title, not the raw class name', function () {
    $section = CourseSection::create(['course_id' => Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published'])->id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0]);
    $student = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Coverage Student']);

    AttendanceRecord::create([
        'attendable_type' => Lesson::class,
        'attendable_id' => $lesson->id,
        'user_id' => $student->id,
        'status' => 'present',
        'source' => 'video_coverage',
        'recorded_at' => now(),
    ]);

    ExportAttendanceRegister::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportAttendanceRegister::pathFor($this->tenant->id));

    expect($csv)->toContain('Coverage Student')
        ->toContain('Velocity')
        ->not->toContain(Lesson::class);
});

it('escapes a student name that would otherwise become a live spreadsheet formula', function () {
    $student = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => '=SUM(1+1)']);
    batchSessionAttendance($this->tenant, $student);

    ExportAttendanceRegister::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportAttendanceRegister::pathFor($this->tenant->id));

    // A leading apostrophe forces spreadsheet software to treat the cell
    // as literal text rather than a formula — the raw name must never
    // appear as the very start of a cell (fputcsv would otherwise leave
    // "=SUM(1+1)" as a live formula for any admin opening this in Excel).
    expect($csv)->toContain("'=SUM(1+1)")
        ->not->toContain(",=SUM(1+1)")
        ->not->toContain("\n=SUM(1+1)");
});

it('only exports the requested tenants own attendance records', function () {
    $ownStudent = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Own Tenant Student']);
    batchSessionAttendance($this->tenant, $ownStudent);

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    $otherStudent = User::factory()->create(['tenant_id' => $otherTenant->id, 'name' => 'Other Tenant Student']);
    batchSessionAttendance($otherTenant, $otherStudent);
    app(CurrentTenant::class)->set($this->tenant);

    ExportAttendanceRegister::dispatch($this->tenant->id);

    $csv = Storage::disk('local')->get(ExportAttendanceRegister::pathFor($this->tenant->id));

    expect($csv)->toContain('Own Tenant Student')
        ->not->toContain('Other Tenant Student');
});

it('has retry configured now that it is safe to actually retry', function () {
    // S9 B10: the one job in the "every existing job gets tries/backoff()"
    // list that already implemented ShouldQueue with no internal
    // try/catch — see the job's own docblock for why it benefits from
    // Laravel's automatic queue retry with no other change needed, unlike
    // the AI ingestion chain (which deliberately self-fails instead).
    $job = new ExportAttendanceRegister(tenant_id: null);

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900]);
});
