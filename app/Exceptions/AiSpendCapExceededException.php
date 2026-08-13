<?php

namespace App\Exceptions;

use RuntimeException;

class AiSpendCapExceededException extends RuntimeException
{
    public static function forTenant(int $tenantId): self
    {
        return new self("Tenant [{$tenantId}] has reached its daily AI spend cap; upgrade the plan or wait until tomorrow.");
    }
}
