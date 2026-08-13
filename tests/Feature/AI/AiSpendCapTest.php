<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Services\AiSpendCap;
use App\Domains\Content\Events\LessonVideoReady;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults a fresh tenant to the free tier cap', function () {
    $tenant = Tenant::factory()->create();

    // ->fresh(), not the in-memory $tenant returned by create() — same S9
    // B9 lesson BandwidthQuotaTest's own equivalent case documents.
    expect($tenant->fresh()->plan_tier)->toBe('free')
        ->and(app(AiSpendCap::class)->capFor($tenant))->toBe(AiSpendCap::CAPS_USD['free']);
});

it('gives a paid tenant a larger cap', function () {
    $tenant = Tenant::factory()->create(['plan_tier' => 'paid']);

    expect(app(AiSpendCap::class)->capFor($tenant))
        ->toBe(AiSpendCap::CAPS_USD['paid'])
        ->toBeGreaterThan(AiSpendCap::CAPS_USD['free']);
});

it('remainingFor subtracts todays real spend from the cap', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    event(new LessonVideoReady(ingestibleLesson($tenant)->id, $tenant->id));

    $artifact = LessonArtifact::sole();
    $spendCap = app(AiSpendCap::class);

    expect($spendCap->spentToday($tenant))->toBe($artifact->token_cost['usd'])
        ->and($spendCap->remainingFor($tenant))->toBe(round($spendCap->capFor($tenant) - $artifact->token_cost['usd'], 6));
});

it('never returns a negative remaining amount once spend exceeds the cap', function () {
    $tenant = Tenant::factory()->create(['plan_tier' => 'free']);
    app(CurrentTenant::class)->set($tenant);

    LessonArtifact::create([
        'lesson_id' => ingestibleLesson($tenant)->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'token_cost' => ['in' => 1_000_000, 'out' => 1_000_000, 'usd' => 999.0],
    ]);

    expect(app(AiSpendCap::class)->remainingFor($tenant))->toBe(0.0);
});
