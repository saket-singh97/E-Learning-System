<?php

use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Services\QuizAttemptService;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $this->student = User::factory()->create(['tenant_id' => $tenant->id]);
    $this->instructor = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz topic', 'type' => 'quiz', 'position' => 0]);

    $quiz = Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Test quiz',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 20,
        'max_attempts' => 5,
        'status' => 'published',
    ]);

    $mcq = Question::create([
        'course_id' => $this->course->id,
        'type' => 'mcq',
        'stem' => 'What is 2 + 2?',
        'options' => ['3', '4'],
        'correct_answer' => '4',
        'difficulty' => 'easy',
        'status' => 'approved',
        'source' => 'manual',
    ]);

    $this->shortQuestion = Question::create([
        'course_id' => $this->course->id,
        'type' => 'short_answer',
        'stem' => 'State Newtons second law.',
        'correct_answer' => 'Force equals mass times acceleration.',
        'difficulty' => 'medium',
        'status' => 'approved',
        'source' => 'manual',
    ]);

    $this->attempt = QuizAttempt::create([
        'quiz_id' => $quiz->id,
        'user_id' => $this->student->id,
        'question_ids' => [$mcq->id, $this->shortQuestion->id],
        'status' => 'in_progress',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(20),
        'max_score' => 2,
    ]);

    app(QuizAttemptService::class)->submit($this->attempt, [
        $mcq->id => '4',
        $this->shortQuestion->id => 'force = mass times acceleration',
    ]);

    $this->pendingAnswer = QuizAnswer::where('question_id', $this->shortQuestion->id)->sole();
});

it('reviews a pending short_answer, assigning partial credit and marking it reviewed', function () {
    $reviewed = app(QuizAttemptService::class)->reviewAnswer($this->pendingAnswer, 0.5, $this->instructor);

    expect($reviewed->status)->toBe('reviewed')
        ->and((float) $reviewed->score)->toBe(0.5)
        ->and($reviewed->is_correct)->toBeTrue()
        ->and($reviewed->reviewed_by)->toBe($this->instructor->id)
        ->and($reviewed->reviewed_at)->not->toBeNull();
});

it('marks a zero-score review as incorrect', function () {
    $reviewed = app(QuizAttemptService::class)->reviewAnswer($this->pendingAnswer, 0.0, $this->instructor);

    expect($reviewed->is_correct)->toBeFalse();
});

it('recomputes the attempts total score once the pending answer is reviewed, not just at submit time', function () {
    expect((float) $this->attempt->fresh()->score)->toBe(1.0);

    app(QuizAttemptService::class)->reviewAnswer($this->pendingAnswer, 0.5, $this->instructor);

    expect((float) $this->attempt->fresh()->score)->toBe(1.5);
});
