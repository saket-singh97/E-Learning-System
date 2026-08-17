<?php

namespace App\Exceptions;

use RuntimeException;

class DeviceLimitExceededException extends RuntimeException
{
    public static function forUser(int $userId, int $limit): self
    {
        return new self("User [{$userId}] has reached the registered-device limit ({$limit}); log out an existing device before signing in on a new one.");
    }
}
