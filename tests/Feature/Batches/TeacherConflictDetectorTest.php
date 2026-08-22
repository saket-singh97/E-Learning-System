<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Services\TeacherConflictDetector;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeBatchForInstructor(Tenant $tenant, ?User $instructor): Batch
{
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);

    return Batch::create([
        'course_id' => $course->id,
        'instructor_id' => $instructor?->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
}

function makeSession(Batch $batch, string $startsAt, int $durationMinutes): BatchSession
{
    $section = CourseSection::create(['course_id' => $batch->course_id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Lesson', 'type' => 'live_session', 'position' => 0]);

    return BatchSession::create([
        'batch_id' => $batch->id,
        'lesson_id' => $lesson->id,
        'starts_at' => $startsAt,
        'duration_minutes' => $durationMinutes,
        'position' => 0,
        'live_provider' => 'external_link',
        'provider_meeting_ref' => 'https://example.test/meet',
    ]);
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->detector = new TeacherConflictDetector;
});

it('flags an overlapping window across different batches for the same instructor', function () {
    $batchA = makeBatchForInstructor($this->tenant, $this->instructor);
    $batchB = makeBatchForInstructor($this->tenant, $this->instructor);
    makeSession($batchA, '2026-09-01 09:00:00', 60);

    expect($this->detector->wouldConflict($batchB, '2026-09-01 09:30:00', 30))->toBeTrue();
});

it('allows a back-to-back non-overlapping session', function () {
    $batch = makeBatchForInstructor($this->tenant, $this->instructor);
    makeSession($batch, '2026-09-01 09:00:00', 60);

    expect($this->detector->wouldConflict($batch, '2026-09-01 10:00:00', 60))->toBeFalse();
});

it('ignores sessions for a different instructor', function () {
    $otherInstructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $batchA = makeBatchForInstructor($this->tenant, $this->instructor);
    $batchB = makeBatchForInstructor($this->tenant, $otherInstructor);
    makeSession($batchA, '2026-09-01 09:00:00', 60);

    expect($this->detector->wouldConflict($batchB, '2026-09-01 09:30:00', 30))->toBeFalse();
});

it('never conflicts when the batch has no assigned instructor', function () {
    $batch = makeBatchForInstructor($this->tenant, null);
    makeSession($batch, '2026-09-01 09:00:00', 60);

    expect($this->detector->wouldConflict($batch, '2026-09-01 09:30:00', 30))->toBeFalse();
});

it('excludes the session being edited from its own conflict check', function () {
    $batch = makeBatchForInstructor($this->tenant, $this->instructor);
    $session = makeSession($batch, '2026-09-01 09:00:00', 60);

    expect($this->detector->wouldConflict($batch, '2026-09-01 09:00:00', 60, $session->id))->toBeFalse();
});
