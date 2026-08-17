<?php

namespace App\Exceptions;

use RuntimeException;

class TenantNotSubscribedException extends RuntimeException
{
    public static function forTenant(int $tenantId): self
    {
        return new self("Tenant [{$tenantId}] has no live subscription to change — use subscribe() for a first-time plan assignment instead.");
    }
}
