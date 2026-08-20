<?php

namespace App\Domains\Content\Policies;

use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Models\User;

/**
 * A review requires (past or current) enrolment in the course — same
 * "genuine participant only" gate AssignmentSubmission/QuizAttempt already
 * imply implicitly (design doc decision 12). Not scoped to only the
 * *current* batch — a past learner from an earlier batch of the same
 * course is still a real reviewer.
 *
 * "Genuine" means the enrolment actually represents real participation:
 * `active` or `expired` (naturally ran its course). `pending_payment`/
 * `pending_approval`/`waitlisted`/`rejected`/`cancelled` never became
 * real attendance — the original version of this check counted any
 * status at all, letting a rejected or never-paid-for applicant post a
 * public review with an instructor_response visible to real buyers
 * (found by S13 B17's review pass).
 */
class CourseReviewPolicy
{
    protected const GENUINE_PARTICIPATION_STATUSES = ['active', 'expired'];

    public function canReview(User $user, Course $course): bool
    {
        return Enrolment::whereHas('batch', fn ($query) => $query->where('course_id', $course->id))
            ->where('user_id', $user->id)
            ->whereIn('status', self::GENUINE_PARTICIPATION_STATUSES)
            ->exists();
    }
}
