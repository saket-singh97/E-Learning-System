<?php

namespace App\Domains\Content\Services;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Deterministic behind-schedule list (design doc §4 decision 7) — no
 * pacing algorithm, no AI call. "Taught" reuses the same
 * BatchSession.lesson_id-on/before-today definition
 * DailyPracticeSetGenerator (S22 B3) already established for "taught so
 * far"; this adds ordering (oldest-taught-first) since a practice set
 * doesn't care about lesson order but a catch-up plan does.
 */
class CatchUpPlanService
{
    public function __construct(
        private readonly LessonCompletion $completion,
        private readonly LessonAccess $access,
    ) {}

    /**
     * @return Collection<int, Lesson> oldest-taught-first, already-complete
     *                                 and not-yet-released lessons excluded
     */
    public function planFor(User $student, Batch $batch): Collection
    {
        $taughtLessonIds = BatchSession::where('batch_id', $batch->id)
            ->whereNotNull('lesson_id')
            ->whereDate('starts_at', '<=', now())
            ->orderBy('starts_at')
            ->pluck('lesson_id')
            ->unique();

        return $taughtLessonIds
            ->map(fn (int $lessonId): ?Lesson => Lesson::find($lessonId))
            ->filter()
            ->filter(fn (Lesson $lesson): bool => $this->access->isVisibleTo($lesson, $student))
            ->reject(fn (Lesson $lesson): bool => $this->completion->isComplete($lesson, $student))
            ->values();
    }
}
