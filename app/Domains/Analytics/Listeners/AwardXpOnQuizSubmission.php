<?php

namespace App\Domains\Analytics\Listeners;

use App\Domains\Analytics\Services\GamificationService;
use App\Domains\Assessments\Events\QuizAttemptSubmitted;

class AwardXpOnQuizSubmission
{
    public function __construct(private readonly GamificationService $gamification) {}

    public function handle(QuizAttemptSubmitted $event): void
    {
        $this->gamification->awardXp($event->userId, $event->tenantId, GamificationService::XP_QUIZ_SUBMISSION);
    }
}
