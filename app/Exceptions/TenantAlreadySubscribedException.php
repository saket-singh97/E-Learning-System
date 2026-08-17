<?php

namespace App\Exceptions;

use RuntimeException;

class TenantAlreadySubscribedException extends RuntimeException
{
    public static function forTenant(int $tenantId): self
    {
        return new self("Tenant [{$tenantId}] already has an active or trialing subscription — use a plan change, not a new subscription.");
    }
}
