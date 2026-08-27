<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Fees\Services\RefundEligibilityService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
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
            'provider_order_id' => 'order_'.uniqid(),
            'idempotency_key' => 'idem_'.uniqid(),
            'amount' => 1180,
            'status' => $paidAt !== null ? 'captured' : 'created',
        ]);
    };

    $this->service = app(RefundEligibilityService::class);
});

it('is eligible within the tenants configured refund window', function () {
    $this->tenant->update(['refund_window_days' => 7]);
    $payment = ($this->makePayment)(now()->subDays(3));

    expect($this->service->isEligible($payment))->toBeTrue();
});

it('is refused once the tenants configured refund window has passed', function () {
    $this->tenant->update(['refund_window_days' => 7]);
    $payment = ($this->makePayment)(now()->subDays(10));

    expect($this->service->isEligible($payment))->toBeFalse();
});

it('is refused when the tenant has never configured a refund window', function () {
    $payment = ($this->makePayment)(now()->subDay());

    expect($this->service->isEligible($payment))->toBeFalse();
});

it('is refused for a payment that was never actually paid', function () {
    $this->tenant->update(['refund_window_days' => 30]);
    $payment = ($this->makePayment)(null);

    expect($this->service->isEligible($payment))->toBeFalse();
});
