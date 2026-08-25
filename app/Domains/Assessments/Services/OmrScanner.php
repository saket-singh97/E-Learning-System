<?php

namespace App\Domains\Assessments\Services;

use App\Domains\Assessments\Models\OmrSheetScan;
use App\Domains\Assessments\Models\Question;
use GdImage;
use Illuminate\Support\Facades\Storage;

/**
 * S16 B15 (I11 pt.2, design doc §4 decision 8) — GD-based: samples pixel
 * darkness at each of the template's own known coordinates and resolves
 * the darkest bubble per question/per roll-number-digit column. No
 * skew/rotation correction — a flat, upright scan against the known grid
 * only (flagged limitation, design doc §5, a real production-accuracy
 * risk this slice's own test suite can't fully cover).
 *
 * Pure image reading only — no DB writes, no roll-number-to-enrolment
 * resolution, no grading. `ProcessOmrSheetScan` (the job) owns everything
 * after this.
 */
class OmrScanner
{
    /**
     * @return array{detected_roll_number: string, responses: array<int, mixed>}
     */
    public function scan(OmrSheetScan $sheetScan): array
    {
        $template = $sheetScan->template;
        $image = imagecreatefromstring(Storage::disk('local')->get($sheetScan->image_disk_path));

        $rollDigits = collect($template->layout['roll_number'])
            ->map(fn (array $column): ?int => $this->darkestBubble($image, $column['digits'], 'digit'));

        $questionIds = $template->question_ids ?? [];
        $questions = Question::whereIn('id', $questionIds)->get()->keyBy('id');

        $responses = [];

        foreach ($template->layout['questions'] as $questionLayout) {
            $questionId = $questionIds[$questionLayout['question_index']] ?? null;

            if ($questionId === null) {
                continue;
            }

            $optionIndex = $this->darkestBubble($image, $questionLayout['options'], 'option_index');
            $question = $questions->get($questionId);

            $responses[$questionId] = $optionIndex !== null ? ($question?->options[$optionIndex] ?? null) : null;
        }

        imagedestroy($image);

        return [
            'detected_roll_number' => $rollDigits->implode(''),
            'responses' => $responses,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $bubbles
     */
    protected function darkestBubble(GdImage $image, array $bubbles, string $valueKey): mixed
    {
        $best = null;
        $bestDarkness = -1.0;

        foreach ($bubbles as $bubble) {
            $darkness = $this->darknessAt($image, $bubble['x'], $bubble['y']);

            if ($darkness > $bestDarkness) {
                $bestDarkness = $darkness;
                $best = $bubble[$valueKey];
            }
        }

        return $best;
    }

    /**
     * Average "how dark" a small box around (x, y) is, 0 (white) to 255
     * (black) — a filled-in bubble reads much darker than an empty
     * circle's own thin outline.
     */
    protected function darknessAt(GdImage $image, int $x, int $y): float
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $radius = 3;
        $total = 0;
        $count = 0;

        for ($dx = -$radius; $dx <= $radius; $dx++) {
            for ($dy = -$radius; $dy <= $radius; $dy++) {
                $px = $x + $dx;
                $py = $y + $dy;

                if ($px < 0 || $py < 0 || $px >= $width || $py >= $height) {
                    continue;
                }

                $rgb = imagecolorat($image, $px, $py);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $luminance = 0.299 * $r + 0.587 * $g + 0.114 * $b;

                $total += 255 - $luminance;
                $count++;
            }
        }

        return $count > 0 ? $total / $count : 0.0;
    }
}
