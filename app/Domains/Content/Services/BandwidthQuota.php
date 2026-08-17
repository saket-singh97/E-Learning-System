<?php

namespace App\Domains\Content\Services;

use App\Domains\Billing\Services\TenantPlanLimits;
use App\Domains\Content\Models\BandwidthUsageDaily;
use App\Domains\Tenancy\Models\Tenant;

/**
 * Turns BandwidthUsageDaily's own existing measurement (S2) into a real
 * cap, closing S2's own "measuring only, no quota" note (D8) — S9 B12.
 *
 * Caps are placeholder round numbers, not sourced from any real pricing
 * decision — the design doc deliberately doesn't invent business numbers
 * (§2.4 decision 5: a flat tier enum + two hardcoded limits, not a
 * configurable-limits table). Bunny's own Asia delivery pricing
 * (docs/FEATURES.md §3.2, ~$0.03/GB) is the only real anchor available;
 * these bound a free tenant's platform cost to a few dollars/day, not
 * derived from a finished pricing model.
 *
 * CAPS_BYTES itself stays put (S11 B2) — it's now the legacy-fallback data
 * source TenantPlanLimits reads directly for a plan_id-null tenant, not
 * the only cap source any more. capFor() delegates there so this class
 * doesn't have to know whether a given tenant has a real Plan assigned.
 */
class BandwidthQuota
{
    /** @var array<string, int> Bytes/day, keyed by Tenant::PLAN_TIER_*. */
    public const CAPS_BYTES = [
        Tenant::PLAN_TIER_FREE => 5 * 1024 * 1024 * 1024,   // 5 GB/day
        Tenant::PLAN_TIER_PAID => 50 * 1024 * 1024 * 1024,  // 50 GB/day
    ];

    public function __construct(
        private readonly TenantPlanLimits $limits,
    ) {}

    public function capFor(Tenant $tenant): int
    {
        return $this->limits->bandwidthLimitBytesPerDay($tenant);
    }

    /**
     * Explicit tenant_id filter (withoutGlobalScope), not a bare query
     * relying on CurrentTenant already matching $tenant — decouples this
     * service from whatever tenant context happens to be ambient at call
     * time, the same reasoning several S9 B10 sweeps were fixed for
     * (a stale CurrentTenant binding would otherwise silently read the
     * wrong tenant's usage).
     */
    public function usedToday(Tenant $tenant): int
    {
        return (int) (BandwidthUsageDaily::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('date', now()->toDateString())
            ->value('bytes_served') ?? 0);
    }

    public function remainingFor(Tenant $tenant): int
    {
        return max(0, $this->capFor($tenant) - $this->usedToday($tenant));
    }
}
