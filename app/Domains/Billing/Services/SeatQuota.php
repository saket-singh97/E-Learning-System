<?php

namespace App\Domains\Billing\Services;

use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;

/**
 * Seat = active institute_admin/instructor accounts (design doc §2.4
 * decision 4) — students excluded, unlimited student enrolment is the
 * institute's own monetisation lever, not something this platform caps.
 */
class SeatQuota
{
    public const SEAT_ROLES = ['institute_admin', 'instructor'];

    public function __construct(
        private readonly TenantPlanLimits $limits,
    ) {}

    /** Null = unlimited (design doc §3.2 — TenantPlanLimits::seatLimit()'s own contract). */
    public function capFor(Tenant $tenant): ?int
    {
        return $this->limits->seatLimit($tenant);
    }

    /**
     * User has no BelongsToTenant scope (roles are global to a user, not
     * per-tenant — RoleAssignmentPolicy's own note on why), so this is an
     * explicit tenant_id filter, not an ambient-scope query. Soft-deleted
     * users are already excluded by User's own SoftDeletes global scope —
     * no separate "active" flag exists to check.
     */
    public function usedNow(Tenant $tenant): int
    {
        return User::where('tenant_id', $tenant->id)
            ->role(self::SEAT_ROLES)
            ->count();
    }

    public function remainingFor(Tenant $tenant): ?int
    {
        $cap = $this->capFor($tenant);

        if ($cap === null) {
            return null;
        }

        return max(0, $cap - $this->usedNow($tenant));
    }
}
