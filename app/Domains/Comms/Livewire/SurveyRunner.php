<?php

namespace App\Domains\Comms\Livewire;

use App\Domains\Comms\Models\Survey;
use App\Domains\Comms\Models\SurveyAnswer;
use App\Domains\Comms\Models\SurveyQuestion;
use App\Domains\Comms\Models\SurveyResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * S19 N15 pt.2 — student/staff-facing survey response flow. Lists only
 * published surveys the viewer hasn't already answered (`SurveyResponse`
 * existing for the pair hides it); `submit()` still enforces the same
 * `unique(survey_id, respondent_id)` constraint via `updateOrCreate`, so a
 * direct re-submission updates the existing response rather than erroring
 * or duplicating — same precedent `BatchFeedbackForm` already established.
 */
class SurveyRunner extends Component
{
    public ?int $surveyId = null;

    /** @var array<int, mixed> keyed by SurveyQuestion id */
    public array $answers = [];

    public function availableSurveys(): Collection
    {
        $viewer = auth()->user();

        if (! $viewer) {
            return collect();
        }

        $answeredSurveyIds = SurveyResponse::where('respondent_id', $viewer->id)->pluck('survey_id');

        return Survey::where('status', 'published')
            ->whereNotIn('id', $answeredSurveyIds)
            ->orderByDesc('created_at')
            ->get();
    }

    public function openSurvey(int $surveyId): void
    {
        $survey = Survey::where('status', 'published')->find($surveyId);

        if ($survey !== null) {
            $this->surveyId = $survey->id;
            $this->answers = [];
        }
    }

    public function closeSurvey(): void
    {
        $this->surveyId = null;
        $this->answers = [];
    }

    /**
     * Re-verifies the survey is still published on every call, not just at
     * `openSurvey()` time — `surveyId` is a plain public Livewire property,
     * settable directly from the client, so a crafted request naming a
     * draft/closed or cross-tenant survey's id must not leak its question
     * text (cross-tenant is already blocked by `Survey`'s own
     * `BelongsToTenant` scope; draft/closed needs this explicit check).
     */
    public function questions(): Collection
    {
        if ($this->surveyId === null) {
            return collect();
        }

        $survey = Survey::where('status', 'published')->find($this->surveyId);

        if ($survey === null) {
            return collect();
        }

        return SurveyQuestion::where('survey_id', $survey->id)
            ->orderBy('position')
            ->get();
    }

    public function submit(): void
    {
        $viewer = auth()->user();

        if (! $viewer || $this->surveyId === null) {
            return;
        }

        $survey = Survey::where('status', 'published')->find($this->surveyId);

        if ($survey === null) {
            return;
        }

        $questions = $this->questions();

        $this->validate($this->rulesFor($questions));

        $response = SurveyResponse::updateOrCreate(
            ['survey_id' => $survey->id, 'respondent_id' => $viewer->id],
            ['submitted_at' => now()],
        );

        foreach ($questions as $question) {
            SurveyAnswer::updateOrCreate(
                ['survey_response_id' => $response->id, 'survey_question_id' => $question->id],
                ['value' => $this->answers[$question->id] ?? null],
            );
        }

        $this->surveyId = null;
        $this->answers = [];
    }

    /**
     * @param  Collection<int, SurveyQuestion>  $questions
     * @return array<string, string>
     */
    protected function rulesFor(Collection $questions): array
    {
        $rules = [];

        foreach ($questions as $question) {
            $key = "answers.{$question->id}";

            $rules[$key] = match ($question->type) {
                'text' => 'nullable|string|max:2000',
                'single_choice' => 'required|string|in:'.implode(',', $question->options['choices'] ?? []),
                'multi_choice' => 'required|array|min:1',
                'rating' => 'required|integer|min:1|max:'.($question->options['max'] ?? 5),
                default => 'nullable',
            };

            if ($question->type === 'multi_choice') {
                $rules["{$key}.*"] = 'string|in:'.implode(',', $question->options['choices'] ?? []);
            }
        }

        return $rules;
    }

    public function render(): View
    {
        $viewer = auth()->user();

        return view('comms::survey-runner', [
            'surveys' => $this->availableSurveys(),
            'questions' => $this->questions(),
            'canRespond' => $viewer !== null,
        ]);
    }
}
