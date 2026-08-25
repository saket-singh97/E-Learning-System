<?php

namespace App\Domains\Assessments\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * S16 B14 (I11 pt.1, design doc §4 decision 8) — a fixed, app-generated
 * bubble template, not a freeform designer. `layout` is computed once at
 * creation time by `generateLayout()` from `question_count`/
 * `options_per_question`/`roll_number_digits` — a deterministic grid this
 * app itself lays out and the institute prints, never hand-authored.
 */
class OmrTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'quiz_id',
        'question_count',
        'options_per_question',
        'roll_number_digits',
        'layout',
    ];

    protected function casts(): array
    {
        return [
            'question_count' => 'integer',
            'options_per_question' => 'integer',
            'roll_number_digits' => 'integer',
            'layout' => 'array',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(OmrSheetScan::class);
    }

    /**
     * Pure function of the three counts — same input always produces the
     * same layout, so this needs no persisted "designer state" of its
     * own. Roll-number digit bubbles sit above the question grid, with
     * the question block's own top margin computed from the roll-number
     * block's own height, so the two sections' coordinate ranges never
     * overlap regardless of how many roll-number digits are configured.
     *
     * @return array{
     *     question_count: int,
     *     options_per_question: int,
     *     roll_number_digits: int,
     *     roll_number: array<int, array{digit_index: int, digits: array<int, array{digit: int, x: int, y: int}>}>,
     *     questions: array<int, array{question_index: int, options: array<int, array{option_index: int, x: int, y: int}>}>,
     * }
     */
    public static function generateLayout(int $questionCount, int $optionsPerQuestion, int $rollNumberDigits): array
    {
        $digitColWidth = 30;
        $digitRowHeight = 25;
        $rollLeftMargin = 60;
        $rollTopMargin = 40;

        $rollNumber = [];

        for ($digitIndex = 0; $digitIndex < $rollNumberDigits; $digitIndex++) {
            $digits = [];

            for ($digit = 0; $digit <= 9; $digit++) {
                $digits[] = [
                    'digit' => $digit,
                    'x' => $rollLeftMargin + $digitIndex * $digitColWidth,
                    'y' => $rollTopMargin + $digit * $digitRowHeight,
                ];
            }

            $rollNumber[] = ['digit_index' => $digitIndex, 'digits' => $digits];
        }

        $rollNumberBlockBottom = $rollNumberDigits > 0 ? $rollTopMargin + 9 * $digitRowHeight : $rollTopMargin;
        $sectionGap = 60;
        $questionsTopMargin = $rollNumberBlockBottom + $sectionGap;
        $questionsLeftMargin = 60;
        $optionSpacing = 30;
        $questionRowHeight = 40;

        $questions = [];

        for ($questionIndex = 0; $questionIndex < $questionCount; $questionIndex++) {
            $options = [];

            for ($optionIndex = 0; $optionIndex < $optionsPerQuestion; $optionIndex++) {
                $options[] = [
                    'option_index' => $optionIndex,
                    'x' => $questionsLeftMargin + $optionIndex * $optionSpacing,
                    'y' => $questionsTopMargin + $questionIndex * $questionRowHeight,
                ];
            }

            $questions[] = ['question_index' => $questionIndex, 'options' => $options];
        }

        return [
            'question_count' => $questionCount,
            'options_per_question' => $optionsPerQuestion,
            'roll_number_digits' => $rollNumberDigits,
            'roll_number' => $rollNumber,
            'questions' => $questions,
        ];
    }
}
