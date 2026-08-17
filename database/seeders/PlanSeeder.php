<?php

namespace Database\Seeders;

use App\Domains\Billing\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Four round, placeholder tiers — not sourced from a real pricing model,
 * same posture as BandwidthQuota/AiSpendCap's own caps (S9 B12). The free
 * tier's bandwidth/AI numbers deliberately match those existing hardcoded
 * constants (5 GB/day, $1/day) for continuity, not because the numbers are
 * final.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => Plan::CODE_FREE,
                'name' => 'Free',
                'seat_limit' => 2,
                'storage_limit_gb' => 5,
                'bandwidth_limit_gb_per_day' => 5,
                'ai_spend_cap_usd_per_day' => 1.00,
                'price_inr_per_month' => 0,
            ],
            [
                'code' => Plan::CODE_STARTER,
                'name' => 'Starter',
                'seat_limit' => 5,
                'storage_limit_gb' => 25,
                'bandwidth_limit_gb_per_day' => 20,
                'ai_spend_cap_usd_per_day' => 5.00,
                'price_inr_per_month' => 2999,
            ],
            [
                'code' => Plan::CODE_GROWTH,
                'name' => 'Growth',
                'seat_limit' => 15,
                'storage_limit_gb' => 100,
                'bandwidth_limit_gb_per_day' => 50,
                'ai_spend_cap_usd_per_day' => 10.00,
                'price_inr_per_month' => 7999,
            ],
            [
                'code' => Plan::CODE_ENTERPRISE,
                'name' => 'Enterprise',
                'seat_limit' => null,
                'storage_limit_gb' => 500,
                'bandwidth_limit_gb_per_day' => 200,
                'ai_spend_cap_usd_per_day' => 50.00,
                'price_inr_per_month' => 19999,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(['code' => $plan['code']], $plan);
        }
    }
}
