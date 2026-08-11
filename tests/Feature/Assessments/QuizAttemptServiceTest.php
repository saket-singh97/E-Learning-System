<?php

use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Services\QuizAttemptService;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\QuizAttemptLimitExceededException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $this->student = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics-'.uniqid(), 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Quiz topic', 'type' => 'quiz', 'position' => 0]);

    $this->makeQuiz = fn (array $blueprint, int $maxAttempts = 3): Quiz => Quiz::create([
        'lesson_id' => $this->lesson->id,
        'title' => 'Test quiz',
        'blueprint' => $blueprint,
        'time_limit_minutes' => 20,
        'max_attempts' => $maxAttempts,
        'status' => 'published',
    ]);

    $this->makeQuestion = fn (string $status = 'approved', array $overrides = []): Question => Question::create(array_merge([
        'course_id' => $this->course->id,
        'type' => 'mcq',
        'stem' => 'Q '.uniqid(),
        'options' => ['A', 'B'],
        'correct_answer' => 'A',
        'difficulty' => 'easy',
        'status' => $status,
        'source' => 'manual',
    ], $overrides));
});

it('draws exactly the blueprint count from the approved pool, ignoring drafts', function () {
    for ($i = 0; $i < 3; $i++) {
        ($this->makeQuestion)('approved');
    }
    ($this->makeQuestion)('draft');

    $quiz = ($this->makeQuiz)([['count' => 3]]);

    $attempt = app(QuizAttemptService::class)->start($quiz, $this->student);

    expect($attempt->question_ids)->toHaveCount(3)
        ->and($attempt->status)->toBe('in_progress')
        ->and((float) $attempt->max_score)->toBe(3.0);

    $draftIds = Question::where('status', 'draft')->pluck('id')->all();
    expect(array_intersect($attempt->question_ids, $draftIds))->toBeEmpty();
});

it('sets expires_at to started_at plus the quizs time limit', function () {
    ($this->makeQuestion)('approved');

    $quiz = ($this->makeQuiz)([['count' => 1]]);

    $attempt = app(QuizAttemptService::class)->start($quiz, $this->student);

    expect($attempt->expires_at->equalTo($attempt->started_at->copy()->addMinutes(20)))->toBeTrue();
});

it('draws distinct questions across multiple blueprint lines with no overlap', function () {
    ($this->makeQuestion)('approved', ['difficulty' => 'easy']);
    ($this->makeQuestion)('approved', ['difficulty' => 'easy']);
    ($this->makeQuestion)('approved', ['difficulty' => 'hard']);
    ($this->makeQuestion)('approved', ['difficulty' => 'hard']);

    $quiz = ($this->makeQuiz)([
        ['difficulty' => 'easy', 'count' => 2],
        ['difficulty' => 'hard', 'count' => 2],
    ]);

    $attempt = app(QuizAttemptService::class)->start($quiz, $this->student);

    expect($attempt->question_ids)->toHaveCount(4)
        ->and(array_unique($attempt->question_ids))->toHaveCount(4);
});

it('excludes the previous attempts question set when the pool is large enough', function () {
    collect(range(1, 5))->each(fn () => ($this->makeQuestion)('approved'));

    $quiz = ($this->makeQuiz)([['count' => 1]], maxAttempts: 5);
    $service = app(QuizAttemptService::class);

    $first = $service->start($quiz, $this->student);
    $first->update(['status' => 'submitted', 'submitted_at' => now()]);

    $second = $service->start($quiz, $this->student);

    expect($second->question_ids)->not->toBe($first->question_ids);
});

it('allows repeating the previous question when the approved pool is too small to avoid it', function () {
    ($this->makeQuestion)('approved');

    $quiz = ($this->makeQuiz)([['count' => 1]], maxAttempts: 5);
    $service = app(QuizAttemptService::class);

    $first = $service->start($quiz, $this->student);
    $first->update(['status' => 'submitted', 'submitted_at' => now()]);

    $second = $service->start($quiz, $this->student);

    expect($second->question_ids)->toHaveCount(1)
        ->and($second->question_ids)->toBe($first->question_ids);
});

it('throws once the students attempt count reaches max_attempts', function () {
    ($this->makeQuestion)('approved');

    $quiz = ($this->makeQuiz)([['count' => 1]], maxAttempts: 1);
    $service = app(QuizAttemptService::class);

    $service->start($quiz, $this->student);

    expect(fn () => $service->start($quiz, $this->student))
        ->toThrow(QuizAttemptLimitExceededException::class);

    expect(QuizAttempt::where('quiz_id', $quiz->id)->count())->toBe(1);
});
