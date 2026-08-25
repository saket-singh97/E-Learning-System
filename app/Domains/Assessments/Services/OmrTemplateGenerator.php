<?php

namespace App\Domains\Assessments\Services;

use App\Domains\Assessments\Models\OmrTemplate;
use App\Domains\Assessments\Models\Quiz;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * S16 B14 (I11 pt.1) — dompdf reuse, same posture `IdCardGenerator` (S15)
 * already took rather than adding a third PDF package. One template per
 * quiz, regenerated in place (`updateOrCreate` on `quiz_id`) so re-running
 * the Admin-panel action after a blueprint change doesn't pile up stale
 * rows.
 */
class OmrTemplateGenerator
{
    public function generate(Quiz $quiz, int $questionCount, int $optionsPerQuestion, int $rollNumberDigits): OmrTemplate
    {
        $layout = OmrTemplate::generateLayout($questionCount, $optionsPerQuestion, $rollNumberDigits);

        $template = OmrTemplate::updateOrCreate(
            ['quiz_id' => $quiz->id],
            [
                'question_count' => $questionCount,
                'options_per_question' => $optionsPerQuestion,
                'roll_number_digits' => $rollNumberDigits,
                'layout' => $layout,
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
