<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Fees\Models\FeeRefund;
use App\Domains\Fees\Services\RefundApprovalService;
use App\Domains\Growth\Models\AffiliateCode;
use App\Domains\Growth\Models\AffiliateReferral;
use App\Domains\Growth\Services\AffiliateAttributionService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['refund_window_days' => 7]);
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $enrolment = Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $this->makePayment = function () use ($batch, $enrolment): FeePayment {
        $invoice = FeeInvoice::create([
            'batch_id' => $batch->id,
            'enrolment_id' => $enrolment->id,
            'user_id' => $enrolment->user_id,
            'sequence_number' => FeeInvoice::count() + 1,
            'invoice_number' => 'INV-'.uniqid(),
            'description' => 'Full fee',
            'amount' => 1000,
            'gst_amount' => 180,
            'total_amount' => 1180,
            'due_date' => now()->toDateString(),
            'status' => 'paid',
            'paid_at' => now()->subDay(),
        ]);

        return FeePayment::create([
            'fee_invoice_id' => $invoice->id,
            'provider_payment_id' => 'pay_'.uniqid(),
            'provider_order_id' => 'order_'.uniqid(),
            'idempotency_key' => 'idem_'.uniqid(),
            'amount' => 1180,
            'status' => 'captured',
        ]);
    };

    $this->makeAffiliateCode = function (string $type, float $value): AffiliateCode {
        return AffiliateCode::create([
            'owner_user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'code' => 'AFF'.strtoupper(substr(uniqid(), -6)),
            'commission_type' => $type,
            'commission_value' => $value,
            'status' => 'active',
        ]);
    };

    $this->attribution = app(AffiliateAttributionService::class);
});

it('computes a percentage commission against the payments own amount', function () {
    $payment = ($this->makePayment)();
    $code = ($this->makeAffiliateCode)('percentage', 10);
    $referral = $this->attribution->attribute($payment, $code->code);

    $confirmed = $this->attribution->confirmCommission($referral);

    expect((float) $confirmed->commission_amount)->toBe(118.0)
        ->and($confirmed->status)->toBe('confirmed');
});

it('computes a fixed commission regardless of the payments own amount', function () {
    $payment = ($this->makePayment)();
    $code = ($this->makeAffiliateCode)('fixed', 50);
    $referral = $this->attribution->attribute($payment, $code->code);

    $confirmed = $this->attribution->confirmCommission($referral);

    expect((float) $confirmed->commission_amount)->toBe(50.0)
        ->and($confirmed->status)->toBe('confirmed');
});

it('does not recompute or touch an already-confirmed or reversed referral', function () {
    $payment = ($this->makePayment)();
    $code = ($this->makeAffiliateCode)('fixed', 50);
    $referral = $this->attribution->attribute($payment, $code->code);
    $this->attribution->confirmCommission($referral->fresh());

    $referral->update(['status' => 'reversed']);
    $result = $this->attribution->confirmCommission($referral->fresh());

    expect($result->status)->toBe('reversed')
        ->and((float) $result->commission_amount)->toBe(50.0);
});

it('reverses a confirmed referral automatically once its own payments refund is processed', function () {
    $payment = ($this->makePayment)();
    $code = ($this->makeAffiliateCode)('percentage', 10);
    $referral = $this->attribution->attribute($payment, $code->code);
    $this->attribution->confirmCommission($referral->fresh());

    $refund = FeeRefund::create([
        'fee_payment_id' => $payment->id,
        'requested_by_user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
        'amount' => 1180,
        'reason' => 'Student withdrew',
        'status' => 'pending',
    ]);

    app(RefundApprovalService::class)->approve($refund);

    expect($referral->fresh()->status)->toBe('reversed');
});

it('triggers nothing when an unaffiliated payments refund is processed', function () {
    $payment = ($this->makePayment)();

    $refund = FeeRefund::create([
        'fee_payment_id' => $payment->id,
        'requested_by_user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
        'amount' => 1180,
        'reason' => 'Student withdrew',
        'status' => 'pending',
    ]);

    app(RefundApprovalService::class)->approve($refund);

    expect(AffiliateReferral::count())->toBe(0)
        ->and($refund->fresh()->status)->toBe('processed');
});
