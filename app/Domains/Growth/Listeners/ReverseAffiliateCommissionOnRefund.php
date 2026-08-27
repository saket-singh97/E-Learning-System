<?php

namespace App\Domains\Growth\Listeners;

use App\Domains\Fees\Events\FeeRefundProcessed;
use App\Domains\Growth\Models\AffiliateReferral;

/**
 * Growth's half of the same one-way-ignorance shape
 * `EnrolFromProgramPayment` (Content) already established for
 * `ProgramInvoicePaid` — a real event listener, not a manual
 * reconciliation step an admin has to remember (design doc §4 decision 6).
 * A payment with no confirmed referral at all (never affiliated, or still
 * pending) matches zero rows and this is a safe no-op.
 */
class ReverseAffiliateCommissionOnRefund
{
    public function handle(FeeRefundProcessed $event): void
    {
        AffiliateReferral::where('fee_payment_id', $event->feePaymentId)
            ->where('status', 'confirmed')
            ->update(['status' => 'reversed']);
    }
}
