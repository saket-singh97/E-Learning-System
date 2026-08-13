<?php

namespace App\Domains\AI\Services;

use App\Domains\Tenancy\Models\Tenant;

/**
 * Turns TokenCostLedger's own existing measurement (S3) into a real cap,
 * closing S3's own "measuring only, no cap" note (L4) — S9 B12.
 *
 * Caps are placeholder round numbers, not sourced from any real pricing
 * decision — same posture as BandwidthQuota's own caps (S9 B12, Content
 * domain), the design doc deliberately doesn't invent business numbers
 * (§2.4 decision 5).
 *
 * spentToday() relies on TokenCostLedger::totalForDate()'s own documented
 * behavior: no explicit tenant filter, reading through BelongsToTenant's
 * ambient global scope instead. Correct here specifically because the one
 * real caller (SpendCapGuardedAiDriver) always derives $tenant from
 * CurrentTenant itself before calling remainingFor() — the ambient scope
 * and $tenant are guaranteed to already agree at call time, not an
 * assumption this class would otherwise need to re-verify.
 */
class AiSpendCap
{
    /** @var array<string, float> USD/day, keyed by Tenant::PLAN_TIER_*. */
    public const CAPS_USD = [
        Tenant::PLAN_TIER_FREE => 1.00,
        Tenant::PLAN_TIER_PAID => 10.00,
    ];

    public function __construct(
        private readonly TokenCostLedger $ledger,
    ) {}

    public function capFor(Tenant $tenant): float
    {
        return self::CAPS_USD[$tenant->plan_tier] ?? self::CAPS_USD[Tenant::PLAN_TIER_FREE];
    }

    public function spentToday(Tenant $tenant): float
    {
        return $this->ledger->totalForDate(now())['usd'];
    }

    public function remainingFor(Tenant $tenant): float
    {
        return max(0.0, $this->capFor($tenant) - $this->spentToday($tenant));
    }
}
