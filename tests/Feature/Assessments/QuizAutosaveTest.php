<?php

use App\Domains\Assessments\Jobs\AutoSubmitExpiredQuizAttempts;
use App\Domains\Assessments\Filament\Resources\QuizAnswers\QuizAnswerResource;
use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Services\QuizAttemptService;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Tenancy\Models\Tenant;
use App\Livewire\QuizRunner;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($tenant);

    $this->student = User::factory()->create(['tenant_id' => $tenant->id]);
    $this->student->assignRole('student');

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

    $this->makeAttempt = fn (array $questionIds, ?string $expiresAt = null): QuizAttempt => QuizAttempt::create([
        'quiz_id' => $this->quiz->id,
        'user_id' => $this->student->id,
        'question_ids' => $questionIds,
        'status' => 'in_progress',
        'started_at' => now(),
        'expires_at' => $expiresAt ?? now()->addMinutes(20),
        'max_score' => count($questionIds),
    ]);
});

it('saves a draft answer without grading it', function () {
    $question = ($this->makeQuestion)('mcq', ['options' => ['3', '4'], 'correct_answer' => '4']);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->saveAnswer($attempt, $question->id, '4');

    $answer = QuizAnswer::sole();

    expect($answer->response)->toBe('4')
        ->and($answer->status)->toBe('draft')
        ->and($answer->is_correct)->toBeNull()
        ->and($answer->score)->toBeNull();
});

it('updates the same draft row on a second autosave instead of duplicating it', function () {
    $question = ($this->makeQuestion)('mcq', ['options' => ['3', '4'], 'correct_answer' => '4']);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->saveAnswer($attempt, $question->id, '3');
    app(QuizAttemptService::class)->saveAnswer($attempt, $question->id, '4');

    expect(QuizAnswer::count())->toBe(1);
    expect(QuizAnswer::sole()->response)->toBe('4');
});

it('is a silent no-op once the attempt is no longer in_progress', function () {
    $question = ($this->makeQuestion)('mcq', ['options' => ['3', '4'], 'correct_answer' => '4']);
    $attempt = ($this->makeAttempt)([$question->id]);
    $attempt->update(['status' => 'submitted']);

    app(QuizAttemptService::class)->saveAnswer($attempt, $question->id, '4');

    expect(QuizAnswer::count())->toBe(0);
});

it('is a silent no-op for a question id not drawn into this attempt', function () {
    $question = ($this->makeQuestion)('mcq', ['options' => ['3', '4'], 'correct_answer' => '4']);
    $other = ($this->makeQuestion)('mcq', ['options' => ['3', '4'], 'correct_answer' => '4']);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->saveAnswer($attempt, $other->id, '4');

    expect(QuizAnswer::count())->toBe(0);
});

it('grades an autosaved draft response at submit time, not just an explicitly passed one', function () {
    $question = ($this->makeQuestion)('mcq', ['options' => ['3', '4'], 'correct_answer' => '4']);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->saveAnswer($attempt, $question->id, '4');

    // Same empty-array call AutoSubmitExpiredQuizAttempts's own sweep makes.
    $result = app(QuizAttemptService::class)->submit($attempt, []);

    $answer = QuizAnswer::sole();
    expect($answer->response)->toBe('4')
        ->and($answer->is_correct)->toBeTrue()
        ->and($answer->status)->toBe('auto_graded')
        ->and((float) $result->score)->toBe(1.0);
});

it('lets an explicitly passed response at submit time override a stale autosaved draft', function () {
    $question = ($this->makeQuestion)('mcq', ['options' => ['3', '4'], 'correct_answer' => '4']);
    $attempt = ($this->makeAttempt)([$question->id]);

    app(QuizAttemptService::class)->saveAnswer($attempt, $question->id, '3');

    $result = app(QuizAttemptService::class)->submit($attempt, [$question->id => '4']);

    expect(QuizAnswer::sole()->response)->toBe('4')
        ->and((float) $result->score)->toBe(1.0);
});

it('auto-submit sweep grades what was autosaved, not a blank attempt', function () {
    $question = ($this->makeQuestion)('mcq', ['options' => ['3', '4'], 'correct_answer' => '4']);
    $attempt = ($this->makeAttempt)([$question->id], now()->subMinute());

    app(QuizAttemptService::class)->saveAnswer($attempt, $question->id, '4');

    app(AutoSubmitExpiredQuizAttempts::class)->handle();

    $answer = QuizAnswer::sole();
    expect($attempt->fresh()->status)->toBe('auto_submitted')
        ->and($answer->response)->toBe('4')
        ->and($answer->is_correct)->toBeTrue()
        ->and((float) $attempt->fresh()->score)->toBe(1.0);
});

it('excludes draft answers from the instructor review queue', function () {
    $short = ($this->makeQuestion)('short_answer', ['correct_answer' => 'anything']);
    $attempt = ($this->makeAttempt)([$short->id]);

    app(QuizAttemptService::class)->saveAnswer($attempt, $short->id, 'still typing');

    expect(QuizAnswer::sole()->status)->toBe('draft');

    $instructor = User::factory()->create(['tenant_id' => $this->student->tenant_id]);
    $instructor->assignRole('instructor');

    $this->actingAs($instructor)
        ->get('http://physics.lms.test/teach/quiz-answers')
        ->assertOk()
        ->assertDontSee('still typing');
});

it('persists a draft answer through the QuizRunner Livewire component on blur/selection-change', function () {
    $question = ($this->makeQuestion)('mcq', ['options' => ['3', '4'], 'correct_answer' => '4']);
    ($this->makeAttempt)([$question->id]);

    $this->actingAs($this->student);

    Livewire::test(QuizRunner::class, ['quiz' => $this->quiz])
        ->set('responses.'.$question->id, '4')
        ->call('saveAnswer', $question->id);

    $answer = QuizAnswer::sole();
    expect($answer->response)->toBe('4')
        ->and($answer->status)->toBe('draft');
});
