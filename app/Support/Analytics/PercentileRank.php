<?php

namespace App\Support\Analytics;

use Illuminate\Support\Collection;

/**
 * S24 B8 — extracted from `TestSeriesRankingService::rankingsFor()`'s own
 * inline formula so `PeerComparisonService` (Analytics domain) can reuse
 * the identical math without either a 3rd inline copy or a cross-domain
 * call into an Assessments-domain *service* (CLAUDE.md's "domains talk
 * through Events only" rule) — a neutral, stateless `Support/` helper is
 * the shape that answers the plan's own "cleanest reuse point" question.
 *
 * "Beat X%" semantics — the fraction of other participants with a
 * strictly lower score, not the more common "at or below" definition, so
 * a tied top scorer's own percentile reads as *less than* 100 whenever
 * anyone else tied them (`TestSeriesRankingService`'s own documented
 * judgment call, carried over unchanged).
 */
class PercentileRank
{
    /**
     * @param  Collection<int, float>  $allScores  Every participant's own score, $mine included.
     */
    public static function of(float $mine, Collection $allScores): float
    {
        $total = $allScores->count();

        if ($total === 0) {
            return 0.0;
        }

        $countBelow = $allScores->filter(fn (float $score): bool => $score < $mine)->count();

        return round($countBelow / $total * 100, 1);
    }
}
