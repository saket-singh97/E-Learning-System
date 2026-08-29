<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Events\PaymentCaptured;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Fees\Models\StudentMembershipPlan;
use App\Domains\Fees\Services\FeeInvoiceService;
use App\Domains\Fees\Services\PaymentReconciler;
use App\Domains\Growth\Models\AffiliateCode;
use App\Domains\Growth\Models\AffiliateReferral;
use App\Domains\Tenancy\Models\Tenant;
use App\Integrations\Drivers\FakeRazorpayDriver;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->affiliateCode = AffiliateCode::create([
        'owner_user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
        'code' => 'REF12345',
        'commission_type' => 'percentage',
        'commission_value' => 10,
        'status' => 'active',
    ]);
});

function commissionTestEnrolment(Tenant $tenant): Enrolment
{
    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch',
        'slug' => 'batch-'.uniqid(),
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $student = User::factory()->create(['tenant_id' => $tenant->id]);

    return Enrolment::create([
        'batch_id' => $batch->id,
        'user_id' => $student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);
}

it('confirms a pending referrals commission once its payment captures', function () {
    $enrolment = commissionTestEnrolment($this->tenant);
    $invoice = FeeInvoice::create([
        'enrolment_id' => $enrolment->id,
        'user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
        'sequence_number' => 1,
        'invoice_number' => 'INV-'.uniqid(),
        'description' => 'Row',
        'amount' => 1000,
        'gst_amount' => 180,
        'total_amount' => 1180,
        'due_date' => now()->toDateString(),
    ]);
    $payment = FeePayment::create([
        'fee_invoice_id' => $invoice->id,
        'provider_order_id' => 'order_'.uniqid(),
        'idempotency_key' => 'idem_'.uniqid(),
        'amount' => 1180,
        'status' => 'captured',
    ]);
    $referral = AffiliateReferral::create([
        'affiliate_code_id' => $this->affiliateCode->id,
        'referred_user_id' => $invoice->user_id,
        'fee_payment_id' => $payment->id,
        'commission_amount' => 0,
        'status' => 'pending',
    ]);

    event(new PaymentCaptured($invoice->enrolment_id, $this->tenant->id));

    $fresh = $referral->fresh();
    expect($fresh->status)->toBe('confirmed')
        ->and((float) $fresh->commission_amount)->toBe(118.0);
});

it('is a no-op when the captured payment has no referral', function () {
    $enrolment = commissionTestEnrolment($this->tenant);
    $invoice = FeeInvoice::create([
        'enrolment_id' => $enrolment->id,
        'user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
        'sequence_number' => 1,
        'invoice_number' => 'INV-'.uniqid(),
        'description' => 'Row',
        'amount' => 1000,
        'gst_amount' => 180,
        'total_amount' => 1180,
        'due_date' => now()->toDateString(),
    ]);
    FeePayment::create([
        'fee_invoice_id' => $invoice->id,
        'provider_order_id' => 'order_'.uniqid(),
        'idempotency_key' => 'idem_'.uniqid(),
        'amount' => 1180,
        'status' => 'captured',
    ]);

    event(new PaymentCaptured($invoice->enrolment_id, $this->tenant->id));

    expect(AffiliateReferral::count())->toBe(0);
});

it('never fires PaymentCaptured for a membership-linked payment, so this listener never even reaches it', function () {
    Event::fake([PaymentCaptured::class]);

    $plan = StudentMembershipPlan::create([
        'name' => 'Monthly Plan',
        'price' => 499,
        'billing_interval' => 'monthly',
        'trial_days' => 7,
        'status' => 'active',
    ]);
    $student = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $student->assignRole('student');

    $invoice = FeeInvoice::create([
        'student_membership_plan_id' => $plan->id,
        'user_id' => $student->id,
        'sequence_number' => 1,
        'invoice_number' => 'INV-'.uniqid(),
        'description' => 'Membership',
        'amount' => 499,
        'gst_amount' => 0,
        'total_amount' => 499,
        'due_date' => now()->toDateString(),
    ]);
    $payment = app(FeeInvoiceService::class)->createPaymentOrder($invoice);

    $payload = json_encode([
        'event' => 'payment.captured',
        'payload' => [
            'payment' => ['entity' => ['id' => 'pay_test123', 'order_id' => $payment->provider_order_id]],
            'order' => ['entity' => ['id' => $payment->provider_order_id, 'notes' => ['tenant_id' => (string) $this->tenant->id]]],
        ],
    ]);
    $signature = hash_hmac('sha256', $payload, FakeRazorpayDriver::FAKE_SECRET);

    app(PaymentReconciler::class)->reconcile($payload, $signature);

    Event::assertNotDispatched(PaymentCaptured::class);
});
