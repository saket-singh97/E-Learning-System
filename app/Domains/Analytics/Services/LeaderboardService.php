<?php

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\Models\GamificationProfile;
use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use Illuminate\Support\Collection;

/**
 * S19 N18 pt.2 (design doc §4 decision 9) — per-batch leaderboard, computed
 * at read time, no materialized rank column — same posture
 * `TestSeriesRankingService` (S16) already established. Tenant-level opt-out
 * (`tenants.gamification_leaderboard_enabled`) is the caller's concern, not
 * this service's — it only ever answers "how does this batch rank", the
 * widget decides whether to call it at all.
 *
 * @return Collection<int, array{user_id: int, name: string, xp_total: int, level: int, rank: int}>
 */
class LeaderboardService
{
    public function rankingsFor(Batch $batch): Collection
    {
        $studentIds = Enrolment::where('tenant_id', $batch->tenant_id)
            ->where('batch_id', $batch->id)
            ->where('status', 'active')
            ->pluck('user_id');

        if ($studentIds->isEmpty()) {
            return collect();
        }

        return GamificationProfile::with('user')
            ->where('tenant_id', $batch->tenant_id)
            ->whereIn('user_id', $studentIds)
            ->get()
            ->sortByDesc('xp_total')
            ->values()
            ->map(fn (GamificationProfile $profile, int $index): array => [
                'user_id' => $profile->user_id,
                'name' => $profile->user->name,
                'xp_total' => $profile->xp_total,
                'level' => $profile->level,
                'rank' => $index + 1,
            ]);
    }
}
