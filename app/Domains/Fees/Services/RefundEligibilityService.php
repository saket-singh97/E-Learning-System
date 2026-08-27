<?php

namespace App\Domains\Fees\Services;

use App\Domains\Fees\Models\FeePayment;
use App\Domains\Tenancy\Models\Tenant;

/**
 * The only place that answers "can this payment still be refunded" — B1's
 * approval action re-checks this same method at the point of approval
 * (design doc §4 decision 1), it isn't just a request-time gate.
 */
class RefundEligibilityService
{
    public function isEligible(FeePayment $payment): bool
    {
        $paidAt = $payment->invoice?->paid_at;

        if ($paidAt === null) {
            return false;
        }

        $windowDays = Tenant::find($payment->tenant_id)?->refund_window_days;

        if ($windowDays === null) {
            return false;
        }

        return now()->lte($paidAt->copy()->addDays($windowDays));
    }
}
