<?php

namespace App\Exceptions;

use RuntimeException;

class BandwidthQuotaExceededException extends RuntimeException
{
    public static function forTenant(int $tenantId): self
    {
        return new self("Tenant [{$tenantId}] has reached its daily bandwidth quota; upgrade the plan or wait until tomorrow.");
    }
}
