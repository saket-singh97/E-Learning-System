<?php

namespace App\Domains\Batches\Policies;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Models\User;

/**
 * Same "genuine participant only" gate CourseReviewPolicy::canReview()
 * already establishes (design doc §3.1) — active or expired (naturally
 * ran its course), not pending_payment/pending_approval/waitlisted/
 * rejected/cancelled, which never became real attendance.
 */
class BatchFeedbackPolicy
{
    protected const GENUINE_PARTICIPATION_STATUSES = ['active', 'expired'];

    public function canSubmit(User $user, Batch $batch): bool
    {
        return Enrolment::where('batch_id', $batch->id)
            ->where('user_id', $user->id)
            ->whereIn('status', self::GENUINE_PARTICIPATION_STATUSES)
            ->exists();
    }
}
