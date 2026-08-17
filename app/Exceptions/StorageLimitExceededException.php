<?php

namespace App\Exceptions;

use RuntimeException;

class StorageLimitExceededException extends RuntimeException
{
    public static function forTenant(int $tenantId): self
    {
        return new self("Tenant [{$tenantId}] has reached its plan's storage limit; upgrade the plan or free up space first.");
    }
}
