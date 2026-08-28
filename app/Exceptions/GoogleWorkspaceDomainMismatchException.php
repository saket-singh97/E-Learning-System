<?php

namespace App\Exceptions;

use RuntimeException;

class GoogleWorkspaceDomainMismatchException extends RuntimeException
{
    public static function make(string $requiredDomain): self
    {
        return new self("This institute only accepts Google sign-in from a @{$requiredDomain} account.");
    }
}
