<?php

namespace App\Domains\Assignments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once per stored version by `AssignmentSubmissionService::storeVersion()`
 * — each version is a distinct, once-only insert (never retried in place;
 * the unique (assignment_id, user_id, version_number) index plus the
 * count-then-insert retry in `submitWithRetry()` only ever lands one row per
 * version), so this cannot double-fire for the same version (S19 B13).
 * One-way-ignorance shape, same as `AttendanceRecorded`.
 */
class AssignmentSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly int $tenantId,
        public readonly int $assignmentSubmissionId,
    ) {}
}
