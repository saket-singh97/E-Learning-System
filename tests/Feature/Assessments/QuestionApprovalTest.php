<?php

use App\Domains\Assessments\Filament\Resources\Questions\Pages\EditQuestion;
use App\Domains\Assessments\Filament\Resources\Questions\Pages\ListQuestions;
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
        ->get(QuestionResource::getUrl('index', ['subdomain' => 'physics'], panel: 'teach'))
        ->assertOk();

    $this->makeQuestion = fn (string $status = 'draft'): Question => Question::create([
        'course_id' => $this->course->id,
        'type' => 'mcq',
        'stem' => 'What is 2 + 2?',
        'options' => ['3', '4', '5'],
        'correct_answer' => '4',
        'difficulty' => 'easy',
        'status' => $status,
        'source' => 'manual',
    ]);
});

it('approves a draft question via the table action', function () {
    $question = ($this->makeQuestion)();

    Livewire::test(ListQuestions::class)
        ->callTableAction('approve', $question);

    expect($question->fresh()->status)->toBe('approved');
});

it('hides the approve action for a question that is already approved', function () {
    $question = ($this->makeQuestion)('approved');

    Livewire::test(ListQuestions::class)
        ->assertTableActionHidden('approve', $question);
});

it('sends an approved question back to draft when its content is edited', function () {
    $question = ($this->makeQuestion)('approved');

    Livewire::test(EditQuestion::class, ['record' => $question->id])
        ->fillForm(['stem' => 'What is 2 + 2, exactly?'])
        ->call('save')
        ->assertHasNoFormErrors();

    $question->refresh();

    expect($question->stem)->toBe('What is 2 + 2, exactly?')
        ->and($question->status)->toBe('draft');
});
