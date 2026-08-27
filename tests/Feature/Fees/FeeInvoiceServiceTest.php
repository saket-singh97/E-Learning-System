<?php

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Course;
use App\Domains\Fees\Models\FeeCoupon;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Fees\Services\FeeInvoiceService;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\CouponNotRedeemableException;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['gstin' => '27ABCDE1234F1Z5']);
    app(CurrentTenant::class)->set($this->tenant);

    $this->course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'status' => 'published']);

    $this->makeEnrolment = function (float $price = 1000, array $batchOverrides = []): Enrolment {
        $batch = Batch::create(array_merge([
            'course_id' => $this->course->id,
            'title' => 'Morning Batch',
            'slug' => 'morning-batch-'.uniqid(),
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-01',
            'self_enrol_mode' => 'self_enrol_direct',
            'status' => 'published',
            'is_paid' => true,
            'price' => $price,
        ], $batchOverrides));
        $student = User::factory()->create(['tenant_id' => $this->tenant->id]);

        return Enrolment::create([
            'batch_id' => $batch->id,
            'user_id' => $student->id,
            'status' => 'pending_payment',
            'source' => 'self_enrol_direct',
            'requested_at' => now(),
        ]);
    };

    $this->service = app(FeeInvoiceService::class);

    // Most tests only care about a single, full-fee invoice — this wraps
    // the now-plural invoicesFor() down to the one row they expect.
    $this->makeInvoice = fn (float $price = 1000, array $batchOverrides = []): FeeInvoice => $this->service
        ->invoicesFor(($this->makeEnrolment)($price, $batchOverrides))
        ->sole();

    $this->makeCoupon = fn (array $overrides = []): FeeCoupon => FeeCoupon::create(array_merge([
        'code' => 'WELCOME'.strtoupper(substr(uniqid(), -6)),
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'status' => 'active',
    ], $overrides));
});

it('computes amount, 18% GST, and total_amount from the batchs price', function () {
    $invoice = ($this->makeInvoice)(1000);

    expect((float) $invoice->amount)->toBe(1000.0)
        ->and((float) $invoice->gst_rate)->toBe(18.0)
        ->and((float) $invoice->gst_amount)->toBe(180.0)
        ->and((float) $invoice->total_amount)->toBe(1180.0)
        ->and($invoice->description)->toBe('Full fee')
        ->and($invoice->currency)->toBe('INR');
});

it('falls back to the 18% GST default and INR when the tenant has not configured its own tax/currency', function () {
    // Byte-identical to pre-S20-B5 behavior for every tenant that never
    // touches the new tax_rate/tax_label/default_currency columns.
    $invoice = ($this->makeInvoice)(1000);

    expect((float) $invoice->gst_rate)->toBe(18.0)
        ->and($invoice->currency)->toBe('INR');
});

it('reads the tenants own configured tax rate and currency instead of the hardcoded default', function () {
    $this->tenant->update(['tax_rate' => 20, 'tax_label' => 'VAT', 'default_currency' => 'USD']);

    $invoice = ($this->makeInvoice)(1000);

    expect((float) $invoice->gst_rate)->toBe(20.0)
        ->and((float) $invoice->gst_amount)->toBe(200.0)
        ->and((float) $invoice->total_amount)->toBe(1200.0)
        ->and($invoice->currency)->toBe('USD')
        ->and($invoice->currencySymbol())->toBe('$');
});

it('snapshots the tenants GSTIN at issue time, immune to a later change', function () {
    $invoice = ($this->makeInvoice)();

    $this->tenant->update(['gstin' => 'CHANGED_LATER']);

    expect($invoice->fresh()->gstin_snapshot)->toBe('27ABCDE1234F1Z5');
});

it('assigns strictly increasing sequence numbers per tenant', function () {
    $first = ($this->makeInvoice)();
    $second = ($this->makeInvoice)();

    expect($second->sequence_number)->toBe($first->sequence_number + 1)
        ->and($second->invoice_number)->not->toBe($first->invoice_number);
});

it('formats the invoice number as INV-{tenant}-{financial year}-{sequence}', function () {
    $this->travelTo(Carbon\Carbon::parse('2026-08-11'));

    $invoice = ($this->makeInvoice)();

    expect($invoice->invoice_number)->toBe("INV-{$this->tenant->id}-2026-27-{$invoice->sequence_number}");
});

