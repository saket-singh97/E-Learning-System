<?php

namespace App\Domains\Fees\Jobs;

use App\Domains\Fees\Models\FeeInvoice;
use App\Models\User;
use App\Notifications\FeeReminderDue;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Scheduled via Schedule::job() (like the other S9 B10-converted sweeps),
 * no CurrentTenant bound — sweeps every tenant's due invoices in one daily
 * pass. CurrentTenant is explicitly cleared at the top of handle(), same
 * reasoning as the other four sweeps converted in this block: this is now a
 * real queued job processed by a persistent Horizon worker, which can run
 * any job in any order in the same PHP process — a stale binding left by
 * whatever ran immediately before this one must not leak into this sweep's
 * own cross-tenant queries.
 *
 * Which stage fires is derived purely from `due_date - today` each run,
 * with no dedupe state keyed on that alone — that offset can only be true
 * on exactly one calendar day under normal once-daily scheduling. But a
 * *queued* job can still legitimately run twice for the same logical
 * schedule tick (a queue-level retry after a transient failure partway
 * through the invoice loop — the entire point of adding real retry in this
 * block), so alreadySentToday() guards per invoice+stage using the same
 * database-notification-lookback shape SendAbsenceAlert (S8 B3) already
 * established, rather than assuming "once daily" alone is enough now that
 * retry is real. Confirmed FeeReminderDue's 'database' channel (already
 * present since S7) carries a fee_invoice_id + stage payload to key on.
 */
class SendFeeReminders implements ShouldQueue
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

        $this->sendFor(now()->addDays(3)->toDateString(), 'upcoming');
        $this->sendFor(now()->toDateString(), 'due_today');
        $this->sendFor(now()->subDays(3)->toDateString(), 'overdue');
    }

    private function sendFor(string $dueDate, string $stage): void
    {
        FeeInvoice::whereIn('status', ['pending', 'overdue'])
            ->whereDate('due_date', $dueDate)
            ->with('user')
            ->each(function (FeeInvoice $invoice) use ($stage) {
                if ($this->alreadySentToday($invoice, $stage)) {
                    return;
                }

                Notification::send($invoice->user, new FeeReminderDue($invoice, $stage));
            });
    }

    protected function alreadySentToday(FeeInvoice $invoice, string $stage): bool
    {
        /** @var User $user */
        $user = $invoice->user;

        return $user->notifications()
            ->where('type', FeeReminderDue::class)
            ->whereDate('created_at', now()->toDateString())
            ->get()
            ->contains(fn ($notification) => (int) ($notification->data['fee_invoice_id'] ?? 0) === $invoice->id
                && ($notification->data['stage'] ?? null) === $stage);
    }
}
