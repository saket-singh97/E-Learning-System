<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\TimetableTemplate;
use App\Domains\Batches\Services\AutoSchedulerService;
use App\Domains\Batches\Services\TeacherConflictDetector;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function schedulerBatch(Tenant $tenant, ?User $instructor): Batch
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

function schedulerSession(Batch $batch, string $startsAt, int $durationMinutes): BatchSession
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
    $this->teacher = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('proposes conflict-free candidates from templates, each carrying a null room (v1 gap, not silently dropped)', function () {
    TimetableTemplate::create(['title' => 'Mon 4pm', 'day_of_week' => 1, 'starts_at' => '16:00:00', 'duration_minutes' => 60]);
    $batch = schedulerBatch($this->tenant, $this->teacher);

    $proposals = app(AutoSchedulerService::class)->propose($batch, 2);

    expect($proposals)->toHaveCount(2)
        ->and($proposals->pluck('room')->all())->toBe([null, null])
        ->and($proposals->pluck('teacher')->all())->toBe([$this->teacher->id, $this->teacher->id])
        ->and($proposals->pluck('duration_minutes')->all())->toBe([60, 60]);

    // The two proposals from the same weekly template must themselves be
    // conflict-free against each other, not just against real sessions.
    expect((int) $proposals[0]['starts_at']->diffInDays($proposals[1]['starts_at']))->toBe(7);
});

it('never proposes a slot overlapping an existing session for a teacher shared across batches', function () {
    TimetableTemplate::create(['title' => 'Mon 4pm', 'day_of_week' => 1, 'starts_at' => '16:00:00', 'duration_minutes' => 60]);
    $batchA = schedulerBatch($this->tenant, $this->teacher);
    $batchB = schedulerBatch($this->tenant, $this->teacher);

    $occupiedStart = Carbon\Carbon::now()->next(1)->setTimeFrom(Carbon\Carbon::parse('16:00:00'));
    schedulerSession($batchA, $occupiedStart->format('Y-m-d H:i:s'), 60);

    $proposals = app(AutoSchedulerService::class)->propose($batchB, 1);

    expect($proposals)->toHaveCount(1);
    $proposal = $proposals->first();

    expect($proposal['starts_at']->eq($occupiedStart))->toBeFalse()
        ->and(app(TeacherConflictDetector::class)->wouldConflict($batchB, $proposal['starts_at'], $proposal['duration_minutes']))->toBeFalse()
        ->and($proposal['starts_at']->eq($occupiedStart->clone()->addWeek()))->toBeTrue();
});

it('returns nothing when the tenant has no timetable templates configured', function () {
    $batch = schedulerBatch($this->tenant, $this->teacher);

    expect(app(AutoSchedulerService::class)->propose($batch, 3))->toBeEmpty();
});
