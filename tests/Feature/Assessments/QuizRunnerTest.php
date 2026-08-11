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
use App\Livewire\QuizRunner;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Checkpoint', 'type' => 'quiz', 'position' => 0]);

    Question::create([
        'course_id' => $this->course->id,
        'type' => 'mcq',
        'stem' => 'What is 2 + 2?',
        'options' => ['3', '4'],
        'correct_answer' => '4',
        'difficulty' => 'easy',
        'status' => 'approved',
        'source' => 'manual',
    ]);

    $this->quiz = Quiz::create([
        'lesson_id' => $this->lesson->id,
        'title' => 'Kinematics Checkpoint',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 20,
        'max_attempts' => 1,
        'status' => 'published',
    ]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');
});

it('shows a start button and no questions before any attempt exists', function () {
    Livewire::actingAs($this->student)
        ->test(QuizRunner::class, ['quiz' => $this->quiz])
        ->assertSee('Start attempt')
        ->assertSee('Attempts remaining: 1')
        ->assertDontSee('What is 2 + 2?');
});

it('starts an attempt and displays its drawn questions', function () {
    Livewire::actingAs($this->student)
        ->test(QuizRunner::class, ['quiz' => $this->quiz])
        ->call('startAttempt')
        ->assertSee('What is 2 + 2?')
        ->assertDontSee('Start attempt');

    expect(QuizAttempt::where('quiz_id', $this->quiz->id)->where('user_id', $this->student->id)->count())->toBe(1);
});

it('shows an error instead of a second attempt once max_attempts is used up', function () {
    Livewire::actingAs($this->student)
        ->test(QuizRunner::class, ['quiz' => $this->quiz])
        ->call('startAttempt');

    Livewire::actingAs($this->student)
        ->test(QuizRunner::class, ['quiz' => $this->quiz])
        ->call('startAttempt')
        ->assertHasErrors('attempt');

    expect(QuizAttempt::where('quiz_id', $this->quiz->id)->count())->toBe(1);
});

it('shows an error instead of starting an attempt on an unpublished quiz', function () {
    $this->quiz->update(['status' => 'draft']);

    Livewire::actingAs($this->student)
        ->test(QuizRunner::class, ['quiz' => $this->quiz])
        ->call('startAttempt')
        ->assertHasErrors('attempt');

    expect(QuizAttempt::count())->toBe(0);
});

it('prompts a guest to sign in instead of starting an attempt', function () {
    Livewire::test(QuizRunner::class, ['quiz' => $this->quiz])
        ->call('startAttempt')
        ->assertHasErrors('attempt');

    expect(QuizAttempt::count())->toBe(0);
});

it('submits an answer and shows the graded result', function () {
    $question = Question::sole();

    Livewire::actingAs($this->student)
        ->test(QuizRunner::class, ['quiz' => $this->quiz])
        ->call('startAttempt')
        ->set("responses.{$question->id}", '4')
        ->call('submitAttempt')
        ->assertSee('Score: 1 / 1')
        ->assertSee('Attempts remaining: 0');

    $attempt = QuizAttempt::where('quiz_id', $this->quiz->id)->where('user_id', $this->student->id)->sole();

    expect($attempt->status)->toBe('submitted')
        ->and((float) $attempt->score)->toBe(1.0)
        ->and(QuizAnswer::where('quiz_attempt_id', $attempt->id)->count())->toBe(1);
});

it('shows an error instead of submitting when no attempt is in progress', function () {
    Livewire::actingAs($this->student)
        ->test(QuizRunner::class, ['quiz' => $this->quiz])
        ->call('submitAttempt')
        ->assertHasErrors('attempt');

    expect(QuizAnswer::count())->toBe(0);
});

it('shows the students own score sequence across past attempts on this quiz (M9)', function () {
    $question = Question::sole();

    $first = QuizAttempt::create([
        'quiz_id' => $this->quiz->id,
        'user_id' => $this->student->id,
        'question_ids' => [$question->id],
        'status' => 'in_progress',
        'started_at' => now()->subDays(2),
        'expires_at' => now()->subDays(2)->addMinutes(20),
        'max_score' => 1,
    ]);
    app(QuizAttemptService::class)->submit($first, [$question->id => '3']);

    $second = QuizAttempt::create([
        'quiz_id' => $this->quiz->id,
        'user_id' => $this->student->id,
        'question_ids' => [$question->id],
        'status' => 'in_progress',
        'started_at' => now()->subDay(),
        'expires_at' => now()->subDay()->addMinutes(20),
        'max_score' => 1,
    ]);
    app(QuizAttemptService::class)->submit($second, [$question->id => '4']);

    Livewire::actingAs($this->student)
        ->test(QuizRunner::class, ['quiz' => $this->quiz])
        ->assertSeeInOrder(['Attempt 1', 'Attempt 2'])
        ->assertSee('0 / 1')
        ->assertSee('1 / 1');
});
