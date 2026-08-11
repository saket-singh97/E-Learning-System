<?php

use App\Domains\Assessments\Filament\Resources\QuizAnswers\Pages\ListQuizAnswers;
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

    $this->instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->instructor->assignRole('instructor');

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Checkpoint', 'type' => 'quiz', 'position' => 0]);

    $quiz = Quiz::create([
        'lesson_id' => $lesson->id,
        'title' => 'Kinematics Checkpoint',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 20,
        'max_attempts' => 3,
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

    $attempt = QuizAttempt::create([
        'quiz_id' => $quiz->id,
        'user_id' => $this->student->id,
        'question_ids' => [$mcq->id, $this->shortQuestion->id],
        'status' => 'in_progress',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(20),
        'max_score' => 2,
    ]);

    app(QuizAttemptService::class)->submit($attempt, [
        $mcq->id => '4',
        $this->shortQuestion->id => 'force = mass times acceleration',
    ]);

    $this->autoGradedAnswer = QuizAnswer::where('question_id', $mcq->id)->sole();
    $this->pendingAnswer = QuizAnswer::where('question_id', $this->shortQuestion->id)->sole();

    $this->actingAs($this->instructor)
        ->get(QuizAnswerResource::getUrl('index', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();
});

it('lists both the auto-graded and pending_review answers', function () {
    Livewire::test(ListQuizAnswers::class)
        ->assertSee('What is 2 + 2?')
        ->assertSee('State Newtons second law.');
});

it('shows the review action only for the pending_review answer', function () {
    Livewire::test(ListQuizAnswers::class)
        ->assertTableActionVisible('review', $this->pendingAnswer)
        ->assertTableActionHidden('review', $this->autoGradedAnswer);
});

it('assigns partial credit via the review action and recomputes the attempts score', function () {
    Livewire::test(ListQuizAnswers::class)
        ->callTableAction('review', $this->pendingAnswer, data: ['score' => 0.5])
        ->assertHasNoTableActionErrors();

    $this->pendingAnswer->refresh();

    expect($this->pendingAnswer->status)->toBe('reviewed')
        ->and((float) $this->pendingAnswer->score)->toBe(0.5)
        ->and($this->pendingAnswer->reviewed_by)->toBe($this->instructor->id);

    expect((float) $this->pendingAnswer->attempt->fresh()->score)->toBe(1.5);
});

it('rejects a review score outside the 0-1 range', function () {
    Livewire::test(ListQuizAnswers::class)
        ->callTableAction('review', $this->pendingAnswer, data: ['score' => 1.5])
        ->assertHasTableActionErrors(['score']);

    expect($this->pendingAnswer->fresh()->status)->toBe('pending_review');
});
