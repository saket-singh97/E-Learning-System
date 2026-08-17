<?php

namespace App\Exceptions;

use RuntimeException;

class SeatLimitExceededException extends RuntimeException
{
    public static function forTenant(int $tenantId): self
    {
        return new self("Tenant [{$tenantId}] has reached its plan's staff seat limit; upgrade the plan or remove an existing admin/instructor first.");
    }
}
