<?php

namespace App\Domains\Growth\Services;

use App\Domains\Batches\Models\Batch;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Growth\Models\InstructorPayout;
use App\Exceptions\InstructorPayoutActionException;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * S20 R3 — salaried (pt.1) and revenue-share (pt.2) creation, plus the
 * shared approve/reject/markPaid lifecycle both bases walk identically.
 * Approve/reject/markPaid mirror `RefundApprovalService`/
 * `AffiliatePayoutService`'s exact row-locked shape — same
 * double-decision race this app has closed on every prior approval flow.
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

    /**
     * Sums this instructor's own batches' captured `FeePayment` rows for
     * the period, times their configured share percentage (design doc §4
     * decision 7). Scoped by `webhook_verified_at` — the moment a payment
     * actually settled, same field `PaymentReconciler::capture()` itself
     * writes — not `created_at`, which is when the order was first opened
     * and could sit unpaid across a period boundary. A batch with no
     * captured payments in the period, or an instructor with no batches at
     * all, computes to a real, correct 0 rather than erroring.
     */
    public function createRevenueShare(User $instructor, Carbon $periodStart, Carbon $periodEnd, float $sharePercent): InstructorPayout
    {
        $batchIds = Batch::where('instructor_id', $instructor->id)->pluck('id');

        $revenue = (float) FeePayment::whereHas('invoice', fn ($query) => $query->whereIn('batch_id', $batchIds))
            ->where('status', 'captured')
            ->whereBetween('webhook_verified_at', [$periodStart->copy()->startOfDay(), $periodEnd->copy()->endOfDay()])
            ->sum('amount');

        return InstructorPayout::create([
            'instructor_id' => $instructor->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'basis' => InstructorPayout::BASIS_REVENUE_SHARE,
            'amount' => round($revenue * $sharePercent / 100, 2),
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
