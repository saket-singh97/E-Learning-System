<?php

namespace App\Domains\Fees\Services;

use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Fees\Models\StudentMembershipPlan;
use App\Domains\Fees\Models\StudentSubscription;
use App\Models\User;
use Carbon\Carbon;

/**
 * S20 P9 pt.2 — the whole lifecycle of a student's own membership: trial
 * start (no charge), activation on first successful payment
 * (PaymentReconciler calls activateFromPayment() directly — both classes
 * live in Fees, so no cross-domain event the way Program/Content needs),
 * cancellation, and mid-cycle plan-change proration (design doc §4
 * decision 3).
 */
class StudentSubscriptionService
{
    public function startTrial(StudentMembershipPlan $plan, User $user): StudentSubscription
    {
        $trialEndsAt = now()->addDays($plan->trial_days);

        return StudentSubscription::create([
            'user_id' => $user->id,
            'student_membership_plan_id' => $plan->id,
            'status' => 'trialing',
            'trial_ends_at' => $trialEndsAt,
            'current_period_start' => now(),
            'current_period_end' => $trialEndsAt,
        ]);
    }

    /**
     * Immediate-payment path — no trial. Reuses `FeeInvoiceService`'s
     * already-generic `createPaymentOrder()` unchanged, same as
     * `invoiceForMembership()` needed nothing new from it either.
     */
    public function checkout(StudentMembershipPlan $plan, User $user): FeePayment
    {
        $invoice = app(FeeInvoiceService::class)->invoiceForMembership($plan, $user);

        return app(FeeInvoiceService::class)->createPaymentOrder($invoice);
    }

    /**
     * Finds the viewer's existing row for this plan (a trial converting to
     * paid) or creates a fresh one — either way it comes out `active` with
     * a new period starting now. Called only from a genuinely captured
     * payment (PaymentReconciler), never speculatively.
     */
    public function activateFromPayment(FeeInvoice $invoice): StudentSubscription
    {
        $plan = StudentMembershipPlan::findOrFail($invoice->student_membership_plan_id);

        $subscription = StudentSubscription::firstOrNew([
            'user_id' => $invoice->user_id,
            'student_membership_plan_id' => $plan->id,
        ]);

        $subscription->fill([
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => $this->nextPeriodEnd($plan, now()),
        ])->save();

        return $subscription;
    }

    public function cancel(StudentSubscription $subscription): StudentSubscription
    {
        $subscription->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return $subscription->fresh();
    }

    /**
     * Guarded on `status === 'active'` so a cancelled (or still-trialing,
     * or already past_due) subscription is never billed again — the only
     * thing standing between "cancel" and "doesn't actually stop renewing".
     */
    public function renew(StudentSubscription $subscription): ?FeePayment
    {
        if ($subscription->status !== 'active') {
            return null;
        }

        return $this->checkout($subscription->plan, $subscription->user);
    }

    /**
     * Standard mid-cycle proration: unused credit on the old plan's price
     * for the remaining days in the current period, offset against the new
     * plan's price for those same remaining days. Positive means "charge
     * this now", negative means "credit".
     */
    public function calculateProration(StudentSubscription $subscription, StudentMembershipPlan $newPlan, ?Carbon $now = null): float
    {
        $now = $now ?? now();
        $totalDays = $subscription->current_period_start->diffInDays($subscription->current_period_end);

        if ($totalDays <= 0) {
            return 0.0;
        }

        $daysRemaining = max(0, $now->diffInDays($subscription->current_period_end, false));
        $oldPlan = $subscription->plan;

        $unusedCredit = (float) $oldPlan->price * $daysRemaining / $totalDays;
        $newCharge = (float) $newPlan->price * $daysRemaining / $totalDays;

        return round($newCharge - $unusedCredit, 2);
    }

    /**
     * Applies the plan switch immediately (same period, new plan_id) —
     * charging or crediting the returned proration amount is a fast-follow
     * once a real institute needs it; this block computes it correctly and
     * swaps the plan, not yet a further invoice for the delta.
     *
     * @return array{subscription: StudentSubscription, proration_amount: float}
     */
    public function changePlan(StudentSubscription $subscription, StudentMembershipPlan $newPlan, ?Carbon $now = null): array
    {
        $prorationAmount = $this->calculateProration($subscription, $newPlan, $now);

        $subscription->update(['student_membership_plan_id' => $newPlan->id]);

        return [
            'subscription' => $subscription->fresh(),
            'proration_amount' => $prorationAmount,
        ];
    }

    protected function nextPeriodEnd(StudentMembershipPlan $plan, Carbon $from): Carbon
    {
        return $plan->billing_interval === 'yearly' ? $from->copy()->addYear() : $from->copy()->addMonth();
    }
}
