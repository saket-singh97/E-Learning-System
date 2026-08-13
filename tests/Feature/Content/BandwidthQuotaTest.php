<?php

use App\Domains\Content\Models\BandwidthUsageDaily;
use App\Domains\Content\Services\BandwidthQuota;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults a fresh tenant to the free tier cap', function () {
    $tenant = Tenant::factory()->create();

    // ->fresh(), not the in-memory $tenant returned by create() — Eloquent
    // doesn't hydrate a column's DB-level default(...) back into the model
    // after an insert (the exact S9 B9 lesson), so the freshly-created
    // instance's own plan_tier attribute is null even though the DB row
    // itself defaulted to 'free'.
    expect($tenant->fresh()->plan_tier)->toBe('free')
        ->and(app(BandwidthQuota::class)->capFor($tenant))->toBe(BandwidthQuota::CAPS_BYTES['free']);
});

it('capFor falls back to the free tier cap for an unrefreshed tenant instance too', function () {
    // Same S9 B9 lesson, the other direction: capFor() must not crash or
    // silently misbehave on the null plan_tier a just-created (not yet
    // refreshed) Tenant instance actually has in memory.
    $tenant = Tenant::factory()->create();

    expect($tenant->plan_tier)->toBeNull()
        ->and(app(BandwidthQuota::class)->capFor($tenant))->toBe(BandwidthQuota::CAPS_BYTES['free']);
});

it('gives a paid tenant a larger cap', function () {
    $tenant = Tenant::factory()->create(['plan_tier' => 'paid']);

    expect(app(BandwidthQuota::class)->capFor($tenant))
        ->toBe(BandwidthQuota::CAPS_BYTES['paid'])
        ->toBeGreaterThan(BandwidthQuota::CAPS_BYTES['free']);
});

it('reads todays usage for the given tenant regardless of what CurrentTenant happens to be bound to', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    BandwidthUsageDaily::create(['date' => now()->toDateString(), 'bytes_served' => 12345]);

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);

    // CurrentTenant is bound to $otherTenant right now, not $tenant — the
    // service must still read $tenant's own row correctly (S9 B10's own
    // persistent-worker-leak lesson, applied here too).
    expect(app(BandwidthQuota::class)->usedToday($tenant))->toBe(12345)
        ->and(app(BandwidthQuota::class)->usedToday($otherTenant))->toBe(0);
});

it('remainingFor subtracts todays usage from the cap, floored at zero', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    BandwidthUsageDaily::create(['date' => now()->toDateString(), 'bytes_served' => 1000]);

    $quota = app(BandwidthQuota::class);

    expect($quota->remainingFor($tenant))->toBe($quota->capFor($tenant) - 1000);
});

it('never returns a negative remaining amount once usage exceeds the cap', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    BandwidthUsageDaily::create(['date' => now()->toDateString(), 'bytes_served' => 999_999_999_999]);

    expect(app(BandwidthQuota::class)->remainingFor($tenant))->toBe(0);
});
