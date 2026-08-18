<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function softDeleteFeeInvoiceFixture(): FeeInvoice
{
    $tenantId = app(CurrentTenant::class)->id();
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
    $student = User::factory()->create(['tenant_id' => $tenantId]);
    $enrolment = Enrolment::create(['batch_id' => $batch->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'manual_add', 'requested_at' => now()]);

    return FeeInvoice::create([
        'batch_id' => $batch->id,
        'enrolment_id' => $enrolment->id,
        'user_id' => $student->id,
        'sequence_number' => 1,
        'invoice_number' => 'INV-'.uniqid(),
        'description' => 'Fee',
        'amount' => 100,
        'gst_amount' => 18,
        'total_amount' => 118,
        'due_date' => now()->toDateString(),
        'status' => 'pending',
    ]);
}

it('hides a deleted invoice and restores it (S12 B13)', function () {
    app(CurrentTenant::class)->set(Tenant::factory()->create());
    $invoice = softDeleteFeeInvoiceFixture();

    $invoice->delete();

    expect(FeeInvoice::find($invoice->id))->toBeNull();
    expect(FeeInvoice::withTrashed()->find($invoice->id))->not->toBeNull();

    $invoice->restore();

    expect(FeeInvoice::find($invoice->id))->not->toBeNull();
});

it('hides a deleted payment and restores it (S12 B13)', function () {
    app(CurrentTenant::class)->set(Tenant::factory()->create());
    $invoice = softDeleteFeeInvoiceFixture();
    $payment = FeePayment::create([
        'fee_invoice_id' => $invoice->id,
        'provider' => 'razorpay',
        'provider_order_id' => 'order_'.uniqid(),
        'idempotency_key' => 'idem_'.uniqid(),
        'amount' => 118,
        'status' => 'captured',
    ]);

    $payment->delete();

    expect(FeePayment::find($payment->id))->toBeNull();
    expect(FeePayment::withTrashed()->find($payment->id))->not->toBeNull();

    $payment->restore();

    expect(FeePayment::find($payment->id))->not->toBeNull();
});

/**
 * S12 B13 — design doc §4 decision 12's own explicit warning: "confirm
 * the tenant scope still excludes a different tenant's soft-deleted row
 * when withTrashed() is used." `BelongsToTenant`'s global scope
 * (registered under the string key 'tenant') and `SoftDeletingScope`
 * are independent — `withTrashed()` only drops the latter by class, so
 * the tenant scope should stay enforced regardless. Not covered by the
 * generated `CrossTenantTest` suite (it never calls `delete()`/
 * `withTrashed()` on any registered model), so proven directly here.
 */
it('never leaks another tenants soft-deleted invoice through withTrashed()', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    app(CurrentTenant::class)->set($tenantA);
    $invoiceA = softDeleteFeeInvoiceFixture();
    $invoiceA->delete();

    app(CurrentTenant::class)->set($tenantB);
    $invoiceB = softDeleteFeeInvoiceFixture();
    $invoiceB->delete();

    // Still scoped to tenant B — tenant A's own trashed row must not
    // appear even with SoftDeletingScope dropped.
    $visible = FeeInvoice::withTrashed()->pluck('id')->all();

    expect($visible)->toContain($invoiceB->id)
        ->and($visible)->not->toContain($invoiceA->id);
});
