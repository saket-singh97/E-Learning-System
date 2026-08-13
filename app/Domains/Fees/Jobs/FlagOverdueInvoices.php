<?php

namespace App\Domains\Fees\Jobs;

use App\Domains\Fees\Models\FeeInvoice;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scheduled via Schedule::job() (bootstrap/app.php withSchedule). Real
 * queue retry as of S9 B10 — see ExpirePendingEnrolments's own docblock
 * (Batches domain) for why $tries/backoff() were dead configuration on a
 * plain (non-ShouldQueue) class before this.
 *
 * A single bulk `UPDATE ... WHERE status = 'pending'` is naturally
 * race-safe on its own — the WHERE clause is re-evaluated atomically
 * against whatever status the row actually holds at write time, so a
 * payment captured in the same instant this sweep runs simply won't
 * match and won't get incorrectly flagged overdue. No per-row
 * fetch-lock-write dance needed the way seat/coupon mutations require,
 * because this job creates nothing and has no per-row side effect beyond
 * the one column it's already writing. Also inherently safe to retry: a
 * re-run's WHERE clause only ever matches rows still 'pending'.
 *
 * CurrentTenant explicitly cleared up front, same persistent-worker-leak
 * reasoning as ExpirePendingEnrolments — this sweep relies on
 * BelongsToTenant's global scope being a no-op across every tenant.
 */
class FlagOverdueInvoices implements ShouldQueue
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

        FeeInvoice::where('status', 'pending')
            ->where('due_date', '<', now()->toDateString())
            ->update(['status' => 'overdue']);
    }
}
