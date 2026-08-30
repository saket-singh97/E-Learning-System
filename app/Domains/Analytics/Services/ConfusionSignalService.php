<?php

namespace App\Domains\Analytics\Services;

use App\Domains\AI\Models\DoubtChatMessage;
use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\Lesson;
use Illuminate\Support\Collection;

/**
 * U9 pt.1 — composes three existing read-only signals into one ranked
 * "re-teach this" list per batch (design doc §2.3: a composition of
 * existing services' output, sorted, not a new model): RewatchHeatmapService's
 * hottest replayed segment, DoubtChatMessage volume, and quiz question
 * failure rate (same whereNotNull('is_correct') correct/total query shape
 * WeakTopicDetectionService already uses, scoped to the lesson's own Quiz
 * instead of a course-wide concept). No new schema (design §4 decision 6)
 * — computed on request, same posture RewatchHeatmapService/DropOffService
 * already take.
 */
class ConfusionSignalService
{
    public function __construct(private readonly RewatchHeatmapService $heatmap) {}

    /**
     * @return Collection<int, array{lesson: Lesson, doubt_count: int, hottest_rewatch: ?array, failure_rate: float, score: float}>
     */
    public function rankedFor(Batch $batch): Collection
    {
        $lessons = $this->taughtLessonsFor($batch);

        if ($lessons->isEmpty()) {
            return collect();
        }

        $rows = $lessons->map(fn (Lesson $lesson): array => [
            'lesson' => $lesson,
            'doubt_count' => DoubtChatMessage::where('lesson_id', $lesson->id)->count(),
            'hottest_rewatch' => $this->heatmap->heatmapFor($lesson)->first(),
            'failure_rate' => $this->failureRateFor($lesson),
        ]);

        // Min-max normalization against the batch's own worst offender — a
        // lesson with zero of a signal contributes zero for it rather than
        // being excluded, matching the "ranks last, not excluded" rule.
        $maxDoubts = $rows->max(fn (array $row): int => $row['doubt_count']) ?: 1;
        $maxReplays = $rows->max(fn (array $row): int => $row['hottest_rewatch']['replay_count'] ?? 0) ?: 1;

        return $rows
            ->map(fn (array $row): array => $row + [
                'score' => round(
                    ($row['doubt_count'] / $maxDoubts)
                    + (($row['hottest_rewatch']['replay_count'] ?? 0) / $maxReplays)
                    + $row['failure_rate'],
                    4,
                ),
            ])
            // Stable, deterministic order: highest score first, lesson id
            // breaks ties so two equally-quiet lessons don't reorder
            // between calls.
            ->sort(fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['lesson']->id <=> $b['lesson']->id)
            ->values();
    }

    /**
     * Same taught-lesson definition CatchUpPlanService/DailyPracticeSetGenerator
     * (S22 B3) already established — a BatchSession with a non-null
     * lesson_id on/before today.
     */
    protected function taughtLessonsFor(Batch $batch): Collection
    {
        $lessonIds = BatchSession::where('batch_id', $batch->id)
            ->whereNotNull('lesson_id')
            ->whereDate('starts_at', '<=', now())
            ->pluck('lesson_id')
            ->unique();

        return Lesson::whereIn('id', $lessonIds)->get();
    }

    protected function failureRateFor(Lesson $lesson): float
    {
        $quizIds = Quiz::where('lesson_id', $lesson->id)->pluck('id');

        if ($quizIds->isEmpty()) {
            return 0.0;
        }

        $answers = QuizAnswer::whereNotNull('is_correct')
            ->whereHas('attempt', fn ($query) => $query->whereIn('quiz_id', $quizIds))
            ->get();

        if ($answers->isEmpty()) {
            return 0.0;
        }

        return round($answers->where('is_correct', false)->count() / $answers->count(), 2);
    }
}
