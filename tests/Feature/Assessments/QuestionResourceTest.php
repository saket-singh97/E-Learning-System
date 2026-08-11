<?php

use App\Domains\Assessments\Filament\Resources\Questions\Pages\CreateQuestion;
use App\Domains\Assessments\Filament\Resources\Questions\Pages\EditQuestion;
use App\Domains\Assessments\Filament\Resources\Questions\QuestionResource;
use App\Domains\Assessments\Models\Question;
use App\Domains\Content\Models\Course;
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

    $this->actingAs($this->instructor)
        ->get(QuestionResource::getUrl('create', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();
});

it('creates an mcq question as an unapproved draft', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'mcq',
            'stem' => 'What is 2 + 2?',
            'options' => ['3', '4', '5'],
            'correct_answer_mcq' => '4',
            'difficulty' => 'easy',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $question = Question::sole();

    expect($question->status)->toBe('draft')
        ->and($question->source)->toBe('manual')
        ->and($question->created_by)->toBe($this->instructor->id)
        ->and($question->options)->toBe(['3', '4', '5'])
        ->and($question->correct_answer)->toBe('4');
});

it('creates a true_false question with automatic options', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'true_false',
            'stem' => 'Newton wrote the Principia.',
            'correct_answer_true_false' => 'True',
            'difficulty' => 'medium',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $question = Question::sole();

    expect($question->options)->toBe(['True', 'False'])
        ->and($question->correct_answer)->toBe('True');
});

it('creates a short_answer question with no options and a model answer', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'short_answer',
            'stem' => 'State Newtons second law.',
            'correct_answer_short' => 'Force equals mass times acceleration.',
            'difficulty' => 'medium',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $question = Question::sole();

    expect($question->options)->toBeNull()
        ->and($question->correct_answer)->toBe('Force equals mass times acceleration.');
});

it('creates a numerical question with a value and tolerance', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'numerical',
            'stem' => 'Acceleration due to gravity, in m/s^2?',
            'correct_answer_numerical_value' => '9.8',
            'correct_answer_numerical_tolerance' => '0.2',
            'difficulty' => 'hard',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $question = Question::sole();

    expect($question->options)->toBeNull()
        ->and($question->correct_answer)->toBe(['value' => 9.8, 'tolerance' => 0.2]);
});

it('rejects an mcq question with fewer than two options', function () {
    Livewire::test(CreateQuestion::class)
        ->fillForm([
            'course_id' => $this->course->id,
            'type' => 'mcq',
            'stem' => 'Broken question',
            'options' => ['Only one'],
            'difficulty' => 'easy',
        ])
        ->call('create')
        ->assertHasFormErrors(['options']);

    expect(Question::count())->toBe(0);
});

it('edits a draft question without touching its status, and prefills the correct answer for its type', function () {
    $question = Question::create([
        'course_id' => $this->course->id,
        'type' => 'mcq',
        'stem' => 'Original stem',
        'options' => ['A', 'B'],
        'correct_answer' => 'B',
        'difficulty' => 'easy',
        'status' => 'draft',
        'source' => 'manual',
    ]);

    Livewire::test(EditQuestion::class, ['record' => $question->id])
        ->assertFormSet(['correct_answer_mcq' => 'B'])
        ->fillForm(['stem' => 'Updated stem'])
        ->call('save')
        ->assertHasNoFormErrors();

    $question->refresh();

    expect($question->stem)->toBe('Updated stem')
        ->and($question->status)->toBe('draft');
});
