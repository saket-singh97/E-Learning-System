<?php

namespace App\Domains\Assessments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once by `QuizAttemptService::submit()`, right after a genuine
 * in_progress -> submitted transition — the same method throws
 * `QuizAttemptAlreadySubmittedException` on any later call for the same
 * attempt, so this can never fire twice for one attempt (S19 B13, no
 * double-XP-award on a retried job). One-way-ignorance shape, same as
 * `AttendanceRecorded` — Analytics listens without Assessments knowing it
 * exists.
 */
class QuizAttemptSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly int $tenantId,
        public readonly int $quizAttemptId,
    ) {}
}
