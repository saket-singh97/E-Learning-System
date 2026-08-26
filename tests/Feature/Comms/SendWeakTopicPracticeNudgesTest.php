<?php

use App\Domains\AI\Services\WeakTopicDetectionService;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Comms\Jobs\SendWeakTopicPracticeNudges;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Notifications\WeakTopicPracticeNudgeDue;
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

function weakNudgeFixture(Tenant $tenant): array
{
    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Velocity', 'type' => 'video', 'position' => 0]);
    $quiz = Quiz::create(['lesson_id' => $lesson->id, 'title' => 'Quiz', 'status' => 'published', 'blueprint' => [], 'time_limit_minutes' => 20, 'max_attempts' => 5]);
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

    Enrolment::create(['batch_id' => $batch->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()->subWeek()]);

    return [$student, $course, $quiz];
}

function weakNudgeAnswer(int $courseId, int $attemptId, string $concept, bool $isCorrect): void
{
    $question = Question::create([
        'course_id' => $courseId,
        'type' => 'mcq',
        'stem' => 'Q?',
        'correct_answer' => ['A'],
        'difficulty' => 'medium',
        'concept' => $concept,
        'source' => 'manual',
    ]);

    QuizAnswer::create([
        'quiz_attempt_id' => $attemptId,
        'question_id' => $question->id,
        'is_correct' => $isCorrect,
        'status' => 'auto_graded',
    ]);
}

function weakNudgeAttempt(int $quizId, int $studentId): QuizAttempt
{
    return QuizAttempt::create([
        'quiz_id' => $quizId,
        'user_id' => $studentId,
        'question_ids' => [],
        'status' => 'submitted',
        'started_at' => now(),
        'expires_at' => now()->addHour(),
        'max_score' => 10,
    ]);
}

it('nudges a student with a real weak topic', function () {
    Notification::fake();

    [$student, $course, $quiz] = weakNudgeFixture($this->tenant);
    weakNudgeAnswer($course->id, weakNudgeAttempt($quiz->id, $student->id)->id, 'Kinematics', false);

    app(SendWeakTopicPracticeNudges::class)->handle(app(WeakTopicDetectionService::class));

    Notification::assertSentTo($student, WeakTopicPracticeNudgeDue::class, function ($notification) {
        return $notification->weakTopics->first()['concept'] === 'Kinematics';
    });
});

it('skips a student with no weak topics', function () {
    Notification::fake();

    [$student, $course, $quiz] = weakNudgeFixture($this->tenant);
    weakNudgeAnswer($course->id, weakNudgeAttempt($quiz->id, $student->id)->id, 'Kinematics', true);

    app(SendWeakTopicPracticeNudges::class)->handle(app(WeakTopicDetectionService::class));

    Notification::assertNothingSent();
});

it('skips a student not actively enrolled', function () {
    Notification::fake();

    [$student, $course, $quiz] = weakNudgeFixture($this->tenant);
    weakNudgeAnswer($course->id, weakNudgeAttempt($quiz->id, $student->id)->id, 'Kinematics', false);
    Enrolment::where('user_id', $student->id)->update(['status' => 'cancelled']);

    app(SendWeakTopicPracticeNudges::class)->handle(app(WeakTopicDetectionService::class));

    Notification::assertNothingSent();
});

it('sends only one nudge per student per week', function () {
    [$student, $course, $quiz] = weakNudgeFixture($this->tenant);
    weakNudgeAnswer($course->id, weakNudgeAttempt($quiz->id, $student->id)->id, 'Kinematics', false);

    app(SendWeakTopicPracticeNudges::class)->handle(app(WeakTopicDetectionService::class));
    app(SendWeakTopicPracticeNudges::class)->handle(app(WeakTopicDetectionService::class));

    expect($student->notifications()->where('type', WeakTopicPracticeNudgeDue::class)->count())->toBe(1);
});

it('is a real queued job with retry configured', function () {
    expect(app(SendWeakTopicPracticeNudges::class))
        ->toBeInstanceOf(ShouldQueue::class);

    $job = new SendWeakTopicPracticeNudges;

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900]);
});
