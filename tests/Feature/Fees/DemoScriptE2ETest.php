<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Jobs\FlagOverdueInvoices;
use App\Domains\Fees\Jobs\SendFeeReminders;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Services\FeeInvoiceService;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\FakeRazorpayDriver;
use App\Models\User;
use App\Notifications\FeeReminderDue;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * Walks S7's own Demo #7 script (design doc §5) end to end in one story,
 * across every block this slice built: a student joins a paid,
 * instalment-plan batch, pays the first instalment via a real webhook
 * POST, lands active immediately, can download that instalment's GST
 * invoice — then, once the second instalment's due date has passed, the
 * daily sweeps flag it overdue and fire the T+3 reminder.
 *
 * Each of B1-B6 already tests its own piece in isolation with narrower
 * fixtures; nothing until now chained them into the actual multi-day
 * story the demo itself performs, which is the one thing a per-block
 * test can't catch — e.g. whether the *second* instalment survives the
 * first one's webhook capture untouched.
 */
it('runs the full paid, instalment-plan demo script end to end', function () {
    $this->seed(RoleSeeder::class);

    $tenant = Tenant::factory()->create([
        'subdomain' => 'physics',
        'gstin' => '27ABCDE1234F1Z5',
        'legal_name' => 'Physics Academy Pvt Ltd',
        'billing_address' => '221B Baker Street, Mumbai',
    ]);
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
        'instalment_plan' => [
            ['percent' => 50, 'days_after_enrolment' => 0],
            ['percent' => 50, 'days_after_enrolment' => 30],
        ],
    ]);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    // Join -> pending_payment enrolment + two materialized instalment
    // invoices (B1 order creation, B4 instalment splitting).
    $enrolment = app(BatchEnrolmentService::class)->enrol($student, $batch);

    expect($enrolment->status)->toBe('pending_payment');

    $invoices = FeeInvoice::where('enrolment_id', $enrolment->id)->orderBy('due_date')->get();
    expect($invoices)->toHaveCount(2)
        ->and($invoices[0]->description)->toBe('Instalment 1 of 2')
        ->and($invoices[1]->description)->toBe('Instalment 2 of 2');

    $firstInvoice = $invoices[0];
    $secondInvoice = $invoices[1];

    // Pay the first instalment via a real Razorpay order + a real HTTP
    // webhook POST (B2) — not a direct service call, so this exercises
    // the actual signature-verification and routing path a real payment
    // would take.
    $payment = app(FeeInvoiceService::class)->createPaymentOrder($firstInvoice);

    $payload = json_encode([
        'event' => 'payment.captured',
        'payload' => [
            'payment' => ['entity' => ['id' => 'pay_demo_script', 'order_id' => $payment->provider_order_id]],
            'order' => ['entity' => ['id' => $payment->provider_order_id, 'notes' => ['tenant_id' => (string) $tenant->id]]],
        ],
    ]);
    $signature = hash_hmac('sha256', $payload, FakeRazorpayDriver::FAKE_SECRET);

    $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
        'HTTP_X-Razorpay-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    // Auto-enrolment on payment (B2) — lands active immediately, since
    // the batch is self_enrol_direct.
    expect($enrolment->fresh()->status)->toBe('active')
        ->and($firstInvoice->fresh()->status)->toBe('paid');

    // The second instalment is untouched by the first one's capture —
    // B4's own "only the first-due invoice blocks finalization" decision,
    // confirmed here in the same flow that exercises it for real rather
    // than in isolation.
    expect($secondInvoice->fresh()->status)->toBe('pending');

    // The paid instalment's GST invoice is downloadable (B5).
    $this->actingAs($student)
        ->get("http://physics.lms.test/invoices/{$firstInvoice->id}")
        ->assertOk()
        ->assertSee($firstInvoice->invoice_number)
        ->assertSee('27ABCDE1234F1Z5');

    // 33 days on: the second instalment (due at day 30) is now 3 days
    // overdue — the daily sweeps (B6) flag it and fire the T+3 reminder.
    $this->travelTo(now()->addDays(33));
    Notification::fake();

    app(FlagOverdueInvoices::class)->handle();
    expect($secondInvoice->fresh()->status)->toBe('overdue');

    app(SendFeeReminders::class)->handle();
    Notification::assertSentTo(
        $student,
        FeeReminderDue::class,
        fn (FeeReminderDue $n) => $n->stage === 'overdue' && $n->invoice->is($secondInvoice),
    );
});
