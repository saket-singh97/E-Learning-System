<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\Program;
use App\Domains\Content\Services\ProgramEnrolmentService;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Services\FeeInvoiceService;
use App\Domains\Fees\Services\PaymentReconciler;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\FakeRazorpayDriver;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function programPurchaseBatch(string $slug): Batch
{
    $course = Course::create(['title' => 'Course', 'slug' => 'course-'.uniqid(), 'status' => 'published']);

    return Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => $slug,
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['subdomain' => 'physics']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->program = Program::create(['title' => 'Exam Prep Bundle', 'slug' => 'exam-prep-bundle', 'price' => 4999, 'status' => 'published']);
    $this->batchOne = programPurchaseBatch('batch-one');
    $this->batchTwo = programPurchaseBatch('batch-two');
    $this->program->batches()->attach([$this->batchOne->id, $this->batchTwo->id]);
});

it('creates one bundle invoice for the program price with no single batch or enrolment', function () {
    $invoice = app(FeeInvoiceService::class)->invoiceForProgram($this->program, $this->student);

    expect($invoice->program_id)->toBe($this->program->id)
        ->and($invoice->batch_id)->toBeNull()
        ->and($invoice->enrolment_id)->toBeNull()
        ->and($invoice->user_id)->toBe($this->student->id)
        ->and((float) $invoice->amount)->toBe(4999.0)
        ->and((float) $invoice->total_amount)->toBe(round(4999 * 1.18, 2))
        ->and($invoice->status)->toBe('pending');
});

it('enrols the user into every bundled batch once the invoice is processed', function () {
    $invoice = app(FeeInvoiceService::class)->invoiceForProgram($this->program, $this->student);

    app(ProgramEnrolmentService::class)->enrolFromInvoice($invoice);

    expect(Enrolment::where('user_id', $this->student->id)->where('batch_id', $this->batchOne->id)->where('status', 'active')->exists())->toBeTrue();
    expect(Enrolment::where('user_id', $this->student->id)->where('batch_id', $this->batchTwo->id)->where('status', 'active')->exists())->toBeTrue();
    expect(Enrolment::where('user_id', $this->student->id)->count())->toBe(2);
});

it('does not duplicate enrolments when processed twice (retried webhook)', function () {
    $invoice = app(FeeInvoiceService::class)->invoiceForProgram($this->program, $this->student);

    app(ProgramEnrolmentService::class)->enrolFromInvoice($invoice);
    app(ProgramEnrolmentService::class)->enrolFromInvoice($invoice);

    expect(Enrolment::where('user_id', $this->student->id)->count())->toBe(2);
});

it('runs the full webhook-to-enrolment loop, idempotent on a retried delivery', function () {
    $invoice = app(FeeInvoiceService::class)->invoiceForProgram($this->program, $this->student);
    $payment = app(FeeInvoiceService::class)->createPaymentOrder($invoice);

    $payload = json_encode([
        'event' => 'payment.captured',
        'payload' => [
            'payment' => ['entity' => ['id' => 'pay_program_test', 'order_id' => $payment->provider_order_id]],
            'order' => ['entity' => ['id' => $payment->provider_order_id, 'notes' => ['tenant_id' => (string) $this->tenant->id]]],
        ],
    ]);
    $signature = hash_hmac('sha256', $payload, FakeRazorpayDriver::FAKE_SECRET);

    $reconciler = app(PaymentReconciler::class);
    $reconciler->reconcile($payload, $signature);
    // Razorpay retries webhook delivery on anything but a 2xx — a second,
    // identical delivery of the exact same event must not double-enrol.
    $reconciler->reconcile($payload, $signature);

    expect($invoice->fresh()->status)->toBe('paid');
    expect(Enrolment::where('user_id', $this->student->id)->where('batch_id', $this->batchOne->id)->count())->toBe(1);
    expect(Enrolment::where('user_id', $this->student->id)->where('batch_id', $this->batchTwo->id)->count())->toBe(1);
    expect(Enrolment::where('user_id', $this->student->id)->count())->toBe(2);
});
