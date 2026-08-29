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
 * Z1 (S22 B10, corrected S22 B13 review pass) — StudentSubscriptionService::renew()
 * (S20) was written but never scheduled, so a subscription never actually
 * renewed on its own. Same unscoped-CurrentTenant sweep shape
 * FlagOverdueInvoices/SuspendOverdueTenantSubscriptions already use. Calls
 * the *existing* renew() unchanged — no new billing logic here.
 *
 * B13's own 2-angle review pass caught a real billing bug in this block's
 * first version: it advanced current_period_start/end immediately after
 * renew(), but renew() -> checkout() only creates an *unpaid* order
 * (FeeInvoiceService::createPaymentOrder()) — never a captured payment.
 * That granted a full free period whether or not the student ever paid,
 * and once the period moved to the future the row silently dropped out of
 * this same sweep's own WHERE clause, so nothing would ever notice or
 * correct it. Fixed: this job only ever moves a row to `past_due` (already
 * declared in StudentSubscription::STATUSES, never assigned anywhere
 * before this) — that alone keeps a re-run from reprocessing it (the sweep
 * only selects `status = 'active'`), with no optimistic period math. The
 * period only actually advances through the *existing*
 * PaymentReconciler::capture() -> activateFromPayment() path once the
 * renewal invoice is genuinely captured — activateFromPayment()'s own
 * `firstOrNew(['user_id' => ..., 'student_membership_plan_id' => ...])`
 * already finds this exact row (same user+plan) and flips it back to
 * `active` with the new period, so no new wiring was needed for that half.
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

            $locked->update(['status' => 'past_due']);
        });
    }
}
