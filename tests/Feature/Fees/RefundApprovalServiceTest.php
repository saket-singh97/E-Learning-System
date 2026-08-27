<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Fees\Models\FeeRefund;
use App\Domains\Fees\Services\RefundApprovalService;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\RefundActionException;
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
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'self_enrol_direct',
        'status' => 'published',
        'is_paid' => true,
        'price' => 1000,
    ]);
    $enrolment = Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
        'status' => 'active',
        'source' => 'self_enrol_direct',
        'requested_at' => now(),
    ]);

    $this->makePayment = function (?Carbon\Carbon $paidAt) use ($batch, $enrolment): FeePayment {
        $invoice = FeeInvoice::create([
            'batch_id' => $batch->id,
            'enrolment_id' => $enrolment->id,
            'user_id' => $enrolment->user_id,
            'sequence_number' => 1,
            'invoice_number' => 'INV-'.uniqid(),
            'description' => 'Full fee',
            'amount' => 1000,
            'gst_amount' => 180,
            'total_amount' => 1180,
            'due_date' => now()->toDateString(),
            'status' => $paidAt !== null ? 'paid' : 'pending',
            'paid_at' => $paidAt,
        ]);

        return FeePayment::create([
            'fee_invoice_id' => $invoice->id,
            'provider_payment_id' => 'pay_'.uniqid(),
            'provider_order_id' => 'order_'.uniqid(),
            'idempotency_key' => 'idem_'.uniqid(),
            'amount' => 1180,
            'status' => $paidAt !== null ? 'captured' : 'created',
        ]);
    };

    $this->makeRequest = function (FeePayment $payment, float $amount = 1180): FeeRefund {
        return FeeRefund::create([
            'fee_payment_id' => $payment->id,
            'requested_by_user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'amount' => $amount,
            'reason' => 'Student withdrew',
            'status' => 'pending',
        ]);
    };

    $this->service = app(RefundApprovalService::class);
});

it('processes the refund and marks the request and payment accordingly', function () {
    $payment = ($this->makePayment)(now()->subDays(2));
    $refund = ($this->makeRequest)($payment);

    $result = $this->service->approve($refund);

    expect($result->status)->toBe('processed')
        ->and($result->gateway_refund_id)->not->toBeNull()
        ->and($result->processed_at)->not->toBeNull()
        ->and($payment->fresh()->status)->toBe('refunded');
});

it('rejects a request without touching the original payment', function () {
    $payment = ($this->makePayment)(now()->subDays(2));
    $refund = ($this->makeRequest)($payment);

    $result = $this->service->reject($refund);

    expect($result->status)->toBe('rejected')
        ->and($payment->fresh()->status)->toBe('captured');
});

it('refuses to approve a request whose payment has fallen outside the refund window', function () {
    $payment = ($this->makePayment)(now()->subDays(10));
    $refund = ($this->makeRequest)($payment);

    expect(fn () => $this->service->approve($refund))->toThrow(RefundActionException::class);
    expect($refund->fresh()->status)->toBe('pending')
        ->and($payment->fresh()->status)->toBe('captured');
});

it('refuses to decide a request a second time once it has already been approved', function () {
    $payment = ($this->makePayment)(now()->subDays(2));
    $refund = ($this->makeRequest)($payment);

    $this->service->approve($refund);

    expect(fn () => $this->service->approve($refund->fresh()))->toThrow(RefundActionException::class);
    expect(fn () => $this->service->reject($refund->fresh()))->toThrow(RefundActionException::class);
});
