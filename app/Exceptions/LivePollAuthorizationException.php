<?php

namespace App\Exceptions;

use RuntimeException;

class LivePollAuthorizationException extends RuntimeException
{
    public static function forActor(int $actorId, int $batchSessionId): self
    {
        return new self("User [{$actorId}] may not manage live polls for batch session [{$batchSessionId}].");
    }
}
