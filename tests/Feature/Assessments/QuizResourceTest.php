<?php

use App\Domains\Assessments\Filament\Resources\Quizzes\Pages\CreateQuiz;
use App\Domains\Assessments\Filament\Resources\Quizzes\Pages\EditQuiz;
use App\Domains\Assessments\Filament\Resources\Quizzes\Pages\ListQuizzes;
use App\Domains\Assessments\Filament\Resources\Quizzes\QuizResource;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
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

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->quizLesson = Lesson::create(['course_section_id' => $this->section->id, 'title' => 'Kinematics Quiz', 'type' => 'quiz', 'position' => 0]);

    $this->actingAs($this->instructor)
        ->get(QuizResource::getUrl('create', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();
});

it('creates a quiz anchored to a quiz-type lesson, starting as draft', function () {
    Livewire::test(CreateQuiz::class)
        ->fillForm([
            'lesson_id' => $this->quizLesson->id,
            'title' => 'Kinematics Checkpoint',
            'blueprint' => [
                ['difficulty' => 'easy', 'count' => 3],
            ],
            'time_limit_minutes' => 20,
            'max_attempts' => 2,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $quiz = Quiz::sole();

    expect($quiz->lesson_id)->toBe($this->quizLesson->id)
        ->and($quiz->title)->toBe('Kinematics Checkpoint')
        ->and($quiz->blueprint)->toHaveCount(1)
        ->and($quiz->blueprint[0]['difficulty'])->toBe('easy')
        ->and($quiz->blueprint[0]['count'])->toBe(3)
        ->and($quiz->time_limit_minutes)->toBe(20)
        ->and($quiz->max_attempts)->toBe(2)
        ->and($quiz->status)->toBe('draft');
});

it('rejects a blueprint with no lines', function () {
    Livewire::test(CreateQuiz::class)
        ->fillForm([
            'lesson_id' => $this->quizLesson->id,
            'title' => 'Empty blueprint',
            'blueprint' => [],
            'time_limit_minutes' => 20,
            'max_attempts' => 2,
        ])
        ->call('create')
        ->assertHasFormErrors(['blueprint']);

    expect(Quiz::count())->toBe(0);
});

it('rejects a time limit outside the 1-180 minute range', function () {
    Livewire::test(CreateQuiz::class)
        ->fillForm([
            'lesson_id' => $this->quizLesson->id,
            'title' => 'Too long',
            'blueprint' => [['count' => 1]],
            'time_limit_minutes' => 200,
            'max_attempts' => 2,
        ])
        ->call('create')
        ->assertHasFormErrors(['time_limit_minutes']);

    expect(Quiz::count())->toBe(0);
});

it('edits a quiz and can publish it', function () {
    $quiz = Quiz::create([
        'lesson_id' => $this->quizLesson->id,
        'title' => 'Draft quiz',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 15,
        'max_attempts' => 1,
        'status' => 'draft',
    ]);

    Livewire::test(EditQuiz::class, ['record' => $quiz->id])
        ->fillForm(['status' => 'published'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($quiz->fresh()->status)->toBe('published');
});

it('hides delete for a quiz that already has attempts, to avoid destroying graded history', function () {
    $quiz = Quiz::create([
        'lesson_id' => $this->quizLesson->id,
        'title' => 'Attempted quiz',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 15,
        'max_attempts' => 3,
        'status' => 'published',
    ]);

    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    QuizAttempt::create([
        'quiz_id' => $quiz->id,
        'user_id' => $student->id,
        'question_ids' => [],
        'status' => 'in_progress',
        'started_at' => now(),
        'expires_at' => now()->addMinutes(15),
        'max_score' => 0,
    ]);

    $this->actingAs($this->instructor)
        ->get(QuizResource::getUrl('index', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    Livewire::test(ListQuizzes::class)
        ->assertTableActionHidden('delete', $quiz);
});

it('allows deleting a quiz with no attempts yet', function () {
    $quiz = Quiz::create([
        'lesson_id' => $this->quizLesson->id,
        'title' => 'Untouched quiz',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 15,
        'max_attempts' => 3,
        'status' => 'draft',
    ]);

    $this->actingAs($this->instructor)
        ->get(QuizResource::getUrl('index', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    Livewire::test(ListQuizzes::class)
        ->assertTableActionVisible('delete', $quiz);
});
