<?php

namespace App\Domains\Batches\Policies;

use App\Domains\Batches\Models\Batch;
use App\Models\User;

/**
 * Answers only "may $actor approve/reject enrolments for $batch" — kept
 * separate from BatchEnrolmentService per the design doc's split between an
 * authorization question and a state-transition question.
 *
 * S15 B0 added `batches.instructor_id`, closing the gap this docblock used
 * to describe ("no course/batch-instructor pivot exists anywhere") — an
 * instructor now must be *that batch's own* assigned instructor, not just
 * any same-tenant instructor. institute_admin keeps the same-tenant check
 * RoleAssignmentPolicy already uses for its own actor/target check.
 */
class EnrolmentApprovalPolicy
{
    public const DECIDER_ROLES = ['instructor', 'institute_admin'];

    public function canDecide(User $actor, Batch $batch): bool
    {
        if (! $actor->hasAnyRole(self::DECIDER_ROLES)) {
            return false;
        }

        if ($actor->hasRole('institute_admin')) {
            return $actor->tenant_id === $batch->tenant_id;
        }

        return $actor->id === $batch->instructor_id;
    }
}
