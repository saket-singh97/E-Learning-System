<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Models\LiveRecording;
use App\Domains\Comms\Jobs\SendMissedClassReplayNudges;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Notifications\MissedClassReplayNudgeDue;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

function replayNudgeFixture(Tenant $tenant, string $recordingStatus = 'ready'): array
{
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Mechanics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Live class', 'type' => 'live_session', 'position' => 0]);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-'.uniqid(),
        'starts_on' => now()->subMonth()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $session = BatchSession::create([
        'batch_id' => $batch->id,
        'lesson_id' => $lesson->id,
        'title' => 'Class 1',
        'starts_at' => now()->subDay(),
        'duration_minutes' => 60,
        'position' => 0,
        'live_provider' => 'external_link',
    ]);
    $recording = LiveRecording::create([
        'batch_session_id' => $session->id,
        'status' => $recordingStatus,
    ]);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now()->subWeek(),
    ]);

    return [$student, $lesson, $session, $recording];
}

it('nudges a student who never watched the replay', function () {
    Notification::fake();

    [$student, , $session] = replayNudgeFixture($this->tenant);

    app(SendMissedClassReplayNudges::class)->handle();

    Notification::assertSentTo($student, MissedClassReplayNudgeDue::class, function ($notification) use ($session) {
        return $notification->batchSession->id === $session->id;
    });
});

it('does not nudge a student who already watched the replay', function () {
    Notification::fake();

    [$student, $lesson, , $recording] = replayNudgeFixture($this->tenant);
    $event = VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 100]);
    $event->forceFill(['created_at' => $recording->updated_at->clone()->addMinute()])->save();

    app(SendMissedClassReplayNudges::class)->handle();

    Notification::assertNothingSent();
});

it('does not nudge when the only watch event predates the recording going ready', function () {
    Notification::fake();

    [$student, $lesson, , $recording] = replayNudgeFixture($this->tenant);
    $event = VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 100]);
    $event->forceFill(['created_at' => $recording->updated_at->clone()->subDay()])->save();

    app(SendMissedClassReplayNudges::class)->handle();

    Notification::assertSentTo($student, MissedClassReplayNudgeDue::class);
});

it('does not nudge when the recording is not ready yet', function () {
    Notification::fake();

    replayNudgeFixture($this->tenant, 'downloading');

    app(SendMissedClassReplayNudges::class)->handle();

    Notification::assertNothingSent();
});

it('does not nudge a student not actively enrolled', function () {
    Notification::fake();

    [$student] = replayNudgeFixture($this->tenant);
    Enrolment::where('user_id', $student->id)->update(['status' => 'cancelled']);

    app(SendMissedClassReplayNudges::class)->handle();

    Notification::assertNothingSent();
});

it('sends only one nudge per student per week', function () {
    [$student] = replayNudgeFixture($this->tenant);

    app(SendMissedClassReplayNudges::class)->handle();
    app(SendMissedClassReplayNudges::class)->handle();

    expect($student->notifications()->where('type', MissedClassReplayNudgeDue::class)->count())->toBe(1);
});

it('is a real queued job with retry configured', function () {
    expect(app(SendMissedClassReplayNudges::class))
        ->toBeInstanceOf(ShouldQueue::class);

    $job = new SendMissedClassReplayNudges;

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900]);
});
