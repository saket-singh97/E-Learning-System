<?php

use App\Domains\Growth\Models\AffiliateCode;
use App\Domains\Growth\Services\AffiliateCodeService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->service = app(AffiliateCodeService::class);
});

it('creates an affiliate code with an 8-character generated code', function () {
    $code = $this->service->createFor($this->owner, 'percentage', 15);

    expect($code->owner_user_id)->toBe($this->owner->id)
        ->and($code->code)->toHaveLength(8)
        ->and($code->commission_type)->toBe('percentage')
        ->and((float) $code->commission_value)->toBe(15.0)
        ->and($code->status)->toBe('active');
});

it('never generates the same code twice for the same tenant', function () {
    $codes = collect(range(1, 20))->map(fn () => $this->service->createFor($this->owner, 'fixed', 100)->code);

    expect($codes->unique())->toHaveCount(20);
});

it('allows the same code text to be reused by a different tenant', function () {
    $ours = $this->service->createFor($this->owner, 'percentage', 10);

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    $otherOwner = User::factory()->create(['tenant_id' => $otherTenant->id]);

    // Force a collision to prove the DB constraint is (tenant_id, code),
    // not a bare unique index on code alone.
    $theirs = AffiliateCode::create([
        'owner_user_id' => $otherOwner->id,
        'code' => $ours->code,
        'commission_type' => 'percentage',
        'commission_value' => 10,
        'status' => 'active',
    ]);

    expect($theirs->code)->toBe($ours->code)
        ->and($theirs->tenant_id)->not->toBe($ours->tenant_id);
});
