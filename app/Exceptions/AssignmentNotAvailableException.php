<?php

namespace App\Exceptions;

use RuntimeException;

class AssignmentNotAvailableException extends RuntimeException
{
    public static function forAssignment(int $assignmentId): self
    {
        return new self("Assignment [{$assignmentId}] is not published, its course/topic is not currently visible, or the viewer has no active enrolment in a batch offering it.");
    }
}
