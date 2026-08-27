<?php

namespace App\Domains\Fees\Services;

use App\Domains\Fees\Events\FeeRefundProcessed;
use App\Domains\Fees\Models\FeeRefund;
use App\Exceptions\RefundActionException;
use App\Integrations\PaymentProvider;
use Illuminate\Support\Facades\DB;

/**
 * The only place a `FeeRefund` request is decided — approval re-verifies
 * eligibility rather than trusting the state it was raised in (design doc
 * §4 decision 1): a tenant can shorten its own refund_window_days between
 * request and approval, or the window can simply lapse while the request
 * sits pending.
 */
class RefundApprovalService
{
    public function approve(FeeRefund $refund): FeeRefund
    {
        return DB::transaction(function () use ($refund): FeeRefund {
            $locked = $this->lockPending($refund);

            if (! app(RefundEligibilityService::class)->isEligible($locked->payment)) {
                throw RefundActionException::make('This payment is no longer within its refund window.');
            }

            $result = app(PaymentProvider::class)->refund(
                $locked->payment,
                (float) $locked->amount,
                "refund-{$locked->id}",
            );

            $locked->update([
                'status' => 'processed',
                'gateway_refund_id' => $result['refund_id'],
                'processed_at' => now(),
            ]);

            $locked->payment->update(['status' => 'refunded']);

            // S20 R1 pt.2 (design doc §4 decision 6) — a real event, not a
            // direct call into Growth's own AffiliateReferral: Fees has no
            // idea an affiliate program exists, same "domains talk through
            // events only" rule ProgramInvoicePaid already follows.
            FeeRefundProcessed::dispatch($locked->fee_payment_id, $locked->tenant_id);

            return $locked->fresh();
        });
    }

    public function reject(FeeRefund $refund): FeeRefund
    {
        return DB::transaction(function () use ($refund): FeeRefund {
            $locked = $this->lockPending($refund);
            $locked->update(['status' => 'rejected']);

            return $locked->fresh();
        });
    }

    /**
     * Locks the row before checking status — two concurrent approval
     * clicks on the same request (a double-click, or two admins) must not
     * both read 'pending' and both refund the same payment.
     */
    protected function lockPending(FeeRefund $refund): FeeRefund
    {
        $locked = FeeRefund::whereKey($refund->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== 'pending') {
            throw RefundActionException::make('This refund request has already been decided.');
        }

        return $locked;
    }
}
