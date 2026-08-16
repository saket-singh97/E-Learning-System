<?php

namespace App\Exceptions;

use RuntimeException;

class UnknownRubricCriterionException extends RuntimeException
{
    public static function forAssignment(int $assignmentId, string $criterionId): self
    {
        return new self("Criterion [{$criterionId}] is not part of assignment [{$assignmentId}]'s own rubric.");
    }
}
