<?php

namespace App\Exceptions;

use RuntimeException;

class PlanDowngradeBlockedException extends RuntimeException
{
    /**
     * @param  array<int, string>  $overLimits
     */
    public static function forTenant(int $tenantId, array $overLimits): self
    {
        $list = implode(', ', $overLimits);

        return new self("Tenant [{$tenantId}] cannot move to this plan — it is currently over the new plan's limit on: {$list}.");
    }
}
