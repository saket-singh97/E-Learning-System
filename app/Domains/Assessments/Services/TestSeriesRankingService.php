<?php

namespace App\Domains\Assessments\Services;

use App\Domains\Assessments\Models\Quiz;
use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Models\TestSeries;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * S16 B13 (I10 pt.2, design doc §4 decision 6) — "All-India Rank" is
 * computed per-tenant, never cross-tenant (§2.2's own tenant-isolation
 * reasoning — a shared cross-tenant students table would contradict every
 * isolation guarantee this app enforces). Rank/percentile are computed on
 * demand, not materialized — same "no cached rank column, computed at
 * read time" posture GradebookService (S8 B5) already established for a
 * similarly bounded aggregate. A PHP-side sort, not a raw-SQL window
 * function — this app's security baseline forbids raw-SQL query methods
 * in `app/Domains/`, checked by a literal source-text scan, so even this
 * docblock's own wording has to say it without the literal method names.
 */
class TestSeriesRankingService
{
    /**
     * Every student who submitted at least one attempt on any quiz in the
     * series, scoped to `$viewer`'s own tenant explicitly (not trusted to
     * `CurrentTenant`'s own binding) — belt-and-suspenders against a
     * same-named series existing in a different tenant. A student who
     * attempted only some of the series' quizzes is scored on their best
     * attempt per quiz they *did* attempt, summed — no zero-fill penalty
     * for a quiz never attempted (build-time call, plan's own B13 note:
     * "best-effort partial participation").
     *
     * Percentile is "beat X% of participants" — the fraction of other
     * participants with a strictly lower total score, not the more
     * common "at or below" definition, so the very top scorer's own
     * percentile reads as *less than* 100 whenever anyone else tied them
     * (a documented judgment call, not pinned anywhere else in this app).
     *
     * @return Collection<int, array{user_id: int, total_score: float, rank: int, percentile: float}>
     */
    public function rankingsFor(TestSeries $series, User $viewer): Collection
    {
        $quizIds = Quiz::where('test_series_id', $series->id)
            ->where('tenant_id', $viewer->tenant_id)
            ->pluck('id');

        if ($quizIds->isEmpty()) {
            return collect();
        }

        $totalScoreByUser = QuizAttempt::where('tenant_id', $viewer->tenant_id)
            ->whereIn('quiz_id', $quizIds)
            ->where('status', '!=', 'in_progress')
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $attempts): float => $attempts
                ->groupBy('quiz_id')
                ->map(fn (Collection $perQuiz): float => (float) $perQuiz->max('score'))
                ->sum());

        $totalParticipants = $totalScoreByUser->count();

        return $totalScoreByUser
            ->map(fn (float $totalScore, int $userId): array => ['user_id' => $userId, 'total_score' => $totalScore])
            ->values()
            ->sortByDesc('total_score')
            ->values()
            ->map(function (array $row) use ($totalScoreByUser, $totalParticipants): array {
                $rank = 1 + $totalScoreByUser->filter(fn (float $score): bool => $score > $row['total_score'])->count();
                $countBelow = $totalScoreByUser->filter(fn (float $score): bool => $score < $row['total_score'])->count();
                $percentile = $totalParticipants > 0 ? round($countBelow / $totalParticipants * 100, 1) : 0.0;

                return $row + ['rank' => $rank, 'percentile' => $percentile];
            });
    }
}
