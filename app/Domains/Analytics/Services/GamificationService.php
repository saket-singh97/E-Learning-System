<?php

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\Models\GamificationProfile;
use Carbon\Carbon;

/**
 * S19 N18 pt.1 (design doc §4 decision 8) — the single write path for XP and
 * streaks. Every qualifying domain event routes through here via a thin
 * listener; no existing domain service gains gamification logic inline.
 *
 * XP amounts are fixed per source, not tenant-configurable yet (same
 * "single hardcoded value, no per-institute override" starting point H10
 * took before S16 gave it one) — a real but unrequested refinement.
 */
class GamificationService
{
    public const XP_ATTENDANCE = 10;

    public const XP_QUIZ_SUBMISSION = 20;

    public const XP_ASSIGNMENT_SUBMISSION = 25;

    /** One level per 100 XP — the simplest curve that satisfies "a level exists", not tuned against any design target. */
    public const LEVEL_XP_STEP = 100;

    public function __construct(private readonly BadgeEvaluator $badgeEvaluator) {}

    public function awardXp(int $userId, int $tenantId, int $xpAmount): GamificationProfile
    {
        // Not a plain firstOrCreate(): BelongsToTenant's creating() hook reads
        // CurrentTenant::id() unconditionally, which throws when unbound —
        // and this runs as part of AttendanceRecorded's own listener chain,
        // fired from inside RecordVideoCoverageAttendance's job, which
        // deliberately clears CurrentTenant before it ever gets here (same
        // reasoning as that job's own AttendanceRecord::withoutEvents() call).
        // $tenantId is already known from the event, so it's set explicitly.
        $profile = GamificationProfile::where('user_id', $userId)->first();

        if ($profile === null) {
            $profile = GamificationProfile::withoutEvents(fn (): GamificationProfile => GamificationProfile::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'xp_total' => 0,
                'level' => 1,
                'current_streak_days' => 0,
                'longest_streak_days' => 0,
            ]));
        }

        $newXpTotal = $profile->xp_total + $xpAmount;

        $profile->update([
            'xp_total' => $newXpTotal,
            'level' => intdiv($newXpTotal, self::LEVEL_XP_STEP) + 1,
            ...$this->streakUpdate($profile),
        ]);

        $this->badgeEvaluator->evaluate($profile->fresh());

        return $profile->fresh();
    }

    /**
     * Streak only moves once per calendar day — a second qualifying event on
     * the same day still awards XP (above) but does not double-increment the
     * streak. Consecutive day -> +1; a gap (or first-ever activity) -> reset
     * to 1; longest_streak_days only ever grows.
     *
     * @return array<string, mixed>
     */
    protected function streakUpdate(GamificationProfile $profile): array
    {
        $today = Carbon::today();
        $lastActivity = $profile->last_activity_date;

        if ($lastActivity !== null && $lastActivity->isSameDay($today)) {
            return [];
        }

        $currentStreak = ($lastActivity !== null && $lastActivity->isSameDay($today->clone()->subDay()))
            ? $profile->current_streak_days + 1
            : 1;

        return [
            'current_streak_days' => $currentStreak,
            'longest_streak_days' => max($profile->longest_streak_days, $currentStreak),
            'last_activity_date' => $today,
        ];
    }
}
