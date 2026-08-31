<?php

namespace App\Domains\Analytics\Services;

use App\Domains\Assessments\Models\QuizAnswer;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Models\User;
use App\Support\Analytics\PercentileRank;
use Illuminate\Support\Collection;

/**
 * S24 U24 pt.1 — "you're in the top N% of your batch on {concept}"
 * (design §2.1). Same cross-domain-*model*-read shape
 * `RewatchHeatmapService`/`DropOffService` already establish for reading
 * another domain's Eloquent models directly, never that domain's own
 * service class — `QuizAnswer`/`Question` here (Assessments), same as
 * `WeakTopicDetectionService` itself already reads `QuizAnswer` from
 * inside the AI domain. `WeakTopicDetectionService::classWideWeakTopicsFor()`
 * only returns *weak* (ratio < 0.6) concepts aggregated across the class —
 * not a per-student raw score for an arbitrary concept — so it isn't
 * actually callable here; this method re-derives the same per-student
 * per-concept ratio `WeakTopicDetectionService::weakTopicsFor()` computes
 * (concept normalized in PHP after fetching, not a SQL string filter,
 * same reasoning as that method's own `normalize()`), unfiltered. The
 * percentile math itself is the one piece genuinely shared — extracted to
 * `App\Support\Analytics\PercentileRank` (S24 B8) rather than a 3rd inline
 * copy alongside `TestSeriesRankingService::rankingsFor()`'s own.
 */
class PeerComparisonService
{
    public function percentileFor(User $student, Batch $batch, string $concept): ?float
    {
        $studentIds = Enrolment::where('batch_id', $batch->id)
            ->where('status', 'active')
            ->pluck('user_id');

        $normalizedConcept = strtolower(trim($concept));

        $ratioByUser = QuizAnswer::query()
            ->whereNotNull('is_correct')
            ->whereHas('attempt', fn ($attemptQuery) => $attemptQuery->whereIn('user_id', $studentIds))
            ->whereHas('question', fn ($questionQuery) => $questionQuery
                ->where('course_id', $batch->course_id)
                ->whereNotNull('concept'))
            ->with(['attempt:id,user_id', 'question:id,concept'])
            ->get()
            ->filter(fn (QuizAnswer $answer): bool => strtolower(trim($answer->question->concept)) === $normalizedConcept)
            ->groupBy(fn (QuizAnswer $answer): int => $answer->attempt->user_id)
            ->map(fn (Collection $answers): float => $answers->where('is_correct', true)->count() / $answers->count());

        // Undefined, not zero — a lone data point (the student's own, no
        // classmate to compare against) has no peer group to rank among,
        // same as the student never having answered this concept at all.
        if ($ratioByUser->count() < 2 || ! $ratioByUser->has($student->id)) {
            return null;
        }

        return PercentileRank::of($ratioByUser->get($student->id), $ratioByUser->values());
    }
}
