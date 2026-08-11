<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Models\FeeCoupon;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $this->batch = Batch::create([
        'course_id' => $this->course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'self_enrol_direct',
        'status' => 'published',
        'is_paid' => true,
        'price' => 1000,
    ]);
    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->enrolment = Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'pending_payment',
        'source' => 'self_enrol_direct',
        'requested_at' => now(),
    ]);
});

it('creates a fee coupon, defaulting to active with zero uses at the DB level', function () {
    $coupon = FeeCoupon::create([
        'code' => 'WELCOME10',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'usage_limit' => 50,
    ]);

    expect($coupon->fresh()->status)->toBe('active')
        ->and($coupon->fresh()->used_count)->toBe(0)
        ->and((float) $coupon->discount_value)->toBe(10.0);
});

it('creates a fee invoice linked to a batch, enrolment, and user, defaulting to pending', function () {
    $invoice = FeeInvoice::create([
        'batch_id' => $this->batch->id,
        'enrolment_id' => $this->enrolment->id,
        'user_id' => $this->student->id,
        'sequence_number' => 1,
        'invoice_number' => 'INV-1',
        'description' => 'Full fee',
        'amount' => 1000,
        'gst_amount' => 180,
        'total_amount' => 1180,
        'due_date' => now()->toDateString(),
    ]);

    expect($invoice->fresh()->status)->toBe('pending')
        ->and($invoice->batch->id)->toBe($this->batch->id)
        ->and($invoice->enrolment->id)->toBe($this->enrolment->id)
        ->and($invoice->user->id)->toBe($this->student->id)
        ->and((float) $invoice->total_amount)->toBe(1180.0);
});

it('creates a fee payment linked to its invoice, and lists it via the invoice->payments relation', function () {
    $invoice = FeeInvoice::create([
        'batch_id' => $this->batch->id,
        'enrolment_id' => $this->enrolment->id,
        'user_id' => $this->student->id,
        'sequence_number' => 1,
        'invoice_number' => 'INV-1',
        'description' => 'Full fee',
        'amount' => 1000,
        'gst_amount' => 180,
        'total_amount' => 1180,
        'due_date' => now()->toDateString(),
    ]);

    $payment = FeePayment::create([
        'fee_invoice_id' => $invoice->id,
        'provider_order_id' => 'order_abc',
        'idempotency_key' => 'idem_abc',
        'amount' => 1180,
        'status' => 'created',
    ]);

    expect($payment->fresh()->provider)->toBe('razorpay')
        ->and($payment->invoice->id)->toBe($invoice->id)
        ->and($invoice->payments()->count())->toBe(1);
});

it('allows an enrolment to hold the new pending_payment status', function () {
    expect($this->enrolment->status)->toBe('pending_payment')
        ->and(Enrolment::STATUSES)->toContain('pending_payment');
});
