<?php

namespace App\Domains\Growth\Services;

use App\Domains\Fees\Models\FeePayment;
use App\Domains\Growth\Models\AffiliateCode;
use App\Domains\Growth\Models\AffiliateReferral;

/**
 * S20 R1 pt.1 — the single write chokepoint for "which affiliate code, if
 * any, was used for this payment" (same "single write chokepoint"
 * discipline `ForumPostComposer` (S19) already established for its own
 * shared write path). `commission_amount` starts at 0 and `status` at
 * `pending` — B8 computes the real amount and confirms it once the
 * payment this referral is attached to has actually settled.
 */
class AffiliateAttributionService
{
    public function attribute(FeePayment $payment, ?string $code): ?AffiliateReferral
    {
        if ($code === null || $code === '') {
            return null;
        }

        $affiliateCode = AffiliateCode::where('code', $code)
            ->where('status', 'active')
            ->first();

        if ($affiliateCode === null) {
            return null;
        }

        return AffiliateReferral::create([
            'affiliate_code_id' => $affiliateCode->id,
            'referred_user_id' => $payment->invoice->user_id,
            'fee_payment_id' => $payment->id,
            'commission_amount' => 0,
            'status' => 'pending',
        ]);
    }

    /**
     * S20 R1 pt.2 — computes the real commission for an already-attributed
     * referral, per its own `AffiliateCode`'s configured type, and confirms
     * it. A no-op on anything not still `pending` (already confirmed or
     * reversed), same idempotent-no-op discipline `PaymentReconciler::capture()`
     * already takes on an already-captured row — this is meant to run once
     * per referral, whenever its own payment settles.
     */
    public function confirmCommission(AffiliateReferral $referral): AffiliateReferral
    {
        if ($referral->status !== 'pending') {
            return $referral;
        }

        $affiliateCode = $referral->affiliateCode;
        $amount = (float) $referral->payment->amount;

        $commission = match ($affiliateCode->commission_type) {
            'percentage' => round($amount * (float) $affiliateCode->commission_value / 100, 2),
            'fixed' => (float) $affiliateCode->commission_value,
            default => 0.0,
        };

        $referral->update([
            'commission_amount' => $commission,
            'status' => 'confirmed',
        ]);

        return $referral->fresh();
    }
}
