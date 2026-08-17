<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Models\TenantStorageUsage;
use App\Domains\Tenancy\Models\Tenant;

/**
 * A running counter, not a daily log — TenantStorageUsage has one row per
 * tenant (design doc §2.4 decision 5). No pre-existing storage measurement
 * anywhere in this app to build on the way BandwidthQuota had
 * BandwidthUsageDaily; every write path that stores or deletes a billable
 * file calls adjust() itself (B4's enumerated call sites).
 */
class StorageQuota
{
    public function __construct(
        private readonly TenantPlanLimits $limits,
    ) {}

    public function capFor(Tenant $tenant): int
    {
        return $this->limits->storageLimitBytes($tenant);
    }

    /**
     * Explicit tenant_id filter (withoutGlobalScope), not a bare query
     * relying on CurrentTenant already matching $tenant — same reasoning
     * BandwidthQuota::usedToday() already documents, needed here for the
     * identical reason (callers pass an arbitrary $tenant, not necessarily
     * whichever tenant happens to be ambient).
     */
    public function usedNow(Tenant $tenant): int
    {
        return (int) (TenantStorageUsage::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->value('bytes_used') ?? 0);
    }

    public function remainingFor(Tenant $tenant): int
    {
        return max(0, $this->capFor($tenant) - $this->usedNow($tenant));
    }

    /**
     * Positive $deltaBytes records new usage, negative releases it.
     * Creates the row on first use (withoutEvents + explicit tenant_id,
     * same shape RecordVideoCoverageAttendance/CertificateService already
     * use for writing a BelongsToTenant row from a position where
     * CurrentTenant may not match $tenant). Floored at 0 — a release can
     * never underflow past zero.
     */
    public function adjust(Tenant $tenant, int $deltaBytes): void
    {
        $row = TenantStorageUsage::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($row === null) {
            TenantStorageUsage::withoutEvents(fn () => TenantStorageUsage::create([
                'tenant_id' => $tenant->id,
                'bytes_used' => max(0, $deltaBytes),
            ]));

            return;
        }

        $row->update(['bytes_used' => max(0, $row->bytes_used + $deltaBytes)]);
    }
}
