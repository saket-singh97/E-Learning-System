<?php

namespace App\Exceptions;

use RuntimeException;

class ConcurrentStreamLimitExceededException extends RuntimeException
{
    public static function forUser(int $userId): self
    {
        return new self("User [{$userId}] is already streaming another lesson; only one concurrent stream is allowed per account.");
    }
}
