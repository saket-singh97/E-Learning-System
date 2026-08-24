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

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Quiz topic', 'type' => 'quiz', 'position' => 0]);

    $this->quiz = Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Test quiz',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 20,
        'max_attempts' => 5,
        'status' => 'published',
    ]);

    $this->makeQuestion = fn (string $type, array $overrides = []): Question => Question::create(array_merge([
        'course_id' => $course->id,
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

// multi_select

it('grades a fully-correct multi_select response with score 1.0', function () {
    $question = ($this->makeQuestion)('multi_select', ['correct_answer' => ['correct_option_ids' => [1, 2, 3, 4]]]);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->submit($attempt, [$question->id => [1, 2, 3, 4]]);

    $answer = QuizAnswer::sole();
    expect($answer->is_correct)->toBeTrue();
    expect((float) $answer->score)->toBe(1.0);
});

it('grades a partially-correct multi_select response as (correct − incorrect) / total, floored at 0', function () {
    $question = ($this->makeQuestion)('multi_select', ['correct_answer' => ['correct_option_ids' => [1, 2, 3, 4]]]);
    $attempt = ($this->makeAttempt)([$question->id]);

    // 3 correct + 1 incorrect selected → (3 - 1) / 4 = 0.5.
    app(QuizAttemptService::class)->submit($attempt, [$question->id => [1, 2, 3, 99]]);

    $answer = QuizAnswer::sole();
    expect($answer->is_correct)->toBeTrue();
    expect((float) $answer->score)->toBe(0.5);
});

it('floors a fully-wrong multi_select response at 0, never negative', function () {
    $question = ($this->makeQuestion)('multi_select', ['correct_answer' => ['correct_option_ids' => [1, 2, 3, 4]]]);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->submit($attempt, [$question->id => [98, 99]]);

    $answer = QuizAnswer::sole();
    expect($answer->is_correct)->toBeFalse();
    expect((float) $answer->score)->toBe(0.0);
});

// fill_in_blank

it('grades an exact-match fill_in_blank response as correct', function () {
    $question = ($this->makeQuestion)('fill_in_blank', ['correct_answer' => ['accepted_answers' => ['Newton', 'Sir Isaac Newton']]]);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->submit($attempt, [$question->id => 'Newton']);

    $answer = QuizAnswer::sole();
    expect($answer->is_correct)->toBeTrue();
    expect((float) $answer->score)->toBe(1.0);
});

it('grades a case-insensitive fill_in_blank match as correct, not fuzzy', function () {
    $question = ($this->makeQuestion)('fill_in_blank', ['correct_answer' => ['accepted_answers' => ['Newton']]]);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->submit($attempt, [$question->id => '  newton  ']);

    expect(QuizAnswer::sole()->is_correct)->toBeTrue();
});

it('grades a wrong fill_in_blank response as incorrect with no partial credit', function () {
    $question = ($this->makeQuestion)('fill_in_blank', ['correct_answer' => ['accepted_answers' => ['Newton']]]);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->submit($attempt, [$question->id => 'Einstein']);

    $answer = QuizAnswer::sole();
    expect($answer->is_correct)->toBeFalse();
    expect((float) $answer->score)->toBe(0.0);
});

// matching

it('grades a fully-correct matching response with score 1.0', function () {
    $question = ($this->makeQuestion)('matching', ['correct_answer' => ['pairs' => [[1, 10], [2, 20], [3, 30], [4, 40]]]]);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->submit($attempt, [$question->id => [1 => 10, 2 => 20, 3 => 30, 4 => 40]]);

    $answer = QuizAnswer::sole();
    expect($answer->is_correct)->toBeTrue();
    expect((float) $answer->score)->toBe(1.0);
});

it('grades a partially-correct matching response as the fraction of correct pairs', function () {
    $question = ($this->makeQuestion)('matching', ['correct_answer' => ['pairs' => [[1, 10], [2, 20], [3, 30], [4, 40]]]]);
    $attempt = ($this->makeAttempt)([$question->id]);

    // 3 of 4 pairs correct → 0.75.
    app(QuizAttemptService::class)->submit($attempt, [$question->id => [1 => 10, 2 => 20, 3 => 30, 4 => 99]]);

    $answer = QuizAnswer::sole();
    expect($answer->is_correct)->toBeTrue();
    expect((float) $answer->score)->toBe(0.75);
});

it('grades a fully-wrong matching response with score 0.0', function () {
    $question = ($this->makeQuestion)('matching', ['correct_answer' => ['pairs' => [[1, 10], [2, 20], [3, 30], [4, 40]]]]);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->submit($attempt, [$question->id => [1 => 99, 2 => 98, 3 => 97, 4 => 96]]);

    $answer = QuizAnswer::sole();
    expect($answer->is_correct)->toBeFalse();
    expect((float) $answer->score)->toBe(0.0);
});

// ordering

it('grades a fully-correct ordering response with score 1.0', function () {
    $question = ($this->makeQuestion)('ordering', ['correct_answer' => ['correct_sequence' => [1, 2, 3, 4]]]);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->submit($attempt, [$question->id => [1, 2, 3, 4]]);

    $answer = QuizAnswer::sole();
    expect($answer->is_correct)->toBeTrue();
    expect((float) $answer->score)->toBe(1.0);
});

it('grades a partially-correct ordering response as the fraction of correct positions', function () {
    $question = ($this->makeQuestion)('ordering', ['correct_answer' => ['correct_sequence' => [1, 2, 3, 4]]]);
    $attempt = ($this->makeAttempt)([$question->id]);

    // Positions 0 and 1 correct, 2 and 3 swapped → 0.5.
    app(QuizAttemptService::class)->submit($attempt, [$question->id => [1, 2, 4, 3]]);

    $answer = QuizAnswer::sole();
    expect($answer->is_correct)->toBeTrue();
    expect((float) $answer->score)->toBe(0.5);
});

it('grades a fully-wrong ordering response with score 0.0', function () {
    $question = ($this->makeQuestion)('ordering', ['correct_answer' => ['correct_sequence' => [1, 2, 3, 4]]]);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->submit($attempt, [$question->id => [4, 3, 2, 1]]);

    $answer = QuizAnswer::sole();
    expect($answer->is_correct)->toBeFalse();
    expect((float) $answer->score)->toBe(0.0);
});
