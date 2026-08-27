<?php

namespace App\Domains\Analytics\Listeners;

use App\Domains\Analytics\Services\GamificationService;
use App\Domains\Batches\Events\AttendanceRecorded;
use App\Models\User;

/**
 * S19 B13 — only a 'present' mark earns XP; an 'absent' record (the other
 * status this same event carries, per `SendAbsenceAlert`) earns nothing.
 *
 * Gamification is a student-facing feature (per `GamificationWidget`'s own
 * `Enrolment`-based batch picker) — `AttendanceRecorded` is also fired by
 * `BatchSessionsRelationManager`'s own `markMyAttendance` action for the
 * instructor recording their own presence (S16 B5), so this must not award
 * XP to a non-student regardless of `status`.
 */
class AwardXpOnAttendance
{
    public function __construct(private readonly GamificationService $gamification) {}

    public function handle(AttendanceRecorded $event): void
    {
        if ($event->status !== 'present') {
            return;
        }

        if (! User::find($event->userId)?->hasRole('student')) {
            return;
        }

        $this->gamification->awardXp($event->userId, $event->tenantId, GamificationService::XP_ATTENDANCE);
    }
}
