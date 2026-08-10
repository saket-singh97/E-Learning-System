<?php

namespace App\Domains\Batches\Policies;

use App\Domains\Batches\Models\Batch;
use App\Models\User;

/**
 * Answers only "may $actor approve/reject enrolments for $batch" — kept
 * separate from BatchEnrolmentService per the design doc's split between an
 * authorization question and a state-transition question.
 *
 * "An instructor assigned to the batch's course" (design doc §3.4) has no
 * backing schema yet: no course/batch-instructor pivot exists anywhere in
 * this codebase, and roles are global to a user, not per-tenant or
 * per-course (see RoleAssignmentPolicy). Until a real per-course assignment
 * model lands, "assigned" is read as "same tenant as the batch" — the same
 * same-tenant guard RoleAssignmentPolicy already uses for its own actor/
 * target check.
 */
class EnrolmentApprovalPolicy
{
    public const DECIDER_ROLES = ['instructor', 'institute_admin'];

    public function canDecide(User $actor, Batch $batch): bool
    {
        return $actor->tenant_id === $batch->tenant_id
            && $actor->hasAnyRole(self::DECIDER_ROLES);
    }
}
