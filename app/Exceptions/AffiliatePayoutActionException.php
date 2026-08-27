<?php

namespace App\Exceptions;

use RuntimeException;

class AffiliatePayoutActionException extends RuntimeException
{
    public static function make(string $reason): self
    {
        return new self($reason);
    }
}
