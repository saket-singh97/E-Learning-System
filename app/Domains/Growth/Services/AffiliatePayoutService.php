<?php

namespace App\Domains\Growth\Services;

use App\Domains\Growth\Models\AffiliateCode;
use App\Domains\Growth\Models\AffiliatePayoutRequest;
use App\Exceptions\AffiliatePayoutActionException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * S20 R2 — request/approve/reject, same shape B1's own
 * `RefundApprovalService` established: row-locked, status re-verified at
 * the point of decision. `AffiliatePayoutRequest` is scoped to one
 * `AffiliateCode` (design doc §3.2), not an owner-wide aggregate — an
 * owner of two codes requests a payout against each one separately.
 *
 * No "mark paid" action here — this block's own checklist names only
 * request/approve/reject; `paid` stays a defined status on the model for
 * whichever future block actually needs that third step (R3's own
 * instructor-payout flow already establishes that exact pattern).
 */
class AffiliatePayoutService
{
    /**
     * Confirmed commission across every referral this code has earned,
     * minus every payout request against it that isn't `rejected` — a
     * `requested`/`approved`/`paid` request reserves that money
     * permanently (or already spent it); a `rejected` one gives it back.
     */
    public function balanceFor(AffiliateCode $code): float
    {
        $confirmedCommission = (float) $code->referrals()->where('status', 'confirmed')->sum('commission_amount');
        $claimed = (float) $code->payoutRequests()->where('status', '!=', 'rejected')->sum('amount');

        return round($confirmedCommission - $claimed, 2);
    }

    public function requestPayout(AffiliateCode $code, float $amount): AffiliatePayoutRequest
    {
        if ($amount <= 0 || $amount > $this->balanceFor($code)) {
            throw AffiliatePayoutActionException::make('That amount exceeds the available balance.');
        }

        return AffiliatePayoutRequest::create([
            'affiliate_code_id' => $code->id,
            'amount' => $amount,
            'status' => 'requested',
            'requested_at' => now(),
        ]);
    }

    public function approve(AffiliatePayoutRequest $request, User $decider): AffiliatePayoutRequest
    {
        return DB::transaction(function () use ($request, $decider): AffiliatePayoutRequest {
            $locked = $this->lockRequested($request);

            $locked->update([
                'status' => 'approved',
                'decided_at' => now(),
                'decided_by' => $decider->id,
            ]);

            return $locked->fresh();
        });
    }

    public function reject(AffiliatePayoutRequest $request, User $decider): AffiliatePayoutRequest
    {
        return DB::transaction(function () use ($request, $decider): AffiliatePayoutRequest {
            $locked = $this->lockRequested($request);

            $locked->update([
                'status' => 'rejected',
                'decided_at' => now(),
                'decided_by' => $decider->id,
            ]);

            return $locked->fresh();
        });
    }

    protected function lockRequested(AffiliatePayoutRequest $request): AffiliatePayoutRequest
    {
        $locked = AffiliatePayoutRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== 'requested') {
            throw AffiliatePayoutActionException::make('This payout request has already been decided.');
        }

        return $locked;
    }
}
