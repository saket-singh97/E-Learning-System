<?php

use App\Domains\AI\Services\AiSpendCap;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Services\TenantPlanLimits;
use App\Domains\Content\Services\BandwidthQuota;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('falls back to the legacy free-tier caps when a tenant has no plan_id', function () {
    $tenant = Tenant::factory()->create();
    $limits = app(TenantPlanLimits::class);

    expect($limits->seatLimit($tenant))->toBeNull()
        ->and($limits->storageLimitBytes($tenant))->toBe(5 * 1024 * 1024 * 1024)
        ->and($limits->bandwidthLimitBytesPerDay($tenant))->toBe(BandwidthQuota::CAPS_BYTES['free'])
        ->and($limits->aiSpendCapUsdPerDay($tenant))->toBe(AiSpendCap::CAPS_USD['free']);
});

it('falls back to the legacy paid-tier caps for a plan_id-null paid tenant', function () {
    $tenant = Tenant::factory()->create(['plan_tier' => 'paid']);
    $limits = app(TenantPlanLimits::class);

    expect($limits->storageLimitBytes($tenant))->toBe(50 * 1024 * 1024 * 1024)
        ->and($limits->bandwidthLimitBytesPerDay($tenant))->toBe(BandwidthQuota::CAPS_BYTES['paid'])
        ->and($limits->aiSpendCapUsdPerDay($tenant))->toBe(AiSpendCap::CAPS_USD['paid']);
});

it('reads real plan columns once a tenant has a plan_id', function () {
    $plan = Plan::create([
        'code' => 'growth-test',
        'name' => 'Growth',
        'seat_limit' => 15,
        'storage_limit_gb' => 100,
        'bandwidth_limit_gb_per_day' => 50,
        'ai_spend_cap_usd_per_day' => 10,
        'price_inr_per_month' => 7999,
    ]);
    $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
    $limits = app(TenantPlanLimits::class);

    expect($limits->seatLimit($tenant))->toBe(15)
        ->and($limits->storageLimitBytes($tenant))->toBe(100 * 1024 * 1024 * 1024)
        ->and($limits->bandwidthLimitBytesPerDay($tenant))->toBe(50 * 1024 * 1024 * 1024)
        ->and($limits->aiSpendCapUsdPerDay($tenant))->toBe(10.0);
});

it('treats a null seat_limit on the plan as genuinely unlimited', function () {
    $plan = Plan::create([
        'code' => 'enterprise-test',
        'name' => 'Enterprise',
        'seat_limit' => null,
        'storage_limit_gb' => 500,
        'bandwidth_limit_gb_per_day' => 200,
        'ai_spend_cap_usd_per_day' => 50,
        'price_inr_per_month' => 19999,
    ]);
    $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

    expect(app(TenantPlanLimits::class)->seatLimit($tenant))->toBeNull();
});
