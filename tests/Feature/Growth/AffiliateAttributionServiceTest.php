<?php

use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Growth\Models\AffiliateCode;
use App\Domains\Growth\Models\AffiliateReferral;
use App\Domains\Growth\Services\AffiliateAttributionService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->service = app(AffiliateAttributionService::class);

    $this->makePayment = function () {
        $invoice = FeeInvoice::create([
            'user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'sequence_number' => 1,
            'invoice_number' => 'INV-'.uniqid(),
            'description' => 'Row',
            'amount' => 1000,
            'gst_amount' => 180,
            'total_amount' => 1180,
            'due_date' => now()->toDateString(),
        ]);

        return FeePayment::create([
            'fee_invoice_id' => $invoice->id,
            'provider_order_id' => 'order_'.uniqid(),
            'idempotency_key' => 'idem_'.uniqid(),
            'amount' => 1180,
            'status' => 'captured',
        ]);
    };
});

it('attributes a payment to the right affiliate when a valid active code is used', function () {
    $affiliateCode = AffiliateCode::create([
        'owner_user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
        'code' => 'REF12345',
        'commission_type' => 'percentage',
        'commission_value' => 10,
        'status' => 'active',
    ]);
    $payment = ($this->makePayment)();

    $referral = $this->service->attribute($payment, 'REF12345');

    expect($referral)->not->toBeNull()
        ->and($referral->affiliate_code_id)->toBe($affiliateCode->id)
        ->and($referral->fee_payment_id)->toBe($payment->id)
        ->and($referral->referred_user_id)->toBe($payment->invoice->user_id)
        ->and($referral->status)->toBe('pending')
        ->and(AffiliateReferral::count())->toBe(1);
});

it('attributes nothing for an unrecognized code', function () {
    $payment = ($this->makePayment)();

    $referral = $this->service->attribute($payment, 'DOES-NOT-EXIST');

    expect($referral)->toBeNull()
        ->and(AffiliateReferral::count())->toBe(0);
});

it('attributes nothing for a disabled code', function () {
    AffiliateCode::create([
        'owner_user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
        'code' => 'DISABLED1',
        'commission_type' => 'percentage',
        'commission_value' => 10,
        'status' => 'disabled',
    ]);
    $payment = ($this->makePayment)();

    $referral = $this->service->attribute($payment, 'DISABLED1');

    expect($referral)->toBeNull()
        ->and(AffiliateReferral::count())->toBe(0);
});

it('attributes nothing when no code is given', function () {
    $payment = ($this->makePayment)();

    expect($this->service->attribute($payment, null))->toBeNull();
});

it('never lets one tenants affiliate code attribute another tenants payment', function () {
    AffiliateCode::create([
        'owner_user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
        'code' => 'SHARED001',
        'commission_type' => 'percentage',
        'commission_value' => 10,
        'status' => 'active',
    ]);

    $otherTenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($otherTenant);
    $otherPayment = ($this->makePayment)();

    // Same code text exists for the first tenant, but the current tenant
    // context (otherTenant) has never minted it — the global tenant scope
    // must keep the lookup from ever crossing into the first tenant's row.
    $referral = $this->service->attribute($otherPayment, 'SHARED001');

    expect($referral)->toBeNull()
        ->and(AffiliateReferral::count())->toBe(0);
});
