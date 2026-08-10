<?php

namespace App\Exceptions;

use RuntimeException;

class PrivilegeEscalationException extends RuntimeException
{
    public static function forAttempt(string $actorRole, string $attemptedRole): self
    {
        return new self("Role [{$actorRole}] cannot assign role [{$attemptedRole}].");
    }
}
