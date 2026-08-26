<?php

namespace App\Domains\Comms\Jobs;

use App\Domains\Comms\Jobs\Concerns\EscapesCsvFormulas;
use App\Domains\Comms\Models\Survey;
use App\Domains\Comms\Models\SurveyResponse;
use App\Support\Tenancy\RebindsTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Same `ShouldQueue` + `RebindsTenant` + `Storage::disk('local')` +
 * `EscapesCsvFormulas` shape `ExportGradebookCsv`/`ExportCompletionRegister`
 * already established — one row per respondent (design doc §5), one column
 * per question in the survey's own position order.
 */
class ExportSurveyResponsesCsv implements ShouldQueue
{
    use Dispatchable, EscapesCsvFormulas, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public readonly int $surveyId,
        public readonly ?int $tenant_id,
    ) {}

    public function middleware(): array
    {
        return [new RebindsTenant];
    }

    public static function pathFor(int $surveyId): string
    {
        return "survey-exports/{$surveyId}.csv";
    }

    public function handle(): void
    {
        Storage::disk('local')->put(self::pathFor($this->surveyId), $this->buildCsv());
    }

    protected function buildCsv(): string
    {
        $survey = Survey::with('questions')->findOrFail($this->surveyId);
        $questions = $survey->questions;

        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, array_merge(
            ['Respondent name', 'Respondent email', 'Submitted at'],
            $questions->map(fn ($question) => $this->csvSafe($question->prompt))->all(),
        ));

        SurveyResponse::where('survey_id', $survey->id)
            ->with(['respondent', 'answers'])
            ->orderBy('submitted_at')
            ->each(function (SurveyResponse $response) use ($handle, $questions): void {
                $row = [
                    $this->csvSafe($response->respondent->name),
                    $this->csvSafe($response->respondent->email),
                    (string) $response->submitted_at?->toDateTimeString(),
                ];

                foreach ($questions as $question) {
                    $answer = $response->answers->firstWhere('survey_question_id', $question->id);
                    $value = $answer?->value;
                    $row[] = $this->csvSafe(is_array($value) ? implode(', ', $value) : (string) ($value ?? ''));
                }

                fputcsv($handle, $row);
            });

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
