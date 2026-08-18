<?php

use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Services\QuizAttemptService;
use App\Domains\Assignments\Services\GradebookService;
use App\Domains\Comms\Services\StudentMetrics;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\QuizAttemptAlreadySubmittedException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $this->student = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz topic', 'type' => 'quiz', 'position' => 0]);

    $this->quiz = Quiz::create([
        'lesson_id' => $this->lesson->id,
        'title' => 'Test quiz',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 20,
        'max_attempts' => 5,
        'status' => 'published',
    ]);

    $this->makeQuestion = fn (string $type, array $overrides = []): Question => Question::create(array_merge([
        'course_id' => $this->course->id,
        'type' => $type,
        'stem' => 'Q '.uniqid(),
        'difficulty' => 'easy',
        'status' => 'approved',
        'source' => 'manual',
    ], $overrides));

    $this->makeAttempt = fn (array $questionIds): QuizAttempt => QuizAttempt::create([
        'quiz_id' => $this->quiz->id,
        'user_id' => $this->student->id,
        'question_ids' => $questionIds,
        'status' => 'in_progress',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(20),
        'max_score' => count($questionIds),
    ]);
});

it('grades a correct mcq answer and flips the attempt to submitted', function () {
    $question = ($this->makeQuestion)('mcq', ['options' => ['3', '4'], 'correct_answer' => '4']);
    $attempt = ($this->makeAttempt)([$question->id]);

    $result = app(QuizAttemptService::class)->submit($attempt, [$question->id => '4']);

    $answer = QuizAnswer::sole();

    expect($answer->is_correct)->toBeTrue()
        ->and((float) $answer->score)->toBe(1.0)
        ->and($answer->status)->toBe('auto_graded')
        ->and($result->status)->toBe('submitted')
        ->and($result->submitted_at)->not->toBeNull()
        ->and((float) $result->score)->toBe(1.0);
});

it('grades a wrong mcq answer as incorrect with zero score', function () {
    $question = ($this->makeQuestion)('mcq', ['options' => ['3', '4'], 'correct_answer' => '4']);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->submit($attempt, [$question->id => '3']);

    $answer = QuizAnswer::sole();

    expect($answer->is_correct)->toBeFalse()
        ->and((float) $answer->score)->toBe(0.0);
});

it('grades an unanswered question as incorrect rather than skipping it', function () {
    $question = ($this->makeQuestion)('mcq', ['options' => ['3', '4'], 'correct_answer' => '4']);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->submit($attempt, []);

    $answer = QuizAnswer::sole();

    expect($answer->response)->toBeNull()
        ->and($answer->is_correct)->toBeFalse()
        ->and($answer->status)->toBe('auto_graded');
});

it('grades a numerical answer at the tolerance boundary as correct, inclusive', function () {
    $question = ($this->makeQuestion)('numerical', ['correct_answer' => ['value' => 9.8, 'tolerance' => 0.2]]);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->submit($attempt, [$question->id => '10.0']);

    expect(QuizAnswer::sole()->is_correct)->toBeTrue();
});

it('grades a numerical answer just outside tolerance as incorrect', function () {
    $question = ($this->makeQuestion)('numerical', ['correct_answer' => ['value' => 9.8, 'tolerance' => 0.2]]);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->submit($attempt, [$question->id => '10.1']);

    expect(QuizAnswer::sole()->is_correct)->toBeFalse();
});

it('sends a non-blank short_answer response to pending_review with no score yet', function () {
    $question = ($this->makeQuestion)('short_answer', ['correct_answer' => 'Force equals mass times acceleration.']);
    $attempt = ($this->makeAttempt)([$question->id]);

    $result = app(QuizAttemptService::class)->submit($attempt, [$question->id => 'F = ma']);

    $answer = QuizAnswer::sole();

    expect($answer->status)->toBe('pending_review')
        ->and($answer->score)->toBeNull()
        ->and($answer->is_correct)->toBeNull()
        ->and((float) $result->score)->toBe(0.0);
});

it('auto-grades a blank short_answer response as zero, skipping the review queue', function () {
    $question = ($this->makeQuestion)('short_answer', ['correct_answer' => 'Force equals mass times acceleration.']);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->submit($attempt, [$question->id => '   ']);

    $answer = QuizAnswer::sole();

    expect($answer->status)->toBe('auto_graded')
        ->and((float) $answer->score)->toBe(0.0);
});

it('sums only the graded answers into a partial score while one is still pending review', function () {
    $mcq = ($this->makeQuestion)('mcq', ['options' => ['3', '4'], 'correct_answer' => '4']);
    $short = ($this->makeQuestion)('short_answer', ['correct_answer' => 'anything']);
    $attempt = ($this->makeAttempt)([$mcq->id, $short->id]);

    $result = app(QuizAttemptService::class)->submit($attempt, [
        $mcq->id => '4',
        $short->id => 'a real answer',
    ]);

    expect((float) $result->score)->toBe(1.0)
        ->and((float) $result->max_score)->toBe(2.0);
});

it('recomputes max_score down when a drawn question was soft-deleted before submit', function () {
    $kept = ($this->makeQuestion)('mcq', ['options' => ['3', '4'], 'correct_answer' => '4']);
    $retired = ($this->makeQuestion)('mcq', ['options' => ['3', '4'], 'correct_answer' => '4']);
    $attempt = ($this->makeAttempt)([$kept->id, $retired->id]);

    $retired->delete();

    $result = app(QuizAttemptService::class)->submit($attempt, [$kept->id => '4']);

    expect((float) $result->max_score)->toBe(1.0)
        ->and((float) $result->score)->toBe(1.0)
        ->and(QuizAnswer::count())->toBe(1);
});

it('throws when submitting an attempt that has already been submitted', function () {
    $question = ($this->makeQuestion)('mcq', ['options' => ['3', '4'], 'correct_answer' => '4']);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->submit($attempt, [$question->id => '4']);

    expect(fn () => app(QuizAttemptService::class)->submit($attempt->fresh(), [$question->id => '4']))
        ->toThrow(QuizAttemptAlreadySubmittedException::class);

    expect(QuizAnswer::count())->toBe(1);
});

it('busts the gradebook and student-metrics caches for the attempts own tenant/student/course (S12 B12)', function () {
    $question = ($this->makeQuestion)('mcq', ['options' => ['3', '4'], 'correct_answer' => '4']);
    $attempt = ($this->makeAttempt)([$question->id]);

    // Warm both caches with a "no data yet" read before the real submit.
    app(GradebookService::class)->scoreFor($this->student, $this->course->fresh());
    app(StudentMetrics::class)->summarize($this->student);

    app(QuizAttemptService::class)->submit($attempt, [$question->id => '4']);

    $freshGradebook = app(GradebookService::class)->scoreFor($this->student, $this->course->fresh());
    $freshMetrics = app(StudentMetrics::class)->summarize($this->student);

    expect($freshGradebook['quiz_percent'])->toBe(100.0)
        ->and($freshMetrics['quiz_average_percent'])->toBe(100.0);
});