it('creates a FeePayment via the bound PaymentProvider when starting a checkout order', function () {
    $invoice = ($this->makeInvoice)(1000);

    $payment = $this->service->createPaymentOrder($invoice);

    expect($payment->fee_invoice_id)->toBe($invoice->id)
        ->and($payment->fresh()->provider)->toBe('razorpay')
        ->and($payment->fresh()->status)->toBe('created')
        ->and((float) $payment->amount)->toBe(1180.0)
        ->and($payment->provider_order_id)->not->toBeEmpty()
        ->and($payment->idempotency_key)->toBe("{$invoice->invoice_number}-1")
        ->and(FeePayment::where('idempotency_key', $payment->idempotency_key)->count())->toBe(1);
});

it('reuses the same still-open FeePayment on a second checkout call instead of creating a duplicate', function () {
    // Razorpay's own Orders API rejects a re-used receipt outright (400
    // "Duplicate request") rather than handing back the original order —
    // this guard is what actually has to prevent calling the provider
    // twice for the same still-open attempt, a double-click or a network
    // retry included.
    $invoice = ($this->makeInvoice)(1000);

    $first = $this->service->createPaymentOrder($invoice);
    $second = $this->service->createPaymentOrder($invoice->fresh());

    expect($second->id)->toBe($first->id);
    expect(FeePayment::where('fee_invoice_id', $invoice->id)->count())->toBe(1);
});

it('starts a genuinely new attempt with its own idempotency key once the previous one failed', function () {
    $invoice = ($this->makeInvoice)(1000);

    $first = $this->service->createPaymentOrder($invoice);
    $first->update(['status' => 'failed']);

    $second = $this->service->createPaymentOrder($invoice->fresh());

    expect($second->id)->not->toBe($first->id)
        ->and($second->idempotency_key)->toBe("{$invoice->invoice_number}-2")
        ->and(FeePayment::where('fee_invoice_id', $invoice->id)->count())->toBe(2);
});

it('applies a percentage coupon to the pre-tax amount, recomputing GST on the discounted base', function () {
    $invoice = ($this->makeInvoice)(1000);
    $coupon = ($this->makeCoupon)(['discount_type' => 'percentage', 'discount_value' => 10]);

    $result = $this->service->applyCoupon($invoice, $coupon);

    // 1000 - 10% = 900 taxable, 18% GST on 900 = 162, total 1062.
    expect((float) $result->discount_amount)->toBe(100.0)
        ->and((float) $result->gst_amount)->toBe(162.0)
        ->and((float) $result->total_amount)->toBe(1062.0)
        ->and((float) $result->amount)->toBe(1000.0)
        ->and($result->coupon_id)->toBe($coupon->id);
});

it('applies a fixed coupon, capped at the invoices pre-tax amount so the discount cannot go negative', function () {
    $invoice = ($this->makeInvoice)(100);
    $coupon = ($this->makeCoupon)(['discount_type' => 'fixed', 'discount_value' => 500]);

    $result = $this->service->applyCoupon($invoice, $coupon);

    expect((float) $result->discount_amount)->toBe(100.0)
        ->and((float) $result->gst_amount)->toBe(0.0)
        ->and((float) $result->total_amount)->toBe(0.0);
});

it('increments the coupons used_count on redemption', function () {
    $invoice = ($this->makeInvoice)();
    $coupon = ($this->makeCoupon)();

    $this->service->applyCoupon($invoice, $coupon);

    expect($coupon->fresh()->used_count)->toBe(1);
});

it('refuses a coupon that is not active', function () {
    $invoice = ($this->makeInvoice)();
    $coupon = ($this->makeCoupon)(['status' => 'disabled']);

    expect(fn () => $this->service->applyCoupon($invoice, $coupon))
        ->toThrow(CouponNotRedeemableException::class);
    expect($invoice->fresh()->coupon_id)->toBeNull();
});

it('refuses an expired coupon', function () {
    $invoice = ($this->makeInvoice)();
    $coupon = ($this->makeCoupon)(['expires_at' => now()->subDay()]);

    expect(fn () => $this->service->applyCoupon($invoice, $coupon))
        ->toThrow(CouponNotRedeemableException::class);
});

