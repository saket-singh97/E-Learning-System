<?php

namespace App\Domains\Assessments\Filament\Resources\Questions\Pages\Concerns;

/**
 * questions.correct_answer holds a different shape per type (a matched
 * option string for mcq/true_false, free text for short_answer, a
 * {value, tolerance} pair for numerical) — one column, four widgets. The
 * form exposes one virtual field per type instead (dehydrated, but not a
 * real column), and this trait composes/decomposes between them and the
 * real `correct_answer`/`options` columns. Shared by CreateQuestion and
 * EditQuestion rather than duplicated, since both need it identically.
 */
trait NormalizesQuestionFormData
{
    /**
     * Virtual per-type fields -> real correct_answer/options columns,
     * before create/save.
     */
    protected function normalizeQuestionFormData(array $data): array
    {
        $type = $data['type'] ?? null;

        $data['options'] = match ($type) {
            'mcq' => $data['options'] ?? [],
            'true_false' => ['True', 'False'],
            default => null,
        };

        $data['correct_answer'] = match ($type) {
            // Cast explicitly: a purely-numeric option string ("4", common
            // for the numerical-MCQ style this app's own JEE/NEET audience
            // uses) becomes an array key when Select's options() builds
            // [option => option] from the tag list — PHP silently
            // normalises a numeric-string array key to a real int, and
            // Filament resolves the submitted state through that key. Left
            // uncast, correct_answer (int 4) would no longer match any
            // string in options (["3","4","5"]) for later grading.
            'mcq' => isset($data['correct_answer_mcq']) ? (string) $data['correct_answer_mcq'] : null,
            'true_false' => $data['correct_answer_true_false'] ?? null,
            'short_answer' => $data['correct_answer_short'] ?? '',
            'numerical' => [
                'value' => (float) ($data['correct_answer_numerical_value'] ?? 0),
                'tolerance' => (float) ($data['correct_answer_numerical_tolerance'] ?? 0),
            ],
            default => null,
        };

        unset(
            $data['correct_answer_mcq'],
            $data['correct_answer_true_false'],
            $data['correct_answer_short'],
            $data['correct_answer_numerical_value'],
            $data['correct_answer_numerical_tolerance'],
        );

        return $data;
    }

    /**
     * The reverse, before the edit form fills from an existing record —
     * without this, re-opening an MCQ question for edit would show its
     * correct-option Select empty instead of the actual saved answer.
     */
    protected function denormalizeQuestionFormData(array $data): array
    {
        $type = $data['type'] ?? null;
        $correctAnswer = $data['correct_answer'] ?? null;

        $data['correct_answer_mcq'] = $type === 'mcq' ? $correctAnswer : null;
        $data['correct_answer_true_false'] = $type === 'true_false' ? $correctAnswer : null;
        $data['correct_answer_short'] = $type === 'short_answer' ? $correctAnswer : null;
        $data['correct_answer_numerical_value'] = $type === 'numerical' ? ($correctAnswer['value'] ?? null) : null;
        $data['correct_answer_numerical_tolerance'] = $type === 'numerical' ? ($correctAnswer['tolerance'] ?? null) : null;

        return $data;
    }
}
