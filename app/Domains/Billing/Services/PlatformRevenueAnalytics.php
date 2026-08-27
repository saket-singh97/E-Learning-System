<?php

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Fees\Models\FeePayment;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * S20 Q6 — Super-panel-only, computed at read time, no rollup table
 * (design doc §4 decision 5), same posture `TestSeriesRankingService`
 * (S16) and `LeaderboardService` (S19) already established.
 *
 * Every query here explicitly bypasses the tenant scope
 * (`withoutGlobalScope('tenant')`) rather than relying on the Super
 * panel's own CurrentTenant being unbound — `InstituteSubscriptions`
 * (S11 B12) already made this exact call after two earlier slices
 * (S9 B10, S10 B13) shipped cross-tenant reads that leaned on ambient
 * state and got caught later. Built in from this block's first draft.
 */
class PlatformRevenueAnalytics
{
    /**
     * Sum of every *active* TenantSubscription's own Plan.price_inr_per_month.
     * `Plan` pricing is platform-tier and INR-only by name and by design
     * (design doc §2.3) — no currency conversion applies here at all,
     * unlike the per-institute figures below.
     */
    public function mrr(): float
    {
        return (float) TenantSubscription::withoutGlobalScope('tenant')
            ->where('status', TenantSubscription::STATUS_ACTIVE)
            ->with('plan')
            ->get()
            ->sum(fn (TenantSubscription $subscription): float => (float) ($subscription->plan->price_inr_per_month ?? 0));
    }

    public function arr(): float
    {
        return $this->mrr() * 12;
    }

    /**
     * Grouped by (tenant, currency) — never summed across institutes into
     * one blended total, and never across two different currencies for the
     * same institute either (an institute that changes its own
     * `default_currency` mid-history gets one row per currency it has ever
     * actually billed in). This is the deliberate fix for the "currency
     * mixing" bug class B14's own checklist names as an expected finding —
     * built in now instead of found later.
     *
     * @return Collection<int, array{tenant_id: int, tenant_name: string, currency: string, amount: float}>
     */
    public function perInstituteRevenue(): Collection
    {
        $payments = FeePayment::withoutGlobalScope('tenant')
            ->where('status', 'captured')
            ->with('invoice:id,currency')
            ->get();

        // One batched lookup for every tenant name involved, not one
        // Tenant::find() per group — B14's own review pass caught this as
        // an N+1 that would show up in a slow-query audit once the
        // platform has many institutes.
        $tenantNames = Tenant::whereIn('id', $payments->pluck('tenant_id')->unique())
            ->pluck('name', 'id');

        return $payments
            ->groupBy(fn (FeePayment $payment): string => $payment->tenant_id.'|'.($payment->invoice->currency ?? 'INR'))
            ->map(function (Collection $payments) use ($tenantNames): array {
                $first = $payments->first();

                return [
                    'tenant_id' => $first->tenant_id,
                    'tenant_name' => $tenantNames->get($first->tenant_id, 'Unknown institute'),
                    'currency' => $first->invoice->currency ?? 'INR',
                    'amount' => (float) $payments->sum(fn (FeePayment $payment): float => (float) $payment->amount),
                ];
            })
            ->values();
    }

    /**
     * Reads `FeePayment.status` directly, not a separate metrics table.
     * Only `captured`/`failed` (settled outcomes) count — `created` is
     * still in flight and has no success/failure verdict yet, and
     * `refunded` reflects a later reversal, not the original attempt's own
     * outcome.
     *
     * @return array{captured: int, failed: int, success_rate: float|null}
     */
    public function paymentSuccessFailureRate(): array
    {
        // One query for both counts, no raw-SQL fragment of any kind
        // (this app's own security baseline bans that under app/Domains/),
        // rather than two separate count() queries — caught in B14's own
        // review pass.
        $counts = FeePayment::withoutGlobalScope('tenant')
            ->whereIn('status', ['captured', 'failed'])
            ->pluck('status')
            ->countBy();

        $captured = $counts->get('captured', 0);
        $failed = $counts->get('failed', 0);
        $total = $captured + $failed;

        return [
            'captured' => $captured,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round($captured / $total * 100, 2) : null,
        ];
    }
}
