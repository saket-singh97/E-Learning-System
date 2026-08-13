<?php

use App\Domains\Assessments\Jobs\AutoSubmitExpiredQuizAttempts;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->makeTenantFixture = function (): array {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant);

        $student = User::factory()->create(['tenant_id' => $tenant->id]);

        $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);
        $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
        $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz topic', 'type' => 'quiz', 'position' => 0]);

        $quiz = Quiz::create([
            'lesson_id' => $lesson->id,
            'title' => 'Test quiz',
            'blueprint' => [['count' => 1]],
            'time_limit_minutes' => 20,
            'max_attempts' => 5,
            'status' => 'published',
        ]);

        $question = Question::create([
            'course_id' => $course->id,
            'type' => 'mcq',
            'stem' => 'What is 2 + 2?',
            'options' => ['3', '4'],
            'correct_answer' => '4',
            'difficulty' => 'easy',
            'status' => 'approved',
            'source' => 'manual',
        ]);

        return compact('tenant', 'quiz', 'student', 'question');
    };

    $this->makeAttempt = fn (Quiz $quiz, User $student, Question $question, string $expiresAt): QuizAttempt => QuizAttempt::create([
        'quiz_id' => $quiz->id,
        'user_id' => $student->id,
        'question_ids' => [$question->id],
        'status' => 'in_progress',
        'started_at' => now()->subMinutes(30),
        'expires_at' => $expiresAt,
        'max_score' => 1,
    ]);
});

it('force-submits exactly the in_progress attempts past expires_at', function () {
    ['quiz' => $quiz, 'student' => $student, 'question' => $question] = ($this->makeTenantFixture)();

    $expired = ($this->makeAttempt)($quiz, $student, $question, now()->subMinute());
    $notYetExpired = ($this->makeAttempt)($quiz, $student, $question, now()->addMinute());

    app(AutoSubmitExpiredQuizAttempts::class)->handle();

    expect($expired->fresh()->status)->toBe('auto_submitted')
        ->and($notYetExpired->fresh()->status)->toBe('in_progress');
});

it('includes an attempt whose expires_at is exactly now', function () {
    ['quiz' => $quiz, 'student' => $student, 'question' => $question] = ($this->makeTenantFixture)();

    $boundary = ($this->makeAttempt)($quiz, $student, $question, now());

    app(AutoSubmitExpiredQuizAttempts::class)->handle();

    expect($boundary->fresh()->status)->toBe('auto_submitted');
});

it('leaves non-in_progress attempts untouched even if expires_at has passed', function () {
    ['quiz' => $quiz, 'student' => $student, 'question' => $question] = ($this->makeTenantFixture)();

    $submitted = ($this->makeAttempt)($quiz, $student, $question, now()->subMinute());
    $submitted->update(['status' => 'submitted', 'submitted_at' => now()]);

    app(AutoSubmitExpiredQuizAttempts::class)->handle();

    expect($submitted->fresh()->status)->toBe('submitted');
});

it('grades every question on a swept attempt as unanswered, since no response was ever persisted', function () {
    ['quiz' => $quiz, 'student' => $student, 'question' => $question] = ($this->makeTenantFixture)();

    $attempt = ($this->makeAttempt)($quiz, $student, $question, now()->subMinute());

    app(AutoSubmitExpiredQuizAttempts::class)->handle();

    $answer = QuizAnswer::where('quiz_attempt_id', $attempt->id)->sole();

    expect($answer->response)->toBeNull()
        ->and($answer->is_correct)->toBeFalse()
        ->and($answer->status)->toBe('auto_graded')
        ->and((float) $attempt->fresh()->score)->toBe(0.0)
        ->and($attempt->fresh()->submitted_at)->not->toBeNull();
});

it('sweeps every tenants expired attempts in a single pass', function () {
    ['tenant' => $tenantA, 'quiz' => $quizA, 'student' => $studentA, 'question' => $questionA] = ($this->makeTenantFixture)();
    $attemptA = ($this->makeAttempt)($quizA, $studentA, $questionA, now()->subMinute());

    ['tenant' => $tenantB, 'quiz' => $quizB, 'student' => $studentB, 'question' => $questionB] = ($this->makeTenantFixture)();
    $attemptB = ($this->makeAttempt)($quizB, $studentB, $questionB, now()->subMinute());

    app(CurrentTenant::class)->clear();
    app(AutoSubmitExpiredQuizAttempts::class)->handle();

    app(CurrentTenant::class)->set($tenantA);
    expect($attemptA->fresh()->status)->toBe('auto_submitted');

    app(CurrentTenant::class)->set($tenantB);
    expect($attemptB->fresh()->status)->toBe('auto_submitted');
});

it('is a real queued job with retry configured, shorter-tiered than the daily sweeps', function () {
    // S9 B10: this was a plain class before — Schedule::job() ran it inline
    // (dispatchNow()), so $tries/backoff() would have been dead
    // configuration. Backoff caps at 5 minutes, not the other four sweeps'
    // 15-minute tier, since this one is scheduled ->everyFiveMinutes() —
    // a longer final backoff would outlive the next scheduled run anyway.
    expect(app(AutoSubmitExpiredQuizAttempts::class))
        ->toBeInstanceOf(ShouldQueue::class);

    $job = new AutoSubmitExpiredQuizAttempts;

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 180, 300]);
});

it('does not clobber a students own in-flight submit landing between the sweeps query and its write', function () {
    ['quiz' => $quiz, 'student' => $student, 'question' => $question] = ($this->makeTenantFixture)();

    $racedAgainst = ($this->makeAttempt)($quiz, $student, $question, now()->subMinute());
    $control = ($this->makeAttempt)($quiz, $student, $question, now()->subMinute());

    DB::listen(function ($query) use ($racedAgainst) {
        if (str_contains($query->sql, 'expires_at')) {
            DB::table('quiz_attempts')
                ->where('id', $racedAgainst->id)
                ->update(['status' => 'submitted', 'submitted_at' => now(), 'score' => 1]);
        }
    });

    app(AutoSubmitExpiredQuizAttempts::class)->handle();

    expect($racedAgainst->fresh()->status)->toBe('submitted')
        ->and($control->fresh()->status)->toBe('auto_submitted');
});
