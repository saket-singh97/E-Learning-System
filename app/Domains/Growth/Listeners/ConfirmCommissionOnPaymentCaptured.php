<?php

namespace App\Domains\Growth\Listeners;

use App\Domains\Fees\Events\PaymentCaptured;
use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Growth\Models\AffiliateReferral;
use App\Domains\Growth\Services\AffiliateAttributionService;

/**
 * Z2 (S22 B11) — Growth's own half of the same one-way-ignorance shape
 * FinalizeEnrolmentOnPaymentCaptured/DispatchPaymentCapturedWebhook
 * already use on this exact event. PaymentReconciler::capture() only
 * ever dispatches PaymentCaptured from its plain-enrolment branch
 * (confirmed by reading it) — a membership-linked invoice calls
 * activateFromPayment() directly and a program-linked one dispatches
 * ProgramInvoicePaid instead, so this listener never receives either and
 * needs no branch guard of its own.
 *
 * No `fee_payment_id` on the event itself, only `enrolmentId` (same shape
 * every PaymentCaptured listener gets) — resolved via the invoice's own
 * payments rather than assuming the referral's own `fee_payment_id` is
 * the one that just captured (a retried checkout can attach a second
 * FeePayment to the same invoice after an earlier one failed).
 */
class ConfirmCommissionOnPaymentCaptured
{
    public function handle(PaymentCaptured $event): void
    {
        $invoice = FeeInvoice::where('enrolment_id', $event->enrolmentId)->first();

        if ($invoice === null) {
            return;
        }

        $referral = AffiliateReferral::where('status', 'pending')
            ->whereIn('fee_payment_id', $invoice->payments()->pluck('id'))
            ->first();

        if ($referral === null) {
            return;
        }

        app(AffiliateAttributionService::class)->confirmCommission($referral);
    }
}
