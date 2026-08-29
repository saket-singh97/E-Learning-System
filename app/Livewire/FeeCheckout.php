<?php

namespace App\Livewire;

use App\Domains\Batches\Models\Enrolment;
use App\Domains\Fees\Models\FeeCoupon;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Services\FeeInvoiceService;
use App\Exceptions\CouponNotRedeemableException;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Embeds into the existing batch-catalog row (S4 B7) once an enrol()
 * attempt lands pending_payment. Reads FeeInvoice/FeePayment directly
 * (cross-domain model read, established precedent) rather than calling
 * into Fees' own service from a *different* domain's Livewire folder —
 * this component lives in the shared app/Livewire/ root specifically so it
 * isn't bound by the inter-domain rule the same way BatchCatalog is.
 */
class FeeCheckout extends Component
{
    public Enrolment $enrolment;

    public string $couponCode = '';

    /**
     * S21 B9 (V11) — null is a full payment, byte-identical to before this
     * block. Card EMI is separate from, and in addition to, the
     * institute's own instalment plan (design doc §2.1) — this option
     * exists regardless of whether the invoice is already one instalment
     * of several.
     */
    public ?int $emiDurationMonths = null;

    public function mount(Enrolment $enrolment): void
    {
        $this->enrolment = $enrolment;
    }

    /**
     * Coupon codes are matched case-insensitively — a plain equality
     * lookup would make "welcome10" and "WELCOME10" different codes for
     * no reason a student would expect.
     */
    public function applyCoupon(): void
    {
        $this->resetErrorBag();

        $invoice = $this->currentInvoice();

        if ($invoice === null) {
            return;
        }

        $coupon = FeeCoupon::where('code', strtoupper(trim($this->couponCode)))->first();

        if ($coupon === null) {
            $this->addError('couponCode', 'That coupon code was not found.');

            return;
        }

        try {
            app(FeeInvoiceService::class)->applyCoupon($invoice, $coupon);
        } catch (CouponNotRedeemableException $e) {
            $this->addError('couponCode', $e->getMessage());

            return;
        }

        $this->couponCode = '';
    }

    /**
     * Real capture (webhook verification, auto-enrolment) is B2 — this
     * only gets as far as "order exists, ready for the checkout widget,"
     * which is as much as B1's own scope covers.
     */
    public function startCheckout(): void
    {
        $invoice = $this->currentInvoice();

        if ($invoice === null) {
            return;
        }

        app(FeeInvoiceService::class)->createPaymentOrder($invoice, $this->emiDurationMonths);
    }

    public function render(): View
    {
        $invoice = $this->currentInvoice();

        return view('livewire.fee-checkout', [
            'invoice' => $invoice,
            'latestPayment' => $invoice?->payments()->latest('id')->first(),
            // Every invoice this enrolment has ever had, oldest first —
            // each one is individually viewable/printable via B5's new
            // route regardless of status, so a paid instalment's receipt
            // stays reachable after it's no longer the "current" one.
            'allInvoices' => FeeInvoice::where('enrolment_id', $this->enrolment->id)
                ->orderBy('due_date')
                ->orderBy('id')
                ->get(),
            'subdomain' => app(CurrentTenant::class)->get()->subdomain,
        ]);
    }

    /**
     * Ordered by due_date (earliest first), not latest('id') — with a
     * multi-instalment plan (B4) there can be several pending invoices at
     * once, created in instalment order, and "latest by id" would surface
     * the *last* instalment instead of the one actually due next. A single
     * full-fee invoice (no instalment_plan) is unaffected either way,
     * which is exactly why this didn't surface as a bug until B4 gave it a
     * second pending row to get wrong.
     */
    protected function currentInvoice(): ?FeeInvoice
    {
        return FeeInvoice::where('enrolment_id', $this->enrolment->id)
            ->where('status', 'pending')
            ->orderBy('due_date')
            ->orderBy('id')
            ->first();
    }
}
