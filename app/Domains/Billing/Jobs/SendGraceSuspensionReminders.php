<?php

namespace App\Domains\Billing\Jobs;

use App\Domains\Billing\Models\TenantSubscription;
use App\Models\User;
use App\Notifications\GracePeriodReminder;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * S11 B14 — the T-3-before-suspension half of the design doc's own
 * two-notice ask; the other half ('entered_grace') fires directly from
 * SubscriptionReconciler the moment grace actually begins, since that is
 * a real event, not something a daily elapsed-time check can express.
 * "3 days before the grace deadline" genuinely is an elapsed-time check,
 * so it's a scheduled sweep — same unscoped-daily shape as every other
 * job in this neighbourhood (FlagOverdueInvoices, SendFeeReminders).
 */
class SendGraceSuspensionReminders implements ShouldQueue
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
            ->whereDate('grace_ends_at', now()->addDays(3)->toDateString())
            ->get()
            ->each(function (TenantSubscription $subscription): void {
                if ($this->alreadySentToday($subscription)) {
                    return;
                }

                $admins = User::where('tenant_id', $subscription->tenant_id)
                    ->role('institute_admin')
                    ->get();

                if ($admins->isNotEmpty()) {
                    Notification::send($admins, new GracePeriodReminder($subscription, 'suspension_imminent'));
                }
            });
    }

    /**
     * Same database-notification-lookback dedupe shape SendFeeReminders
     * already established for a queue retry landing on the same logical
     * day twice — checked against the shared `notifications` table
     * directly rather than one user's own relation, since the notifiable
     * here is every institute_admin for the tenant, not a single user.
     */
    protected function alreadySentToday(TenantSubscription $subscription): bool
    {
        return DatabaseNotification::where('type', GracePeriodReminder::class)
            ->whereDate('created_at', now()->toDateString())
            ->get()
            ->contains(fn (DatabaseNotification $notification) => (int) ($notification->data['tenant_subscription_id'] ?? 0) === $subscription->id
                && ($notification->data['stage'] ?? null) === 'suspension_imminent');
    }
}
