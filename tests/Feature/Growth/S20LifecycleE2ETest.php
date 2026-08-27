<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Batches\Services\BatchEnrolmentService;
use App\Domains\Content\Models\Course;
use App\Domains\Content\Models\CourseSection;
use App\Domains\Content\Models\Lesson;
use App\Domains\Content\Services\LessonAccess;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Fees\Models\FeeRefund;
use App\Domains\Fees\Services\FeeInvoiceService;
use App\Domains\Fees\Services\PaymentReconciler;
use App\Domains\Fees\Services\RefundApprovalService;
use App\Domains\Growth\Models\AffiliateCode;
use App\Domains\Growth\Models\AffiliateReferral;
use App\Domains\Growth\Services\AffiliateAttributionService;
use App\Domains\Growth\Services\InstructorPayoutService;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\FakeRazorpayDriver;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * S20 B14 — the one thing no per-block test could cover: three real
 * stories chained end to end, same posture every prior slice's own final
 * block established (e.g. SubscriptionLifecycleE2ETest, S11 B16). Each
 * scenario drives real services (webhook capture included, not a
 * shortcut flip of a status column) and asserts the state after every
 * step, not just the terminal one.
 */
function s20CaptureViaWebhook(Tenant $tenant, FeePayment $payment): void
{
    $payload = json_encode([
        'event' => 'payment.captured',
        'payload' => [
            'payment' => ['entity' => ['id' => 'pay_'.uniqid(), 'order_id' => $payment->provider_order_id]],
            'order' => ['entity' => ['id' => $payment->provider_order_id, 'notes' => ['tenant_id' => (string) $tenant->id]]],
        ],
    ]);
    $signature = hash_hmac('sha256', $payload, FakeRazorpayDriver::FAKE_SECRET);

    app(PaymentReconciler::class)->reconcile($payload, $signature);
}

it('blocks batch access on an overdue instalment, then restores it once the real payment is captured', function () {
    test()->seed(RoleSeeder::class);

    $tenant = Tenant::factory()->create(['fee_grace_period_days' => 7]);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Mechanics', 'slug' => 'mechanics', 'status' => 'published']);
    $section = CourseSection::create(['course_id' => $course->id, 'title' => 'Section', 'position' => 0]);
    $lesson = Lesson::create(['course_section_id' => $section->id, 'title' => 'Lesson', 'type' => 'text', 'position' => 0, 'text_body' => 'Hello']);

    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $student->assignRole('student');

    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->subMonth()->toDateString(),
        'ends_on' => now()->addMonths(2)->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $enrolment = Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);
    $invoice = FeeInvoice::create([
        'batch_id' => $batch->id,
        'enrolment_id' => $enrolment->id,
        'user_id' => $student->id,
        'sequence_number' => 1,
        'invoice_number' => 'INV-'.uniqid(),
        'description' => 'Instalment',
        'amount' => 1000,
        'gst_amount' => 180,
        'total_amount' => 1180,
        'due_date' => now()->subDays(10)->toDateString(),
        'status' => 'pending',
    ]);

    $access = app(LessonAccess::class);
    expect($access->canStream($lesson, $student))->toBeFalse();

    $payment = app(FeeInvoiceService::class)->createPaymentOrder($invoice->fresh());
    s20CaptureViaWebhook($tenant, $payment);

    expect($invoice->fresh()->status)->toBe('paid')
        ->and($access->canStream($lesson, $student))->toBeTrue();
});

it('reverses a confirmed affiliate commission the moment its own payments refund is processed', function () {
    test()->seed(RoleSeeder::class);

    $tenant = Tenant::factory()->create(['refund_window_days' => 30]);
    app(CurrentTenant::class)->set($tenant);

    $course = Course::create(['title' => 'Chemistry', 'slug' => 'chemistry', 'status' => 'published']);
    $student = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonths(2)->toDateString(),
        'self_enrol_mode' => 'self_enrol_direct',
        'status' => 'published',
        'is_paid' => true,
        'price' => 1180,
    ]);
    $enrolment = app(BatchEnrolmentService::class)->enrol($student, $batch);
    $invoice = FeeInvoice::where('enrolment_id', $enrolment->id)->sole();
    $payment = app(FeeInvoiceService::class)->createPaymentOrder($invoice);
    s20CaptureViaWebhook($tenant, $payment);

    $affiliateCode = AffiliateCode::create([
        'owner_user_id' => User::factory()->create(['tenant_id' => $tenant->id])->id,
        'code' => 'E2EAFF01',
        'commission_type' => 'percentage',
        'commission_value' => 10,
        'status' => 'active',
    ]);
    $referral = app(AffiliateAttributionService::class)->attribute($payment->fresh(), 'E2EAFF01');
    app(AffiliateAttributionService::class)->confirmCommission($referral);
    expect($referral->fresh()->status)->toBe('confirmed');

    $refund = FeeRefund::create([
        'fee_payment_id' => $payment->id,
        'requested_by_user_id' => $student->id,
        'amount' => (float) $payment->fresh()->amount,
        'reason' => 'Student withdrew',
        'status' => 'pending',
    ]);
    app(RefundApprovalService::class)->approve($refund);

    expect($refund->fresh()->status)->toBe('processed')
        ->and($payment->fresh()->status)->toBe('refunded')
        ->and(AffiliateReferral::find($referral->id)->status)->toBe('reversed');
});

it('computes and pays an instructors revenue-share payout against real captured fee payments', function () {
    test()->seed(RoleSeeder::class);

    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    $instructor = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $course = Course::create(['title' => 'Biology', 'slug' => 'biology', 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'instructor_id' => $instructor->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonths(2)->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);

    foreach ([1000, 2000] as $price) {
        $student = User::factory()->create(['tenant_id' => $tenant->id]);
        $enrolment = Enrolment::create([
            'batch_id' => $batch->id,
            'user_id' => $student->id,
            'status' => 'active',
            'source' => 'manual_add',
            'requested_at' => now(),
        ]);
        $invoice = FeeInvoice::create([
            'batch_id' => $batch->id,
            'enrolment_id' => $enrolment->id,
            'user_id' => $student->id,
            'sequence_number' => FeeInvoice::count() + 1,
            'invoice_number' => 'INV-'.uniqid(),
            'description' => 'Full fee',
            'amount' => $price,
            'gst_amount' => 0,
            'total_amount' => $price,
            'due_date' => now()->toDateString(),
        ]);
        $payment = app(FeeInvoiceService::class)->createPaymentOrder($invoice);
        s20CaptureViaWebhook($tenant, $payment);
    }

    $payoutService = app(InstructorPayoutService::class);
    $payout = $payoutService->createRevenueShare($instructor, now()->subDay(), now()->addDay(), 10);

    // (1000 + 2000) * 10% = 300.
    expect((float) $payout->amount)->toBe(300.0);

    $approved = $payoutService->approve($payout, $admin);
    $paid = $payoutService->markPaid($approved);

    expect($paid->status)->toBe('paid')
        ->and($paid->paid_at)->not->toBeNull();
});
