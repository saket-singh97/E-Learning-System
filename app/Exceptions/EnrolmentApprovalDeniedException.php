<?php

namespace App\Exceptions;

use RuntimeException;

class EnrolmentApprovalDeniedException extends RuntimeException
{
    public static function forActor(int $actorId, int $batchId): self
    {
        return new self("User [{$actorId}] may not approve or reject enrolments for batch [{$batchId}].");
    }
}
