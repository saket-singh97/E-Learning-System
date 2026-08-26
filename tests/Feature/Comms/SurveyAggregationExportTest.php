<?php

use App\Domains\Comms\Jobs\ExportSurveyResponsesCsv;
use App\Domains\Comms\Models\Survey;
use App\Domains\Comms\Models\SurveyAnswer;
use App\Domains\Comms\Models\SurveyQuestion;
use App\Domains\Comms\Models\SurveyResponse;
use App\Domains\Comms\Services\SurveyResponseAggregator;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->survey = Survey::create(['title' => 'Batch feedback', 'status' => 'published', 'created_by_user_id' => $this->admin->id]);

    $this->choiceQuestion = SurveyQuestion::create(['survey_id' => $this->survey->id, 'prompt' => 'Favourite topic?', 'type' => 'single_choice', 'options' => ['choices' => ['Mechanics', 'Optics']], 'position' => 0]);
    $this->ratingQuestion = SurveyQuestion::create(['survey_id' => $this->survey->id, 'prompt' => 'Rate the pace', 'type' => 'rating', 'options' => ['max' => 5], 'position' => 1]);
    $this->textQuestion = SurveyQuestion::create(['survey_id' => $this->survey->id, 'prompt' => 'Comments?', 'type' => 'text', 'position' => 2]);

    $respondent1 = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Asha Kumar', 'email' => 'asha@example.com']);
    $respondent2 = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Ravi Shah', 'email' => 'ravi@example.com']);

    foreach ([[$respondent1, 'Mechanics', 4, 'Great batch'], [$respondent2, 'Mechanics', 2, null]] as [$respondent, $choice, $rating, $comment]) {
        $response = SurveyResponse::create(['survey_id' => $this->survey->id, 'respondent_id' => $respondent->id, 'submitted_at' => now()]);
        SurveyAnswer::create(['survey_response_id' => $response->id, 'survey_question_id' => $this->choiceQuestion->id, 'value' => $choice]);
        SurveyAnswer::create(['survey_response_id' => $response->id, 'survey_question_id' => $this->ratingQuestion->id, 'value' => $rating]);
        SurveyAnswer::create(['survey_response_id' => $response->id, 'survey_question_id' => $this->textQuestion->id, 'value' => $comment]);
    }
});

it('tallies choice answers per option', function () {
    $summary = collect(app(SurveyResponseAggregator::class)->aggregateFor($this->survey))
        ->firstWhere('question.id', $this->choiceQuestion->id)['summary'];

    expect($summary)->toBe(['Mechanics' => 2, 'Optics' => 0]);
});

it('averages rating answers and counts text responses', function () {
    $aggregate = collect(app(SurveyResponseAggregator::class)->aggregateFor($this->survey));

    $ratingSummary = $aggregate->firstWhere('question.id', $this->ratingQuestion->id)['summary'];
    expect($ratingSummary)->toBe(['average' => 3.0, 'responses' => 2]);

    $textSummary = $aggregate->firstWhere('question.id', $this->textQuestion->id)['summary'];
    expect($textSummary)->toBe(['responses' => 1]);
});

it('never mixes another tenants survey into the aggregate', function () {
    $otherTenant = Tenant::factory()->create(['subdomain' => 'chemistry']);
    app(CurrentTenant::class)->set($otherTenant);
    $otherAdmin = User::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherSurvey = Survey::create(['title' => 'Chemistry feedback', 'status' => 'published', 'created_by_user_id' => $otherAdmin->id]);
    SurveyQuestion::create(['survey_id' => $otherSurvey->id, 'prompt' => 'Rate it', 'type' => 'rating', 'options' => ['max' => 5], 'position' => 0]);
    app(CurrentTenant::class)->set($this->tenant);

    $aggregate = app(SurveyResponseAggregator::class)->aggregateFor($this->survey);

    expect(collect($aggregate)->pluck('question.id'))->not->toContain($otherSurvey->questions()->first()->id ?? -1);
});

it('exports one CSV row per respondent with every question as a column', function () {
    ExportSurveyResponsesCsv::dispatchSync($this->survey->id, $this->tenant->id);

    $path = ExportSurveyResponsesCsv::pathFor($this->survey->id);
    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $csv = Storage::disk('local')->get($path);
    expect($csv)->toContain('Favourite topic?');
    expect($csv)->toContain('Rate the pace');
    expect($csv)->toContain('Comments?');
    expect($csv)->toContain('Asha Kumar');
    expect($csv)->toContain('asha@example.com');
    expect($csv)->toContain('Mechanics');
    expect($csv)->toContain('Great batch');
    expect(substr_count($csv, "\n"))->toBe(3); // header + 2 respondents (+ trailing newline)

    Storage::disk('local')->delete($path);
});
