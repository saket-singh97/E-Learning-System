<?php

namespace App\Exceptions;

use RuntimeException;

class TenantNotFoundException extends RuntimeException
{
    public static function forSubdomain(string $subdomain): self
    {
        return new self("No tenant found for subdomain [{$subdomain}].");
    }
}
