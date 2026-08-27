<?php

namespace App\Domains\Growth\Services;

use App\Domains\Growth\Models\InstructorPayout;
use App\Exceptions\InstructorPayoutActionException;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * S20 R3 pt.1 — salaried basis only; `createRevenueShare()` (B11) sums the
 * instructor's own `FeePayment` rows for the period instead of taking a
 * flat admin-entered amount. Approve/reject/markPaid mirror
 * `RefundApprovalService`/`AffiliatePayoutService`'s exact row-locked
 * shape — same double-decision race this app has closed on every prior
 * approval flow.
 */
class InstructorPayoutService
{
    /**
     * A flat, admin-configured amount per period — no computation, the
     * amount given is the amount stored (design doc §4 decision 7: "basis:
     * salaried is a flat configured amount per period").
     */
    public function createSalaried(User $instructor, Carbon $periodStart, Carbon $periodEnd, float $amount): InstructorPayout
    {
        return InstructorPayout::create([
            'instructor_id' => $instructor->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'basis' => InstructorPayout::BASIS_SALARIED,
            'amount' => $amount,
            'status' => 'pending',
        ]);
    }

    public function approve(InstructorPayout $payout, User $decider): InstructorPayout
    {
        return DB::transaction(function () use ($payout, $decider): InstructorPayout {
            $locked = $this->lockInStatus($payout, 'pending');

            $locked->update([
                'status' => 'approved',
                'approved_by' => $decider->id,
                'approved_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    public function reject(InstructorPayout $payout): InstructorPayout
    {
        return DB::transaction(function () use ($payout): InstructorPayout {
            $locked = $this->lockInStatus($payout, 'pending');
            $locked->update(['status' => 'rejected']);

            return $locked->fresh();
        });
    }

    /**
     * Records that the transfer happened outside this app (bank transfer,
     * UPI, whatever the institute actually uses) — this app has no bank
     * integration to actually move money to a person, so it doesn't
     * pretend to (design doc §4 decision 7).
     */
    public function markPaid(InstructorPayout $payout): InstructorPayout
    {
        return DB::transaction(function () use ($payout): InstructorPayout {
            $locked = $this->lockInStatus($payout, 'approved');
            $locked->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    protected function lockInStatus(InstructorPayout $payout, string $expectedStatus): InstructorPayout
    {
        $locked = InstructorPayout::whereKey($payout->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== $expectedStatus) {
            throw InstructorPayoutActionException::make("This payout is not {$expectedStatus}.");
        }

        return $locked;
    }
}