it('refuses a coupon once its usage_limit is reached', function () {
    $coupon = ($this->makeCoupon)(['usage_limit' => 1]);

    $this->service->applyCoupon(($this->makeInvoice)(), $coupon);

    $secondInvoice = ($this->makeInvoice)();
    expect(fn () => $this->service->applyCoupon($secondInvoice, $coupon->fresh()))
        ->toThrow(CouponNotRedeemableException::class);
});

it('refuses to apply a second coupon once one is already applied to an invoice', function () {
    $invoice = ($this->makeInvoice)();
    $first = ($this->makeCoupon)();
    $second = ($this->makeCoupon)();

    $this->service->applyCoupon($invoice, $first);

    expect(fn () => $this->service->applyCoupon($invoice->fresh(), $second))
        ->toThrow(CouponNotRedeemableException::class);
});

it('refuses to apply a coupon to an invoice that is no longer pending', function () {
    $invoice = ($this->makeInvoice)();
    $invoice->update(['status' => 'paid']);
    $coupon = ($this->makeCoupon)();

    expect(fn () => $this->service->applyCoupon($invoice, $coupon))
        ->toThrow(CouponNotRedeemableException::class);
});

it('produces a single full-fee invoice when the batch has no instalment_plan', function () {
    $invoices = $this->service->invoicesFor(($this->makeEnrolment)(1000));

    expect($invoices)->toHaveCount(1)
        ->and($invoices->first()->description)->toBe('Full fee');
});

it('splits the price across instalments per the batchs plan, each due on its own offset', function () {
    $this->travelTo(Carbon\Carbon::parse('2026-08-11'));

    $enrolment = ($this->makeEnrolment)(1000, [
        'instalment_plan' => [
            ['percent' => 50, 'days_after_enrolment' => 0],
            ['percent' => 50, 'days_after_enrolment' => 30],
        ],
    ]);

    $invoices = $this->service->invoicesFor($enrolment)->values();

    expect($invoices)->toHaveCount(2)
        ->and((float) $invoices[0]->amount)->toBe(500.0)
        ->and($invoices[0]->description)->toBe('Instalment 1 of 2')
        ->and($invoices[0]->due_date->toDateString())->toBe('2026-08-11')
        ->and((float) $invoices[1]->amount)->toBe(500.0)
        ->and($invoices[1]->description)->toBe('Instalment 2 of 2')
        ->and($invoices[1]->due_date->toDateString())->toBe('2026-09-10');
});

it('gives each instalment its own GST calculated on its own share, not the full price', function () {
    $enrolment = ($this->makeEnrolment)(1000, [
        'instalment_plan' => [
            ['percent' => 50, 'days_after_enrolment' => 0],
            ['percent' => 50, 'days_after_enrolment' => 30],
        ],
    ]);

    $invoices = $this->service->invoicesFor($enrolment)->values();

    // 500 * 18% = 90 GST per instalment, not 180 (which would be the
    // full-price GST leaking into a half-price instalment).
    expect((float) $invoices[0]->gst_amount)->toBe(90.0)
        ->and((float) $invoices[0]->total_amount)->toBe(590.0)
        ->and((float) $invoices[1]->gst_amount)->toBe(90.0)
        ->and((float) $invoices[1]->total_amount)->toBe(590.0);
});

it('has the last instalment absorb the rounding remainder so the parts sum exactly to the price', function () {
    $enrolment = ($this->makeEnrolment)(100, [
        'instalment_plan' => [
            ['percent' => 33, 'days_after_enrolment' => 0],
            ['percent' => 33, 'days_after_enrolment' => 10],
            ['percent' => 34, 'days_after_enrolment' => 20],
        ],
    ]);

    $invoices = $this->service->invoicesFor($enrolment)->values();
    $sum = $invoices->sum(fn (FeeInvoice $invoice): float => (float) $invoice->amount);

    expect($sum)->toBe(100.0);
});

it('each instalment invoice gets its own sequential invoice number', function () {
    $enrolment = ($this->makeEnrolment)(1000, [
        'instalment_plan' => [
            ['percent' => 50, 'days_after_enrolment' => 0],
            ['percent' => 50, 'days_after_enrolment' => 30],
        ],
    ]);

    $invoices = $this->service->invoicesFor($enrolment)->values();

    expect($invoices->pluck('invoice_number')->unique())->toHaveCount(2)
        ->and($invoices[1]->sequence_number)->toBe($invoices[0]->sequence_number + 1);
});
