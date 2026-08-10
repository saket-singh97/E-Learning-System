<?php

namespace App\Exceptions;

use RuntimeException;

class TenantSuspendedException extends RuntimeException
{
    public static function forSubdomain(string $subdomain): self
    {
        return new self("Tenant [{$subdomain}] is suspended.");
    }
}
