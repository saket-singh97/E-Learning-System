<?php

namespace App\Exceptions;

use RuntimeException;

class CircuitOpenException extends RuntimeException
{
    public static function forProvider(string $key): self
    {
        return new self("Circuit breaker for [{$key}] is open — provider calls are failing fast until the cooldown window passes.");
    }
}
