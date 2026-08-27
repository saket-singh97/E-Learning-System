<?php

use App\Domains\Growth\Models\AffiliateCode;
use App\Domains\Growth\Models\AffiliatePayoutRequest;
use App\Domains\Growth\Models\AffiliateReferral;
use App\Domains\Growth\Services\AffiliatePayoutService;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\AffiliatePayoutActionException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->code = AffiliateCode::create([
        'owner_user_id' => $this->owner->id,
        'code' => 'BAL12345',
        'commission_type' => 'fixed',
        'commission_value' => 100,
        'status' => 'active',
    ]);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->service = app(AffiliatePayoutService::class);

    $this->makeReferral = function (string $status, float $commission) {
        return AffiliateReferral::create([
            'affiliate_code_id' => $this->code->id,
            'referred_user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'fee_payment_id' => \App\Domains\Fees\Models\FeePayment::create([
                'fee_invoice_id' => \App\Domains\Fees\Models\FeeInvoice::create([
                    'user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
                    'sequence_number' => \App\Domains\Fees\Models\FeeInvoice::count() + 1,
                    'invoice_number' => 'INV-'.uniqid(),
                    'description' => 'Row',
                    'amount' => 1000,
                    'gst_amount' => 180,
                    'total_amount' => 1180,
                    'due_date' => now()->toDateString(),
                ])->id,
                'provider_order_id' => 'order_'.uniqid(),
                'idempotency_key' => 'idem_'.uniqid(),
                'amount' => 1180,
                'status' => 'captured',
            ])->id,
            'commission_amount' => $commission,
            'status' => $status,
        ]);
    };
});

it('reflects only confirmed commission in the available balance, not pending or reversed', function () {
    ($this->makeReferral)('confirmed', 100);
    ($this->makeReferral)('confirmed', 50);
    ($this->makeReferral)('pending', 999);
    ($this->makeReferral)('reversed', 999);

    expect($this->service->balanceFor($this->code->fresh()))->toBe(150.0);
});

it('refuses a payout request that exceeds the available balance', function () {
    ($this->makeReferral)('confirmed', 100);

    expect(fn () => $this->service->requestPayout($this->code->fresh(), 200))
        ->toThrow(AffiliatePayoutActionException::class);
    expect(AffiliatePayoutRequest::count())->toBe(0);
});

it('reserves the requested amount so a second request cannot double-claim it', function () {
    ($this->makeReferral)('confirmed', 100);
    $this->service->requestPayout($this->code->fresh(), 100);

    expect($this->service->balanceFor($this->code->fresh()))->toBe(0.0);
    expect(fn () => $this->service->requestPayout($this->code->fresh(), 1))
        ->toThrow(AffiliatePayoutActionException::class);
});

it('gives the reserved amount back once a request is rejected', function () {
    ($this->makeReferral)('confirmed', 100);
    $request = $this->service->requestPayout($this->code->fresh(), 100);

    $this->service->reject($request, $this->admin);

    expect($this->service->balanceFor($this->code->fresh()))->toBe(100.0);
});

it('approves a request, recording who decided it and when', function () {
    ($this->makeReferral)('confirmed', 100);
    $request = $this->service->requestPayout($this->code->fresh(), 100);

    $approved = $this->service->approve($request, $this->admin);

    expect($approved->status)->toBe('approved')
        ->and($approved->decided_by)->toBe($this->admin->id)
        ->and($approved->decided_at)->not->toBeNull();
});

it('refuses to decide a request a second time once it has already been approved', function () {
    ($this->makeReferral)('confirmed', 100);
    $request = $this->service->requestPayout($this->code->fresh(), 100);
    $this->service->approve($request, $this->admin);

    expect(fn () => $this->service->approve($request->fresh(), $this->admin))
        ->toThrow(AffiliatePayoutActionException::class);
    expect(fn () => $this->service->reject($request->fresh(), $this->admin))
        ->toThrow(AffiliatePayoutActionException::class);
});
