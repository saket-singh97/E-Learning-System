<?php

namespace App\Domains\Assessments\Services;

use App\Domains\Assessments\Models\OmrTemplate;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Services\QuizAttemptService;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * S16 B14/B15 (I11) — dompdf reuse, same posture `IdCardGenerator` (S15)
 * already took rather than adding a third PDF package. One template per
 * quiz, regenerated in place (`updateOrCreate` on `quiz_id`) so re-running
 * the Admin-panel action after a blueprint change doesn't pile up stale
 * rows. Draws a fixed `question_ids` list via the same
 * `QuizAttemptService::draw()` an online attempt's own `start()` uses —
 * the printed sheet's questions must stay fixed for a later scan to grade
 * against, not a fresh random draw each time.
 */
class OmrTemplateGenerator
{
    public function __construct(private readonly QuizAttemptService $quizAttemptService) {}

    public function generate(Quiz $quiz, int $questionCount, int $optionsPerQuestion, int $rollNumberDigits): OmrTemplate
    {
        $layout = OmrTemplate::generateLayout($questionCount, $optionsPerQuestion, $rollNumberDigits);
        $questionIds = array_slice($this->quizAttemptService->draw($quiz, []), 0, $questionCount);

        $template = OmrTemplate::updateOrCreate(
            ['quiz_id' => $quiz->id],
            [
                'question_count' => $questionCount,
                'options_per_question' => $optionsPerQuestion,
                'roll_number_digits' => $rollNumberDigits,
                'layout' => $layout,
                'question_ids' => $questionIds,
            ],
        );

        $diskPath = "omr-templates/{$template->tenant_id}/{$template->id}.pdf";

        Pdf::loadView('assessments.omr-template-pdf', [
            'quiz' => $quiz,
            'template' => $template,
        ])->save($diskPath, 'local');

        return $template;
    }
}
