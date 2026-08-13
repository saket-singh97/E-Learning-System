<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\FakeRazorpayDriver;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    $this->driver = new FakeRazorpayDriver;
});

it('creates an order carrying the invoices total_amount and currency', function () {
    $order = $this->driver->createOrder($this->invoice, 'INV-TEST-1-1');

    expect($order['amount'])->toBe(1180.0)
        ->and($order['currency'])->toBe('INR');
});

it('never repeats an order_id across calls, even for the same invoice', function () {
    // A real Razorpay order_id is unique per API call — B2's retry-on-
    // failure flow depends on that to correlate a webhook to exactly one
    // FeePayment attempt (found when a retry test collided on the old,
    // purely invoice-seeded order_id).
    $first = $this->driver->createOrder($this->invoice, 'INV-TEST-1-1');
    $second = $this->driver->createOrder($this->invoice, 'INV-TEST-1-2');

    expect($first['order_id'])->not->toBe($second['order_id']);
});

it('verifies a payload signed with the fake secret and rejects one that is not', function () {
    $payload = json_encode(['event' => 'payment.captured']);
    $validSignature = hash_hmac('sha256', $payload, FakeRazorpayDriver::FAKE_SECRET);

    expect($this->driver->verifyWebhookSignature($payload, $validSignature))->toBeTrue()
        ->and($this->driver->verifyWebhookSignature($payload, 'not-a-real-signature'))->toBeFalse();
});
