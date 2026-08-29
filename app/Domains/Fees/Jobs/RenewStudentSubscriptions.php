<?php

namespace App\Domains\Fees\Jobs;

use App\Domains\Fees\Models\StudentSubscription;
use App\Domains\Fees\Services\StudentSubscriptionService;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Z1 (S22 B10) — StudentSubscriptionService::renew() (S20) was written but
 * never scheduled, so a subscription never actually renewed on its own.
 * Same unscoped-CurrentTenant sweep shape FlagOverdueInvoices/
 * SuspendOverdueTenantSubscriptions already use. Calls the *existing*
 * renew() unchanged — no new billing logic here.
 *
 * Advancing current_period_start/end after a successful renew() call is
 * the scheduling-layer half of "a renewal happened," not new billing
 * logic (renew()'s own checkout()/proration math is untouched) — and it's
 * what makes a re-run naturally idempotent, same "the WHERE clause won't
 * match a row that's no longer in that state" shape FlagOverdueInvoices'
 * own docblock describes, applied via a lock-and-recheck (SuspendOverdueTenantSubscriptions'
 * shape) since this is a multi-step per-row action, not one bulk UPDATE.
 */
class RenewStudentSubscriptions implements ShouldQueue
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

        StudentSubscription::where('status', 'active')
            ->where('current_period_end', '<', now())
            ->get()
            ->each(fn (StudentSubscription $subscription) => $this->renewOnce($subscription));
    }

    protected function renewOnce(StudentSubscription $subscription): void
    {
        DB::transaction(function () use ($subscription): void {
            $locked = StudentSubscription::withoutGlobalScope('tenant')
                ->lockForUpdate()
                ->find($subscription->id);

            if ($locked === null || $locked->status !== 'active' || $locked->current_period_end->isFuture()) {
                return;
            }

            // FeeInvoiceService::invoiceForMembership() (called via
            // renew() -> checkout()) resolves the billing tenant through
            // CurrentTenant, unlike CertificateService's own
            // Tenant::findOrFail()-from-the-row shape — this unscoped sweep
            // has to rebind it per row rather than change that contract.
            app(CurrentTenant::class)->set(Tenant::findOrFail($locked->tenant_id));

            app(StudentSubscriptionService::class)->renew($locked);

            $locked->update([
                'current_period_start' => $locked->current_period_end,
                'current_period_end' => app(StudentSubscriptionService::class)->nextPeriodEnd($locked->plan, $locked->current_period_end),
            ]);
        });
    }
}
