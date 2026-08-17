<?php

namespace App\Domains\Billing\Jobs;

use App\Domains\Billing\Models\TenantSubscription;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Design doc §2.4 decision 7 — the one grace -> suspended transition no
 * single webhook event can trigger (it depends on elapsed time with no
 * successful payment in between, not an event). Same unscoped-daily-sweep
 * shape as every other job in this file's own neighbourhood
 * (FlagOverdueInvoices, CheckCourseCompletionAndIssueCertificates):
 * CurrentTenant cleared up front, BelongsToTenant's global scope a no-op
 * across every tenant's rows.
 *
 * "A tenant with no active subscription at all (never subscribed, or
 * already cancelled) must skip cleanly" (B8's own plan note) needs no
 * special-case code — the WHERE clause below (status = grace AND
 * grace_ends_at < now()) simply never matches such a tenant, the same way
 * FlagOverdueInvoices's own WHERE is its whole guard.
 */
class SuspendOverdueTenantSubscriptions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        app(CurrentTenant::class)->clear();

        TenantSubscription::where('status', TenantSubscription::STATUS_GRACE)
            ->where('grace_ends_at', '<', now())
            ->get()
            ->each(fn (TenantSubscription $subscription) => $this->suspend($subscription));
    }

    /**
     * Re-checks under a row lock rather than trusting the collection
     * gathered above — a `payment.failed` retry can genuinely recover
     * mid-sweep (SubscriptionReconciler's own `subscription.charged`
     * handler clears grace_ends_at the moment a webhook lands), so acting
     * on a stale in-memory snapshot could suspend a tenant whose
     * subscription just resolved seconds before this row's turn came up.
     * Tenant.status is reused for suspension, not a second flag (decision
     * 8) — both writes happen together, inside the same lock, so the two
     * never disagree.
     */
    protected function suspend(TenantSubscription $subscription): void
    {
        DB::transaction(function () use ($subscription): void {
            $locked = TenantSubscription::withoutGlobalScope('tenant')
                ->lockForUpdate()
                ->find($subscription->id);

            if ($locked === null
                || $locked->status !== TenantSubscription::STATUS_GRACE
                || $locked->grace_ends_at === null
                || $locked->grace_ends_at->isFuture()
            ) {
                return;
            }

            $locked->update(['status' => TenantSubscription::STATUS_SUSPENDED]);

            Tenant::where('id', $locked->tenant_id)->update(['status' => Tenant::STATUS_SUSPENDED]);
        });
    }
}
