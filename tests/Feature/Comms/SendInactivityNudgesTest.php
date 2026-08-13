<?php

use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Models\VideoWatchEvent;
use App\Domains\Comms\Jobs\SendInactivityNudges;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Notifications\InactivityNudgeDue;
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

function nudgeFixture(Tenant $tenant, string $requestedAt): array
{
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0, 'video_status' => 'ready']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->subMonth()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => $requestedAt,
    ]);

    return [$student, $lesson];
}

it('nudges a student with no activity in 3+ days', function () {
    Notification::fake();

    [$student] = nudgeFixture($this->tenant, now()->subDays(10)->toDateString());

    app(SendInactivityNudges::class)->handle();

    Notification::assertSentTo($student, InactivityNudgeDue::class);
});

it('does not nudge a student who watched a video within the last 3 days', function () {
    Notification::fake();

    [$student, $lesson] = nudgeFixture($this->tenant, now()->subDays(10)->toDateString());
    VideoWatchEvent::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'start_s' => 0, 'end_s' => 10]);

    app(SendInactivityNudges::class)->handle();

    Notification::assertNothingSent();
});

it('does not nudge a student who attempted a quiz within the last 3 days', function () {
    Notification::fake();

    [$student] = nudgeFixture($this->tenant, now()->subDays(10)->toDateString());

    $course = Course::create(['title' => 'Quiz course', 'slug' => 'quiz-course-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $quizLesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz topic', 'type' => 'quiz', 'position' => 0]);
    $quiz = Quiz::create(['lesson_id' => $quizLesson->id, 'title' => 'Test', 'blueprint' => [['count' => 1]], 'time_limit_minutes' => 20, 'max_attempts' => 5, 'status' => 'published']);
    $question = Question::create(['course_id' => $course->id, 'type' => 'mcq', 'stem' => 'Q?', 'options' => ['a', 'b'], 'correct_answer' => 'a', 'difficulty' => 'easy', 'status' => 'approved', 'source' => 'manual']);
    QuizAttempt::create(['quiz_id' => $quiz->id, 'user_id' => $student->id, 'question_ids' => [$question->id], 'status' => 'in_progress', 'started_at' => now(), 'expires_at' => now()->addMinutes(20), 'max_score' => 1]);

    app(SendInactivityNudges::class)->handle();

    Notification::assertNothingSent();
});

it('does not nudge a student whose enrolment is less than 3 days old, even with zero activity', function () {
    Notification::fake();

    nudgeFixture($this->tenant, now()->subDay()->toDateString());

    app(SendInactivityNudges::class)->handle();

    Notification::assertNothingSent();
});

it('does not nudge a student not actively enrolled', function () {
    Notification::fake();

    [$student] = nudgeFixture($this->tenant, now()->subDays(10)->toDateString());
    Enrolment::where('user_id', $student->id)->update(['status' => 'cancelled']);

    app(SendInactivityNudges::class)->handle();

    Notification::assertNothingSent();
});

it('sends only one nudge per student per week', function () {
    [$student] = nudgeFixture($this->tenant, now()->subDays(10)->toDateString());

    app(SendInactivityNudges::class)->handle();
    app(SendInactivityNudges::class)->handle();

    expect($student->notifications()->where('type', InactivityNudgeDue::class)->count())->toBe(1);
});

it('sweeps every tenant even when CurrentTenant is left stale-bound to a different one, not explicitly cleared', function () {
    [$studentA] = nudgeFixture($this->tenant, now()->subDays(10)->toDateString());

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    [$studentB] = nudgeFixture($otherTenant, now()->subDays(10)->toDateString());

    // Left bound to $otherTenant, not cleared — proving handle()'s own
    // clear() call, not test setup, is what makes the sweep see every
    // tenant (same S9 B10 regression shape).
    expect(app(CurrentTenant::class)->id())->toBe($otherTenant->id);

    app(SendInactivityNudges::class)->handle();

    expect($studentA->notifications()->where('type', InactivityNudgeDue::class)->count())->toBe(1)
        ->and($studentB->notifications()->where('type', InactivityNudgeDue::class)->count())->toBe(1);
});

it('is a real queued job with retry configured', function () {
    expect(app(SendInactivityNudges::class))
        ->toBeInstanceOf(ShouldQueue::class);

    $job = new SendInactivityNudges;

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900]);
});
