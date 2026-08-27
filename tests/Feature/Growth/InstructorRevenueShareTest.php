<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Growth\Models\InstructorPayout;
use App\Domains\Growth\Services\InstructorPayoutService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);

    $this->service = app(InstructorPayoutService::class);
    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->makeBatch = function (User $instructor): Batch {
        $course = Course::create(['title' => 'Course', 'slug' => 'course-'.uniqid(), 'status' => 'published']);

        return Batch::create([
            'course_id' => $course->id,
            'instructor_id' => $instructor->id,
            'title' => 'Batch',
            'slug' => 'batch-'.uniqid(),
            'starts_on' => now()->subMonth()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
            'self_enrol_mode' => 'manual_only',
            'status' => 'published',
        ]);
    };

    $this->makeCapturedPayment = function (Batch $batch, float $amount, Carbon\Carbon $capturedAt): FeePayment {
        $enrolment = Enrolment::create([
            'batch_id' => $batch->id,
            'user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'status' => 'active',
            'source' => 'manual_add',
            'requested_at' => now(),
        ]);
        $invoice = FeeInvoice::create([
            'batch_id' => $batch->id,
            'enrolment_id' => $enrolment->id,
            'user_id' => $enrolment->user_id,
            'sequence_number' => FeeInvoice::count() + 1,
            'invoice_number' => 'INV-'.uniqid(),
            'description' => 'Row',
            'amount' => $amount,
            'gst_amount' => 0,
            'total_amount' => $amount,
            'due_date' => now()->toDateString(),
            'status' => 'paid',
            'paid_at' => $capturedAt,
        ]);

        return FeePayment::create([
            'fee_invoice_id' => $invoice->id,
            'provider_order_id' => 'order_'.uniqid(),
            'idempotency_key' => 'idem_'.uniqid(),
            'amount' => $amount,
            'status' => 'captured',
            'webhook_verified_at' => $capturedAt,
        ]);
    };
});

it('computes revenue share against a known set of seeded payments for the instructors own batches', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $batch = ($this->makeBatch)($instructor);

    $periodStart = Carbon\Carbon::parse('2026-08-01');
    $periodEnd = Carbon\Carbon::parse('2026-08-31');

    ($this->makeCapturedPayment)($batch, 1000, Carbon\Carbon::parse('2026-08-05'));
    ($this->makeCapturedPayment)($batch, 2000, Carbon\Carbon::parse('2026-08-20'));

    $payout = $this->service->createRevenueShare($instructor, $periodStart, $periodEnd, 10);

    // (1000 + 2000) * 10% = 300.
    expect((float) $payout->amount)->toBe(300.0)
        ->and($payout->basis)->toBe('revenue_share')
        ->and($payout->status)->toBe('pending');
});

it('never lets a different instructors payments leak into the calculation', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherInstructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $batch = ($this->makeBatch)($instructor);
    $otherBatch = ($this->makeBatch)($otherInstructor);

    ($this->makeCapturedPayment)($batch, 1000, Carbon\Carbon::parse('2026-08-05'));
    ($this->makeCapturedPayment)($otherBatch, 5000, Carbon\Carbon::parse('2026-08-05'));

    $payout = $this->service->createRevenueShare($instructor, Carbon\Carbon::parse('2026-08-01'), Carbon\Carbon::parse('2026-08-31'), 10);

    expect((float) $payout->amount)->toBe(100.0);
});

it('excludes a payment captured outside the payout period', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $batch = ($this->makeBatch)($instructor);

    ($this->makeCapturedPayment)($batch, 1000, Carbon\Carbon::parse('2026-08-05'));
    ($this->makeCapturedPayment)($batch, 9000, Carbon\Carbon::parse('2026-09-05'));

    $payout = $this->service->createRevenueShare($instructor, Carbon\Carbon::parse('2026-08-01'), Carbon\Carbon::parse('2026-08-31'), 10);

    expect((float) $payout->amount)->toBe(100.0);
});

it('walks a revenue-share payout through approve to paid, same as a salaried one', function () {
    $instructor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $batch = ($this->makeBatch)($instructor);
    ($this->makeCapturedPayment)($batch, 1000, Carbon\Carbon::parse('2026-08-05'));

    $payout = $this->service->createRevenueShare($instructor, Carbon\Carbon::parse('2026-08-01'), Carbon\Carbon::parse('2026-08-31'), 10);

    $approved = $this->service->approve($payout, $this->admin);
    $paid = $this->service->markPaid($approved);

    expect($paid->status)->toBe('paid')
        ->and($paid->paid_at)->not->toBeNull();
});
