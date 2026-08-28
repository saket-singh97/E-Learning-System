<?php

namespace App\Domains\Fees\Services;

use App\Domains\Batches\Models\Batch;
use App\Domains\Batches\Models\Enrolment;
use App\Domains\Content\Models\Program;
use App\Domains\Fees\Models\FeeCoupon;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Fees\Models\StudentMembershipPlan;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Exceptions\CouponNotRedeemableException;
use App\Integrations\PaymentProvider;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FeeInvoiceService
{
    public const GST_RATE = 18.00;

    /**
     * One invoice if the batch has no instalment_plan, N if it does — same
     * template-then-materialize shape S6 used for Quiz.blueprint -> drawn
     * Question rows (design doc §2.4 decision 4). Renamed from B1's
     * invoiceFor() (singular) now that this can return more than one, back
     * to the plural the plan's own B1 note originally sketched.
     *
     * @return Collection<int, FeeInvoice>
     */
    public function invoicesFor(Enrolment $enrolment): Collection
    {
        return DB::transaction(function () use ($enrolment) {
            $batch = $enrolment->batch;
            $tenant = app(CurrentTenant::class)->get();
            $totalAmount = (float) $batch->price;

            $lines = $this->instalmentLines($batch);
            $amounts = $this->splitAmounts($totalAmount, $lines);
            $lineCount = count($lines);

            $taxRate = $this->taxRateFor($tenant);

            return collect($lines)->values()->map(function (array $line, int $index) use (
                $enrolment, $batch, $tenant, $amounts, $lineCount, $taxRate
            ): FeeInvoice {
                $amount = $amounts[$index];
                $gstAmount = round($amount * $taxRate / 100, 2);
                $sequenceNumber = $this->nextSequenceNumber($tenant);

                return FeeInvoice::create([
                    'batch_id' => $batch->id,
                    'enrolment_id' => $enrolment->id,
                    'user_id' => $enrolment->user_id,
                    'sequence_number' => $sequenceNumber,
                    'invoice_number' => $this->invoiceNumber($tenant, $sequenceNumber),
                    'description' => $lineCount > 1 ? 'Instalment '.($index + 1).' of '.$lineCount : 'Full fee',
                    'amount' => $amount,
                    'gst_rate' => $taxRate,
                    'gst_amount' => $gstAmount,
                    'total_amount' => $amount + $gstAmount,
                    'discount_amount' => 0,
                    'currency' => $tenant->default_currency,
                    'due_date' => now()->addDays((int) ($line['days_after_enrolment'] ?? 0))->toDateString(),
                    'status' => 'pending',
                    'gstin_snapshot' => $tenant->gstin,
                ]);
            });
        });
    }

    /**
     * One invoice for the bundle's whole price, not one per bundled batch
     * (design doc §3.1/decision 4) — no instalment plan, no linked
     * enrolment (none exist yet; ProgramEnrolmentService creates them once
     * this invoice's payment is captured). Same sequencing/numbering this
     * class already owns for every other invoice, so a program purchase's
     * invoice number can never collide with or duplicate a batch invoice's.
     */
    public function invoiceForProgram(Program $program, User $user): FeeInvoice
    {
        return DB::transaction(function () use ($program, $user) {
            $tenant = app(CurrentTenant::class)->get();
            $amount = (float) $program->price;
            $taxRate = $this->taxRateFor($tenant);
            $gstAmount = round($amount * $taxRate / 100, 2);
            $sequenceNumber = $this->nextSequenceNumber($tenant);

            return FeeInvoice::create([
                'program_id' => $program->id,
                'user_id' => $user->id,
                'sequence_number' => $sequenceNumber,
                'invoice_number' => $this->invoiceNumber($tenant, $sequenceNumber),
                'description' => "Program: {$program->title}",
                'amount' => $amount,
                'gst_rate' => $taxRate,
                'gst_amount' => $gstAmount,
                'total_amount' => $amount + $gstAmount,
                'discount_amount' => 0,
                'currency' => $tenant->default_currency,
                'due_date' => now()->toDateString(),
                'status' => 'pending',
                'gstin_snapshot' => $tenant->gstin,
            ]);
        });
    }

    /**
     * S20 P9 (B4) — same "no batch/enrolment, routed by its own linkage
     * instead" shape invoiceForProgram() already established, this time for
     * a membership plan rather than a course bundle. `StudentSubscriptionService`
     * is what turns a paid one of these into an active `StudentSubscription`
     * (PaymentReconciler calls it directly — both live in Fees, no event
     * needed, unlike the Program/Content case which genuinely crosses a
     * domain boundary).
     */
    public function invoiceForMembership(StudentMembershipPlan $plan, User $user): FeeInvoice
    {
        return DB::transaction(function () use ($plan, $user) {
            $tenant = app(CurrentTenant::class)->get();
            $amount = (float) $plan->price;
            $taxRate = $this->taxRateFor($tenant);
            $gstAmount = round($amount * $taxRate / 100, 2);
            $sequenceNumber = $this->nextSequenceNumber($tenant);

            return FeeInvoice::create([
                'student_membership_plan_id' => $plan->id,
                'user_id' => $user->id,
                'sequence_number' => $sequenceNumber,
                'invoice_number' => $this->invoiceNumber($tenant, $sequenceNumber),
                'description' => "Membership: {$plan->name}",
                'amount' => $amount,
                'gst_rate' => $taxRate,
                'gst_amount' => $gstAmount,
                'total_amount' => $amount + $gstAmount,
                'discount_amount' => 0,
                'currency' => $tenant->default_currency,
                'due_date' => now()->toDateString(),
                'status' => 'pending',
                'gstin_snapshot' => $tenant->gstin,
            ]);
        });
    }

    /**
     * S20 P10 (design doc §4 decision 4) — generalizes the hardcoded
     * `GST_RATE` constant into a per-tenant configurable value.
     * `tenants.tax_rate` is nullable and falls back to the same 18%
     * default every tenant already had, so an institute that never
     * configures one sees byte-identical invoices to before this block.
     */
    protected function taxRateFor(Tenant $tenant): float
    {
        return $tenant->tax_rate ?? self::GST_RATE;
    }

    /**
     * @return array<int, array{percent?: int|float, days_after_enrolment?: int}>
     */
    protected function instalmentLines(Batch $batch): array
    {
        $plan = $batch->instalment_plan;

        return empty($plan) ? [['percent' => 100, 'days_after_enrolment' => 0]] : $plan;
    }

    /**
     * Splitting a price by percentage risks the parts not summing back to
     * the whole (e.g. three 33.33% lines on ₹100 leave 1 paise unaccounted
     * for) — the last line absorbs whatever's left over instead of its own
     * literal percentage, so the instalments always sum to exactly the
     * batch's price, never a paise short or over.
     *
     * @param  array<int, array{percent?: int|float}>  $lines
     * @return array<int, float>
     */
    protected function splitAmounts(float $total, array $lines): array
    {
        $amounts = [];
        $allocated = 0.0;
        $lastIndex = count($lines) - 1;

        foreach (array_values($lines) as $index => $line) {
            if ($index === $lastIndex) {
                $amounts[] = round($total - $allocated, 2);

                continue;
            }

            $amount = round($total * ((float) ($line['percent'] ?? 0)) / 100, 2);
            $amounts[] = $amount;
            $allocated += $amount;
        }

        return $amounts;
    }

    /**
     * Creates the vendor order + the FeePayment row that tracks it —
     * webhook capture (B2) finds this same row by provider_order_id and
     * flips it to 'captured' rather than creating a new one.
     *
     * Idempotency (S9 B8) lives here, at the application level, not in
     * anything Razorpay's own Orders API does for us: its `receipt` field
     * rejects a re-used value outright (400 "Duplicate request") rather
     * than degrading gracefully into "here's the order you already made",
     * so calling the provider twice for the same still-open attempt is
     * exactly what has to never happen. A student double-clicking "Pay
     * now", or a network retry re-submitting the same click, finds the
     * already-created row here and reuses it instead of reaching the
     * provider a second time. Locks the invoice row for the same reason
     * applyCoupon() already locks the coupon row: two concurrent calls
     * (two browser tabs) both reading "no existing row yet" before either
     * writes would otherwise both create one.
     */
    /**
     * $emiDurationMonths (S21 B8) — null (the default) is byte-identical to
     * every call site before this block; threaded straight through to the
     * provider's own order-creation call and stamped onto the created
     * FeePayment so PaymentReconciler/the invoice's own history can see an
     * EMI payment was requested without re-deriving it from the provider.
     */
    public function createPaymentOrder(FeeInvoice $invoice, ?int $emiDurationMonths = null): FeePayment
    {
        return DB::transaction(function () use ($invoice, $emiDurationMonths): FeePayment {
            FeeInvoice::whereKey($invoice->id)->lockForUpdate()->first();

            $existing = FeePayment::where('fee_invoice_id', $invoice->id)
                ->where('status', 'created')
                ->latest('id')
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            // Deterministic per invoice+attempt: stable across a genuine
            // retry of *this* call (nothing new has been persisted yet, so
            // re-entering this method after, say, a timed-out first
            // attempt computes the same number again), but distinct from
            // every earlier attempt on this same invoice (a previous
            // failed FeePayment row is still there, so the count moves on).
            $attemptNumber = FeePayment::where('fee_invoice_id', $invoice->id)->count() + 1;
            $idempotencyKey = "{$invoice->invoice_number}-{$attemptNumber}";

            $order = app(PaymentProvider::class)->createOrder($invoice, $idempotencyKey, $emiDurationMonths);

            return FeePayment::create([
                'fee_invoice_id' => $invoice->id,
                'provider' => 'razorpay',
                'provider_order_id' => $order['order_id'],
                'idempotency_key' => $idempotencyKey,
                'amount' => $order['amount'],
                'emi_duration_months' => $emiDurationMonths,
                'status' => 'created',
            ]);
        });
    }

    /**
     * Discount is applied to the pre-tax `amount` (the taxable value), not
     * the already-taxed `total_amount` — standard GST practice: a discount
     * reduces the base price, then GST is computed on what's left, not
     * applied as a straight cut off the tax-inclusive total. `amount`
     * itself is left untouched (the batch's real sticker price stays on
     * the record); only `discount_amount`/`gst_amount`/`total_amount`
     * change.
     *
     * Applies to *this one invoice*, not the whole instalment plan —
     * corrected from this method's own original B3 comment, which assumed
     * B4 would split an already-discounted total across instalments. B4
     * instead splits the batch's raw price at enrolment time, before any
     * coupon exists to apply (coupons are redeemed afterward, in
     * FeeCheckout, specifically so Batches' enrol() flow never has to know
     * coupon codes exist — B3's own decision). A coupon redeemed against a
     * multi-instalment invoice therefore discounts only the instalment
     * it's applied to, not the plan as a whole. Not revisited here: doing
     * otherwise would mean threading a coupon code through the
     * enrolment-time event chain B3 deliberately avoided coupling to Fees.
     */
    public function applyCoupon(FeeInvoice $invoice, FeeCoupon $coupon): FeeInvoice
    {
        return DB::transaction(function () use ($invoice, $coupon) {
            $lockedCoupon = FeeCoupon::whereKey($coupon->id)->lockForUpdate()->firstOrFail();

            $this->assertCouponRedeemable($invoice, $lockedCoupon);

            $baseAmount = (float) $invoice->amount;
            $discountAmount = $this->discountAmountFor($lockedCoupon, $baseAmount);
            $taxableAmount = $baseAmount - $discountAmount;
            $gstAmount = round($taxableAmount * (float) $invoice->gst_rate / 100, 2);

            $invoice->update([
                'coupon_id' => $lockedCoupon->id,
                'discount_amount' => $discountAmount,
                'gst_amount' => $gstAmount,
                'total_amount' => $taxableAmount + $gstAmount,
            ]);

            $lockedCoupon->increment('used_count');

            return $invoice->fresh();
        });
    }

    /**
     * `usage_limit`'s own race guard: lockForUpdate() on the coupon row
     * (applyCoupon()'s own transaction) so two students redeeming the last
     * remaining use concurrently can't both read used_count < usage_limit
     * and both succeed — same seat-race discipline
     * BatchEnrolmentService::enrolDirect() already established for seats.
     */
    protected function assertCouponRedeemable(FeeInvoice $invoice, FeeCoupon $coupon): void
    {
        if ($invoice->status !== 'pending') {
            throw CouponNotRedeemableException::make('This invoice is no longer open for a coupon.');
        }

        if ($invoice->coupon_id !== null) {
            throw CouponNotRedeemableException::make('A coupon has already been applied to this invoice.');
        }

        if ($coupon->status !== 'active') {
            throw CouponNotRedeemableException::make('This coupon is not active.');
        }

        if ($coupon->expires_at !== null && $coupon->expires_at->isPast()) {
            throw CouponNotRedeemableException::make('This coupon has expired.');
        }

        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            throw CouponNotRedeemableException::make('This coupon has reached its usage limit.');
        }
    }

    protected function discountAmountFor(FeeCoupon $coupon, float $baseAmount): float
    {
        return match ($coupon->discount_type) {
            'percentage' => round($baseAmount * (float) $coupon->discount_value / 100, 2),
            'fixed' => min((float) $coupon->discount_value, $baseAmount),
            default => 0.0,
        };
    }

    /**
     * Locks the tenant row rather than running `MAX(sequence_number) ...
     * FOR UPDATE` directly — Postgres refuses FOR UPDATE combined with an
     * aggregate function, so the lock has to sit on a real row (same shape
     * BatchEnrolmentService::enrolDirect() uses: lock the parent, then run
     * an ordinary read inside that lock).
     */
    protected function nextSequenceNumber(Tenant $tenant): int
    {
        Tenant::whereKey($tenant->id)->lockForUpdate()->first();

        return (int) FeeInvoice::where('tenant_id', $tenant->id)->max('sequence_number') + 1;
    }

    /**
     * INV-{tenant}-{financial year}-{sequence}. Indian FY runs April-March.
     */
    protected function invoiceNumber(Tenant $tenant, int $sequenceNumber): string
    {
        $year = now()->year;
        $fy = now()->month >= 4
            ? $year.'-'.substr((string) ($year + 1), -2)
            : ($year - 1).'-'.substr((string) $year, -2);

        return "INV-{$tenant->id}-{$fy}-{$sequenceNumber}";
    }
}
