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
}
