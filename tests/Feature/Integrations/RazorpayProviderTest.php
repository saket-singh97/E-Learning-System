<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\RazorpayProvider;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

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
    ]);
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $enrolment = Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'pending_payment',
        'source' => 'self_enrol_direct',
        'requested_at' => now(),
    ]);

    $this->invoice = FeeInvoice::create([
        'batch_id' => $batch->id,
        'enrolment_id' => $enrolment->id,
        'user_id' => $student->id,
        'sequence_number' => 1,
        'invoice_number' => 'INV-TEST-1',
        'description' => 'Full fee',
        'amount' => 1000,
        'gst_amount' => 180,
        'total_amount' => 1180,
        'due_date' => now()->toDateString(),
    ]);

    $this->driver = new RazorpayProvider;
});

it('refuses to create an order when Razorpay credentials are not configured', function () {
    config(['services.razorpay.key_id' => null, 'services.razorpay.key_secret' => null]);

    expect(fn () => $this->driver->createOrder($this->invoice, 'INV-TEST-1-1'))->toThrow(RuntimeException::class);
});

it('creates a real order via the Razorpay API, amount in paise, tenant/invoice in notes', function () {
    config(['services.razorpay.key_id' => 'test-key', 'services.razorpay.key_secret' => 'test-secret']);

    Http::fake([
        'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_live_abc123'], 200),
    ]);

    $order = $this->driver->createOrder($this->invoice, 'INV-TEST-1-1');

    expect($order)->toBe(['order_id' => 'order_live_abc123', 'amount' => 1180.0, 'currency' => 'INR']);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.razorpay.com/v1/orders'
            && $request['amount'] === 118000
            && $request['currency'] === 'INR'
            && $request['notes']['fee_invoice_id'] === (string) $this->invoice->id;
    });
});

/**
 * Razorpay's Orders API has no idempotency *header* — that only exists on
 * its Payouts/Refunds APIs (confirmed against Razorpay's own docs at S9 B8
 * build time, not assumed from this deferred feature's own original
 * naming). `receipt` is what plays that role for order creation.
 */
it('sends the idempotency key as the orders own receipt field, not a header', function () {
    config(['services.razorpay.key_id' => 'test-key', 'services.razorpay.key_secret' => 'test-secret']);

    Http::fake([
        'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_live_abc123'], 200),
    ]);

    $this->driver->createOrder($this->invoice, 'INV-TEST-1-2');

    Http::assertSent(function ($request) {
        return $request['receipt'] === 'INV-TEST-1-2'
            && ! $request->hasHeader('Idempotency-Key')
            && ! $request->hasHeader('X-Razorpay-Idempotency');
    });
});

it('refuses to verify a webhook when the webhook secret is not configured', function () {
    config(['services.razorpay.webhook_secret' => null]);

    expect(fn () => $this->driver->verifyWebhookSignature('{}', 'sig'))->toThrow(RuntimeException::class);
});

it('verifies a webhook signature against the configured secret', function () {
    config(['services.razorpay.webhook_secret' => 'test-webhook-secret']);

    $payload = json_encode(['event' => 'payment.captured']);
    $validSignature = hash_hmac('sha256', $payload, 'test-webhook-secret');

    expect($this->driver->verifyWebhookSignature($payload, $validSignature))->toBeTrue()
        ->and($this->driver->verifyWebhookSignature($payload, 'wrong'))->toBeFalse();
});
