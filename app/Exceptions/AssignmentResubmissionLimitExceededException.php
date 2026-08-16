<?php

namespace App\Exceptions;

use RuntimeException;

class AssignmentResubmissionLimitExceededException extends RuntimeException
{
    public static function forAssignment(int $assignmentId): self
    {
        return new self("Assignment [{$assignmentId}] already has 3 submitted versions — the maximum allowed.");
    }
}
