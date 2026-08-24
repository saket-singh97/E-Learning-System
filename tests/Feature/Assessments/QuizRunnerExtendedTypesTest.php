<?php

use App\Domains\Assessments\Models\Question;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAnswer;
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
    $section = CourseSection::create(['course_id' => $this->course->id, 'title' => 'Kinematics', 'position' => 0]);
    $this->lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Checkpoint', 'type' => 'quiz', 'position' => 0]);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->student->assignRole('student');

    $this->makeQuiz = fn (): Quiz => Quiz::create([
        'lesson_id' => $this->lesson->id,
        'title' => 'Checkpoint',
        'blueprint' => [['count' => 1]],
        'time_limit_minutes' => 20,
        'max_attempts' => 1,
        'status' => 'published',
    ]);
});

it('persists a multi_select response to QuizAnswer.response and grades it correctly', function () {
    $question = Question::create([
        'course_id' => $this->course->id,
        'type' => 'multi_select',
        'stem' => 'Which are prime?',
        'options' => ['2', '3', '4'],
        'correct_answer' => ['correct_option_ids' => ['2', '3']],
        'difficulty' => 'easy',
        'status' => 'approved',
        'source' => 'manual',
    ]);
    $quiz = ($this->makeQuiz)();

    Livewire::actingAs($this->student)
        ->test(QuizRunner::class, ['quiz' => $quiz])
        ->call('startAttempt')
        ->set("responses.{$question->id}", ['2', '3'])
        ->call('submitAttempt');

    $answer = QuizAnswer::sole();
    expect($answer->response)->toBe(['2', '3']);
    expect($answer->is_correct)->toBeTrue();
    expect((float) $answer->score)->toBe(1.0);
});

it('persists a fill_in_blank response to QuizAnswer.response and grades it case-insensitively', function () {
    $question = Question::create([
        'course_id' => $this->course->id,
        'type' => 'fill_in_blank',
        'stem' => 'Capital of France?',
        'correct_answer' => ['accepted_answers' => ['Paris']],
        'difficulty' => 'easy',
        'status' => 'approved',
        'source' => 'manual',
    ]);
    $quiz = ($this->makeQuiz)();

    Livewire::actingAs($this->student)
        ->test(QuizRunner::class, ['quiz' => $quiz])
        ->call('startAttempt')
        ->set("responses.{$question->id}", 'paris')
        ->call('submitAttempt');

    $answer = QuizAnswer::sole();
    expect($answer->response)->toBe('paris');
    expect($answer->is_correct)->toBeTrue();
});

it('persists a matching response to QuizAnswer.response and grades it correctly', function () {
    // right[0] = Tokyo, right[1] = Paris; correct pairs: left0(France)->right1(Paris), left1(Japan)->right0(Tokyo).
    $question = Question::create([
        'course_id' => $this->course->id,
        'type' => 'matching',
        'stem' => 'Match country to capital.',
        'options' => ['left' => ['France', 'Japan'], 'right' => ['Tokyo', 'Paris']],
        'correct_answer' => ['pairs' => [[0, 1], [1, 0]]],
        'difficulty' => 'medium',
        'status' => 'approved',
        'source' => 'manual',
    ]);
    $quiz = ($this->makeQuiz)();

    Livewire::actingAs($this->student)
        ->test(QuizRunner::class, ['quiz' => $quiz])
        ->call('startAttempt')
        ->set("responses.{$question->id}.0", '1')
        ->set("responses.{$question->id}.1", '0')
        ->call('submitAttempt');

    $answer = QuizAnswer::sole();
    expect($answer->response)->toBe([0 => '1', 1 => '0']);
    expect($answer->is_correct)->toBeTrue();
    expect((float) $answer->score)->toBe(1.0);
});

it('grades a partially-correct matching response through the real component', function () {
    $question = Question::create([
        'course_id' => $this->course->id,
        'type' => 'matching',
        'stem' => 'Match country to capital.',
        'options' => ['left' => ['France', 'Japan'], 'right' => ['Tokyo', 'Paris']],
        'correct_answer' => ['pairs' => [[0, 1], [1, 0]]],
        'difficulty' => 'medium',
        'status' => 'approved',
        'source' => 'manual',
    ]);
    $quiz = ($this->makeQuiz)();

    Livewire::actingAs($this->student)
        ->test(QuizRunner::class, ['quiz' => $quiz])
        ->call('startAttempt')
        // France matched wrong (Tokyo instead of Paris), Japan matched right.
        ->set("responses.{$question->id}.0", '0')
        ->set("responses.{$question->id}.1", '0')
        ->call('submitAttempt');

    $answer = QuizAnswer::sole();
    expect((float) $answer->score)->toBe(0.5);
});

it('persists an ordering response to QuizAnswer.response and grades it correctly', function () {
    $question = Question::create([
        'course_id' => $this->course->id,
        'type' => 'ordering',
        'stem' => 'Order the steps.',
        'options' => ['Observe', 'Hypothesize', 'Experiment'],
        'correct_answer' => ['correct_sequence' => [0, 1, 2]],
        'difficulty' => 'medium',
        'status' => 'approved',
        'source' => 'manual',
    ]);
    $quiz = ($this->makeQuiz)();

    Livewire::actingAs($this->student)
        ->test(QuizRunner::class, ['quiz' => $quiz])
        ->call('startAttempt')
        ->set("responses.{$question->id}.0", '0')
        ->set("responses.{$question->id}.1", '1')
        ->set("responses.{$question->id}.2", '2')
        ->call('submitAttempt');

    $answer = QuizAnswer::sole();
    expect($answer->response)->toBe([0 => '0', 1 => '1', 2 => '2']);
    expect($answer->is_correct)->toBeTrue();
    expect((float) $answer->score)->toBe(1.0);
});
