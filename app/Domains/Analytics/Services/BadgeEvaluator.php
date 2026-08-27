<?php

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\Models\Badge;
use App\Domains\Analytics\Models\GamificationProfile;
use App\Domains\Analytics\Models\StudentBadge;

/**
 * S19 N18 pt.1 (design doc §4 decision 8) — runs after every XP award,
 * checking the tenant's own badge catalog against the updated profile. Not
 * one giant if/else per badge type: a badge picks exactly one of
 * `xp_threshold` or `criteria['streak_days']`, evaluated generically.
 */
class BadgeEvaluator
{
    public function evaluate(GamificationProfile $profile): void
    {
        $alreadyAwardedBadgeIds = StudentBadge::where('user_id', $profile->user_id)->pluck('badge_id');

        Badge::where('tenant_id', $profile->tenant_id)
            ->whereNotIn('id', $alreadyAwardedBadgeIds)
            ->get()
            ->each(function (Badge $badge) use ($profile): void {
                if ($this->criteriaMet($badge, $profile) && ! StudentBadge::where('user_id', $profile->user_id)->where('badge_id', $badge->id)->exists()) {
                    // withoutEvents + explicit tenant_id, not a plain create():
                    // this can run with no CurrentTenant bound (GamificationService's
                    // own docblock explains why — RecordVideoCoverageAttendance
                    // clears it before dispatching AttendanceRecorded), and
                    // BelongsToTenant's creating() hook would otherwise throw.
                    // The unique (user_id, badge_id) index is the real guarantee
                    // against a double-award; this exists-check is just belt-
                    // and-suspenders against the query-then-insert race.
                    StudentBadge::withoutEvents(fn () => StudentBadge::create([
                        'tenant_id' => $profile->tenant_id,
                        'user_id' => $profile->user_id,
                        'badge_id' => $badge->id,
                        'awarded_at' => now(),
                    ]));
                }
            });
    }

    protected function criteriaMet(Badge $badge, GamificationProfile $profile): bool
    {
        if ($badge->xp_threshold !== null) {
            return $profile->xp_total >= $badge->xp_threshold;
        }

        $streakDays = $badge->criteria['streak_days'] ?? null;

        return $streakDays !== null && $profile->current_streak_days >= $streakDays;
    }
}
