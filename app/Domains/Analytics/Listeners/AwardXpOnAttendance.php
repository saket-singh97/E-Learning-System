<?php

namespace App\Domains\Analytics\Listeners;

use App\Domains\Analytics\Services\GamificationService;
use App\Domains\Batches\Events\AttendanceRecorded;

/**
 * S19 B13 — only a 'present' mark earns XP; an 'absent' record (the other
 * status this same event carries, per `SendAbsenceAlert`) earns nothing.
 */
class AwardXpOnAttendance
{
    public function __construct(private readonly GamificationService $gamification) {}

    public function handle(AttendanceRecorded $event): void
    {
        if ($event->status !== 'present') {
            return;
        }

        $this->gamification->awardXp($event->userId, $event->tenantId, GamificationService::XP_ATTENDANCE);
    }
}
