<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Events\PaymentCaptured;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Fees\Services\FeeInvoiceService;
use App\Domains\Fees\Services\PaymentReconciler;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\InvalidWebhookSignatureException;
use App\Integrations\Drivers\FakeRazorpayDriver;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Morning Batch',
        'slug' => 'morning-batch',
        'starts_on' => '2026-09-01',
        'ends_on' => '2026-12-01',
        'self_enrol_mode' => 'self_enrol_direct',
        'status' => 'published',
        'is_paid' => true,
        'price' => 1000,
        'seat_limit' => 10,
    ]);

    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $this->enrolment = app(BatchEnrolmentService::class)->enrol($student, $batch);
    $this->invoice = FeeInvoice::where('enrolment_id', $this->enrolment->id)->sole();
    $this->payment = app(FeeInvoiceService::class)->createPaymentOrder($this->invoice);

    $this->reconciler = app(PaymentReconciler::class);

    $this->payload = fn (string $event, ?string $paymentId = 'pay_test123', ?string $failureReason = null): string => json_encode([
        'event' => $event,
        'payload' => [
            'payment' => [
                'entity' => array_filter([
                    'id' => $paymentId,
                    'order_id' => $this->payment->provider_order_id,
                    'error_description' => $failureReason,
                ], fn ($v) => $v !== null),
            ],
            'order' => [
                'entity' => [
                    'id' => $this->payment->provider_order_id,
                    'notes' => ['tenant_id' => (string) $this->tenant->id],
                ],
            ],
        ],
    ]);

    $this->sign = fn (string $payload): string => hash_hmac('sha256', $payload, FakeRazorpayDriver::FAKE_SECRET);
});

it('captures a payment, marks the invoice paid, and dispatches PaymentCaptured', function () {
    Event::fake([PaymentCaptured::class]);

    $payload = ($this->payload)('payment.captured');
    $this->reconciler->reconcile($payload, ($this->sign)($payload));

    expect($this->payment->fresh()->status)->toBe('captured')
        ->and($this->payment->fresh()->provider_payment_id)->toBe('pay_test123')
        ->and($this->payment->fresh()->webhook_verified_at)->not->toBeNull()
        ->and($this->invoice->fresh()->status)->toBe('paid')
        ->and($this->invoice->fresh()->paid_at)->not->toBeNull();

    Event::assertDispatched(PaymentCaptured::class, fn ($event) => $event->enrolmentId === $this->enrolment->id);
});

it('throws on an invalid signature and touches nothing', function () {
    $payload = ($this->payload)('payment.captured');

    expect(fn () => $this->reconciler->reconcile($payload, 'not-a-real-signature'))
        ->toThrow(InvalidWebhookSignatureException::class);

    expect($this->payment->fresh()->status)->toBe('created');
});

it('is idempotent: processing the same captured payload twice only captures once', function () {
    Event::fake([PaymentCaptured::class]);

    $payload = ($this->payload)('payment.captured');
    $signature = ($this->sign)($payload);

    $this->reconciler->reconcile($payload, $signature);
    $this->reconciler->reconcile($payload, $signature);

    Event::assertDispatchedTimes(PaymentCaptured::class, 1);
});

it('marks a failed payment without touching the invoice, leaving it retryable', function () {
    $payload = ($this->payload)('payment.failed', paymentId: null, failureReason: 'Insufficient funds');
    $this->reconciler->reconcile($payload, ($this->sign)($payload));

    expect($this->payment->fresh()->status)->toBe('failed')
        ->and($this->payment->fresh()->failure_reason)->toBe('Insufficient funds')
        ->and($this->invoice->fresh()->status)->toBe('pending');
});

it('allows a fresh order to be created and captured after a failed attempt (retry)', function () {
    $failedPayload = ($this->payload)('payment.failed', paymentId: null, failureReason: 'Card declined');
    $this->reconciler->reconcile($failedPayload, ($this->sign)($failedPayload));

    $retryPayment = app(FeeInvoiceService::class)->createPaymentOrder($this->invoice->fresh());

    $capturedPayload = json_encode([
        'event' => 'payment.captured',
        'payload' => [
            'payment' => ['entity' => ['id' => 'pay_retry', 'order_id' => $retryPayment->provider_order_id]],
            'order' => ['entity' => ['id' => $retryPayment->provider_order_id, 'notes' => ['tenant_id' => (string) $this->tenant->id]]],
        ],
    ]);
    $this->reconciler->reconcile($capturedPayload, ($this->sign)($capturedPayload));

    expect($retryPayment->fresh()->status)->toBe('captured')
        ->and($this->invoice->fresh()->status)->toBe('paid')
        ->and(FeePayment::where('fee_invoice_id', $this->invoice->id)->count())->toBe(2);
});

it('ignores an event type it does not recognise', function () {
    $payload = ($this->payload)('order.paid');
    $this->reconciler->reconcile($payload, ($this->sign)($payload));

    expect($this->payment->fresh()->status)->toBe('created');
});

it('ignores a payload whose tenant_id does not resolve to a real tenant', function () {
    $payload = json_encode([
        'event' => 'payment.captured',
        'payload' => [
            'payment' => ['entity' => ['id' => 'pay_x', 'order_id' => $this->payment->provider_order_id]],
            'order' => ['entity' => ['id' => $this->payment->provider_order_id, 'notes' => ['tenant_id' => '999999']]],
        ],
    ]);

    $this->reconciler->reconcile($payload, ($this->sign)($payload));

    expect($this->payment->fresh()->status)->toBe('created');
});
