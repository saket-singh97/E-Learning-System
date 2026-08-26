<?php

use App\Domains\Comms\Livewire\SurveyRunner;
use App\Domains\Comms\Models\Survey;
use App\Domains\Comms\Models\SurveyQuestion;
use App\Domains\Comms\Models\SurveyResponse;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

function makeSurveyWithQuestions($admin): Survey
{
    $survey = Survey::create(['title' => 'Batch feedback', 'status' => 'published', 'created_by_user_id' => $admin->id]);

    SurveyQuestion::create(['survey_id' => $survey->id, 'prompt' => 'Comments?', 'type' => 'text', 'position' => 0]);
    SurveyQuestion::create(['survey_id' => $survey->id, 'prompt' => 'Favourite topic?', 'type' => 'single_choice', 'options' => ['choices' => ['Mechanics', 'Optics']], 'position' => 1]);
    SurveyQuestion::create(['survey_id' => $survey->id, 'prompt' => 'Rate the pace', 'type' => 'rating', 'options' => ['max' => 5], 'position' => 2]);

    return $survey;
}

it('lists only published surveys not yet answered', function () {
    $published = makeSurveyWithQuestions($this->admin);
    $draft = Survey::create(['title' => 'Draft', 'status' => 'draft', 'created_by_user_id' => $this->admin->id]);
    $closed = Survey::create(['title' => 'Closed', 'status' => 'closed', 'created_by_user_id' => $this->admin->id]);

    Livewire::actingAs($this->viewer)
        ->test(SurveyRunner::class)
        ->assertSee('Batch feedback')
        ->assertDontSee('Draft')
        ->assertDontSee('Closed');

    expect($draft->id)->not->toBeNull();
    expect($closed->id)->not->toBeNull();
});

it('never opens or exposes questions for a non-published survey', function () {
    $draft = Survey::create(['title' => 'Draft', 'status' => 'draft', 'created_by_user_id' => $this->admin->id]);
    SurveyQuestion::create(['survey_id' => $draft->id, 'prompt' => 'Secret question', 'type' => 'text', 'position' => 0]);

    Livewire::actingAs($this->viewer)
        ->test(SurveyRunner::class)
        ->call('openSurvey', $draft->id)
        ->assertDontSee('Secret question');

    // Even a direct property set (bypassing openSurvey's own guard) must
    // still be refused by questions()'s own re-check.
    Livewire::actingAs($this->viewer)
        ->test(SurveyRunner::class)
        ->set('surveyId', $draft->id)
        ->assertDontSee('Secret question');
});

it('submits a full response with one answer per question type', function () {
    $survey = makeSurveyWithQuestions($this->admin);
    $questions = $survey->questions()->get();

    Livewire::actingAs($this->viewer)
        ->test(SurveyRunner::class)
        ->call('openSurvey', $survey->id)
        ->set("answers.{$questions[0]->id}", 'Great batch')
        ->set("answers.{$questions[1]->id}", 'Optics')
        ->set("answers.{$questions[2]->id}", 4)
        ->call('submit')
        ->assertHasNoErrors();

    $response = SurveyResponse::where('survey_id', $survey->id)->where('respondent_id', $this->viewer->id)->firstOrFail();
    expect($response->answers)->toHaveCount(3);
    expect($response->answers->firstWhere('survey_question_id', $questions[0]->id)->value)->toBe('Great batch');
    expect($response->answers->firstWhere('survey_question_id', $questions[1]->id)->value)->toBe('Optics');
    expect($response->answers->firstWhere('survey_question_id', $questions[2]->id)->value)->toBe(4);
});

it('updates the existing response instead of duplicating on a second submission', function () {
    $survey = makeSurveyWithQuestions($this->admin);
    $questions = $survey->questions()->get();

    $submit = fn (string|int $comment) => Livewire::actingAs($this->viewer)
        ->test(SurveyRunner::class)
        ->call('openSurvey', $survey->id)
        ->set("answers.{$questions[0]->id}", $comment)
        ->set("answers.{$questions[1]->id}", 'Mechanics')
        ->set("answers.{$questions[2]->id}", 3)
        ->call('submit');

    $submit('First answer');
    $submit('Updated answer');

    expect(SurveyResponse::where('survey_id', $survey->id)->where('respondent_id', $this->viewer->id)->count())->toBe(1);

    $response = SurveyResponse::where('survey_id', $survey->id)->where('respondent_id', $this->viewer->id)->firstOrFail();
    expect($response->answers)->toHaveCount(3);
    expect($response->answers->firstWhere('survey_question_id', $questions[0]->id)->value)->toBe('Updated answer');
});

it('no longer offers a survey the viewer already answered', function () {
    $survey = makeSurveyWithQuestions($this->admin);
    $questions = $survey->questions()->get();

    Livewire::actingAs($this->viewer)
        ->test(SurveyRunner::class)
        ->call('openSurvey', $survey->id)
        ->set("answers.{$questions[0]->id}", 'x')
        ->set("answers.{$questions[1]->id}", 'Mechanics')
        ->set("answers.{$questions[2]->id}", 3)
        ->call('submit');

    Livewire::actingAs($this->viewer)
        ->test(SurveyRunner::class)
        ->assertDontSee('Batch feedback');
});
