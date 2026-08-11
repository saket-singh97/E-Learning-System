<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Services\FeeInvoiceService;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\FakeRazorpayDriver;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    $enrolment = app(BatchEnrolmentService::class)->enrol($student, $batch);
    $invoice = FeeInvoice::where('enrolment_id', $enrolment->id)->sole();
    $this->payment = app(FeeInvoiceService::class)->createPaymentOrder($invoice);
    $this->enrolment = $enrolment;
});

it('captures a payment and finalizes the enrolment end-to-end over a real HTTP POST', function () {
    // Signed against the exact raw bytes sent as the request body — going
    // through postJson()'s own array->JSON re-encoding risks a byte
    // mismatch (key order, slash-escaping) against what was signed, which
    // would make this test fail for a reason that has nothing to do with
    // the controller itself.
    $payload = json_encode([
        'event' => 'payment.captured',
        'payload' => [
            'payment' => ['entity' => ['id' => 'pay_http_test', 'order_id' => $this->payment->provider_order_id]],
            'order' => ['entity' => ['id' => $this->payment->provider_order_id, 'notes' => ['tenant_id' => (string) $this->tenant->id]]],
        ],
    ]);
    $signature = hash_hmac('sha256', $payload, FakeRazorpayDriver::FAKE_SECRET);

    $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
        'HTTP_X-Razorpay-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    expect($this->payment->fresh()->status)->toBe('captured')
        ->and($this->enrolment->fresh()->status)->toBe('active');
});

it('rejects a webhook with a bad signature', function () {
    $payload = json_encode(['event' => 'payment.captured', 'payload' => []]);

    $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
        'HTTP_X-Razorpay-Signature' => 'forged',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertStatus(400);
});
