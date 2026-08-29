<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Events\PaymentCaptured;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Fees\Models\PaymentHistoryImportBatch;
use App\Domains\Fees\Services\PaymentHistoryCsvImporter;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\InvalidPaymentHistoryImportFileException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
    $this->uploader = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $course = Course::create(['title' => 'Physics', 'slug' => 'physics-'.uniqid(), 'status' => 'published']);
    $this->batch = Batch::create([
        'course_id' => $course->id,
        'title' => 'Batch A',
        'slug' => 'batch-a',
        'starts_on' => now()->toDateString(),
        'ends_on' => now()->addMonth()->toDateString(),
        'self_enrol_mode' => 'manual_only',
        'status' => 'published',
    ]);
    $this->student = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'asha@example.com']);
    $this->enrolment = Enrolment::create([
        'batch_id' => $this->batch->id,
        'user_id' => $this->student->id,
        'status' => 'active',
        'source' => 'manual_add',
        'requested_at' => now(),
    ]);

    $this->importer = app(PaymentHistoryCsvImporter::class);
    $this->header = 'email,batch,invoice_number,amount,gst_amount,total_amount,paid_at';
});

it('rejects a file with no data rows', function () {
    expect(fn () => $this->importer->import("{$this->header}\n", $this->uploader, true))
        ->toThrow(InvalidPaymentHistoryImportFileException::class);
});

it('rejects a file missing a required header', function () {
    expect(fn () => $this->importer->import("email,batch\nasha@example.com,batch-a\n", $this->uploader, true))
        ->toThrow(InvalidPaymentHistoryImportFileException::class);
});

it('dry run reports errors with zero writes, catching a missing student, batch, and enrolment', function () {
    $csv = "{$this->header}\n"
        ."asha@example.com,batch-a,INV-OLD-1,1000,180,1180,2026-01-15\n" // valid
        ."ghost@example.com,batch-a,INV-OLD-2,1000,180,1180,2026-01-15\n" // no such student
        ."asha@example.com,no-such-batch,INV-OLD-3,1000,180,1180,2026-01-15\n" // no such batch
        ."not-a-number,batch-a,INV-OLD-4,abc,180,1180,2026-01-15\n"; // bad email + non-numeric amount

    $batch = $this->importer->import($csv, $this->uploader, true);

    expect($batch->status)->toBe('dry_run')
        ->and($batch->total_rows)->toBe(4)
        ->and($batch->error_rows)->toBe(3)
        ->and($batch->error_report_path)->not->toBeNull();

    expect(FeeInvoice::count())->toBe(0)
        ->and(FeePayment::count())->toBe(0);
});

it('catches a payment for a student/batch pair with no enrolment on record', function () {
    $otherStudent = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'priya@example.com']);

    $csv = "{$this->header}\npriya@example.com,batch-a,INV-OLD-5,1000,180,1180,2026-01-15\n";

    $batch = $this->importer->import($csv, $this->uploader, true);

    expect($batch->error_rows)->toBe(1);
});

it('catches a duplicate invoice_number within the same file (S21 B15 review fix)', function () {
    $csv = "{$this->header}\n"
        ."asha@example.com,batch-a,INV-OLD-1,1000,180,1180,2026-01-15\n"
        ."asha@example.com,batch-a,INV-OLD-1,500,90,590,2026-02-15\n";

    $batch = $this->importer->import($csv, $this->uploader, true);

    expect($batch->error_rows)->toBe(1);
});

it('catches an invoice_number already used by a real invoice (S21 B15 review fix)', function () {
    FeeInvoice::create([
        'batch_id' => $this->batch->id,
        'enrolment_id' => $this->enrolment->id,
        'user_id' => $this->student->id,
        'sequence_number' => 1,
        'invoice_number' => 'INV-OLD-1',
        'description' => 'Existing',
        'amount' => 100,
        'gst_amount' => 18,
        'total_amount' => 118,
        'due_date' => now()->toDateString(),
    ]);

    $csv = "{$this->header}\nasha@example.com,batch-a,INV-OLD-1,1000,180,1180,2026-01-15\n";

    $batch = $this->importer->import($csv, $this->uploader, true);

    expect($batch->error_rows)->toBe(1);
});

it('a real run creates matching invoice/payment pairs, already paid and captured, skipping bad rows', function () {
    Event::fake([PaymentCaptured::class]);

    $csv = "{$this->header}\n"
        ."asha@example.com,batch-a,INV-OLD-1,1000,180,1180,2026-01-15\n"
        ."ghost@example.com,batch-a,INV-OLD-2,1000,180,1180,2026-01-15\n";

    $result = $this->importer->import($csv, $this->uploader, false);

    expect($result->status)->toBe('completed')
        ->and($result->total_rows)->toBe(2)
        ->and($result->error_rows)->toBe(1);

    $invoice = FeeInvoice::where('invoice_number', 'INV-OLD-1')->sole();
    expect($invoice->status)->toBe('paid')
        ->and($invoice->paid_at)->not->toBeNull()
        ->and($invoice->enrolment_id)->toBe($this->enrolment->id)
        ->and((float) $invoice->total_amount)->toBe(1180.0);

    $payment = FeePayment::where('fee_invoice_id', $invoice->id)->sole();
    expect($payment->status)->toBe('captured')
        ->and($payment->provider)->toBe('imported');

    // Never a live payment event for imported (already-settled) history.
    Event::assertNotDispatched(PaymentCaptured::class);
});

it('writes no error report when every row is valid', function () {
    $csv = "{$this->header}\nasha@example.com,batch-a,INV-OLD-1,1000,180,1180,2026-01-15\n";

    $batch = $this->importer->import($csv, $this->uploader, false);

    expect($batch->error_report_path)->toBeNull();
});
