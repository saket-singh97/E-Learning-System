<?php

namespace App\Domains\Assessments\Services;

use App\Domains\Assessments\Models\DailyPracticeSet;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\Lesson;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * U3 — aggregates a batch's own taught-lesson questions into one daily
 * practice set. "Taught" = a BatchSession actually scheduled for this batch,
 * with a lesson attached, on or before practice_date (design doc B3
 * checklist) — a session with no lesson_id is a bare timetable slot, not
 * teachable content. Reuses QuestionGenerator::fromSeedQuestions() per
 * lesson directly (design doc §4 decision 2), never duplicates it.
 *
 * Idempotent by construction, not by an overwrite: a second call for a
 * batch+date that's already generated returns the existing row unchanged
 * rather than promoting a fresh, duplicate batch of draft Questions
 * underneath it — daily_practice_sets' own unique(batch_id, practice_date)
 * is what a caller relies on already existing for that day, not a trigger
 * to regenerate it.
 */
class DailyPracticeSetGenerator
{
    public function __construct(
        private readonly QuestionGenerator $questionGenerator,
    ) {}

    public function generateFor(Batch $batch, CarbonInterface|string $practiceDate): DailyPracticeSet
    {
        $practiceDate = Carbon::parse($practiceDate)->toDateString();

        $existing = DailyPracticeSet::where('batch_id', $batch->id)
            ->where('practice_date', $practiceDate)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $lessonIds = BatchSession::where('batch_id', $batch->id)
            ->whereNotNull('lesson_id')
            ->whereDate('starts_at', '<=', $practiceDate)
            ->pluck('lesson_id')
            ->unique();

        $questionIds = Lesson::whereIn('id', $lessonIds)->get()
            ->flatMap(fn (Lesson $lesson) => $this->questionGenerator->fromSeedQuestions($lesson))
            ->pluck('id')
            ->values()
            ->all();

        return DailyPracticeSet::create([
            'batch_id' => $batch->id,
            'practice_date' => $practiceDate,
            'question_ids' => $questionIds,
        ]);
    }
}
