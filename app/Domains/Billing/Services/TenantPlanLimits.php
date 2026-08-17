<?php

namespace App\Domains\Billing\Services;

use App\Domains\AI\Services\AiSpendCap;
use App\Domains\Content\Services\BandwidthQuota;
use App\Domains\Tenancy\Models\Tenant;

/**
 * The one place implementing design doc §2.4 decision 3's fallback branch:
 * a tenant with a real plan_id reads its numeric limits from Plan; a
 * pre-S11 tenant (plan_id still null) falls back to the exact same
 * hardcoded constants BandwidthQuota/AiSpendCap already shipped in S9 B12.
 * Reading those consts directly (not duplicating the numbers here) is
 * deliberate — one source of truth for the legacy caps, not two that can
 * drift apart.
 */
class TenantPlanLimits
{
    /**
     * No legacy seat concept existed anywhere before S11 — a plan_id-null
     * tenant is unlimited, not zero, so B3's SeatQuota enforcement never
     * changes behavior for a tenant nobody has assigned a real plan to.
     */
    private const LEGACY_SEAT_LIMIT = null;

    /**
     * Same placeholder-round-numbers posture as BandwidthQuota::CAPS_BYTES
     * — storage never had a cap before S11, so there is no existing
     * constant to reuse the way bandwidth/AI-spend do.
     *
     * @var array<string, int> Gigabytes, keyed by Tenant::PLAN_TIER_*.
     */
    public const LEGACY_STORAGE_LIMIT_GB = [
        Tenant::PLAN_TIER_FREE => 5,
        Tenant::PLAN_TIER_PAID => 50,
    ];

    public function seatLimit(Tenant $tenant): ?int
    {
        if ($tenant->plan_id !== null) {
            return $tenant->plan?->seat_limit;
        }

        return self::LEGACY_SEAT_LIMIT;
    }

    public function storageLimitBytes(Tenant $tenant): int
    {
        if ($tenant->plan_id !== null && $tenant->plan !== null) {
            return $tenant->plan->storage_limit_gb * 1024 * 1024 * 1024;
        }

        $gb = self::LEGACY_STORAGE_LIMIT_GB[$tenant->plan_tier] ?? self::LEGACY_STORAGE_LIMIT_GB[Tenant::PLAN_TIER_FREE];

        return $gb * 1024 * 1024 * 1024;
    }

    public function bandwidthLimitBytesPerDay(Tenant $tenant): int
    {
        if ($tenant->plan_id !== null && $tenant->plan !== null) {
            return $tenant->plan->bandwidth_limit_gb_per_day * 1024 * 1024 * 1024;
        }

        return BandwidthQuota::CAPS_BYTES[$tenant->plan_tier] ?? BandwidthQuota::CAPS_BYTES[Tenant::PLAN_TIER_FREE];
    }

    public function aiSpendCapUsdPerDay(Tenant $tenant): float
    {
        if ($tenant->plan_id !== null && $tenant->plan !== null) {
            return (float) $tenant->plan->ai_spend_cap_usd_per_day;
        }

        return AiSpendCap::CAPS_USD[$tenant->plan_tier] ?? AiSpendCap::CAPS_USD[Tenant::PLAN_TIER_FREE];
    }
}
