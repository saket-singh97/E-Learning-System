<?php

namespace App\Domains\Analytics\Listeners;

use App\Domains\Analytics\Services\GamificationService;
use App\Domains\Assignments\Events\AssignmentSubmitted;

class AwardXpOnAssignmentSubmission
{
    public function __construct(private readonly GamificationService $gamification) {}

    public function handle(AssignmentSubmitted $event): void
    {
        $this->gamification->awardXp($event->userId, $event->tenantId, GamificationService::XP_ASSIGNMENT_SUBMISSION);
    }
}
