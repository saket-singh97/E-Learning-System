<?php

namespace App\Domains\Comms\Services;

use App\Domains\Comms\Models\Survey;
use App\Domains\Comms\Models\SurveyAnswer;
use App\Domains\Comms\Models\SurveyQuestion;
use Illuminate\Support\Collection;

/**
 * S19 N15 pt.3 — computed at read time, no materialized aggregate column,
 * same posture `TestSeriesRankingService` (S16) already established for a
 * differently-shaped but equally read-heavy aggregate. `single_choice`/
 * `multi_choice` get a per-option tally; `rating` gets an average; `text`
 * has no meaningful numeric summary, so it's just a response count.
 */
class SurveyResponseAggregator
{
    /**
     * @return array<int, array{question: SurveyQuestion, summary: array}>
     */
    public function aggregateFor(Survey $survey): array
    {
        return $survey->questions->map(function (SurveyQuestion $question): array {
            $values = SurveyAnswer::where('survey_question_id', $question->id)->pluck('value');

            return [
                'question' => $question,
                'summary' => match ($question->type) {
                    'single_choice', 'multi_choice' => $this->tally($values, $question->options['choices'] ?? []),
                    'rating' => $this->average($values),
                    default => ['responses' => $values->filter(fn ($value) => $value !== null && $value !== '')->count()],
                },
            ];
        })->all();
    }

    /**
     * @param  Collection<int, mixed>  $values
     * @param  array<int, string>  $choices
     * @return array<string, int>
     */
    protected function tally(Collection $values, array $choices): array
    {
        $counts = array_fill_keys($choices, 0);

        foreach ($values as $value) {
            foreach ((array) $value as $choice) {
                if (array_key_exists($choice, $counts)) {
                    $counts[$choice]++;
                }
            }
        }

        return $counts;
    }

    /**
     * @param  Collection<int, mixed>  $values
     * @return array{average: float|null, responses: int}
     */
    protected function average(Collection $values): array
    {
        $numeric = $values->filter(fn ($value) => is_numeric($value))->map(fn ($value) => (float) $value);

        return [
            'average' => $numeric->isEmpty() ? null : round($numeric->avg(), 2),
            'responses' => $numeric->count(),
        ];
    }
}
