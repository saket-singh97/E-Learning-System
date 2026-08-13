<?php

namespace App\Domains\Batches\Jobs;

use App\Domains\Batches\Events\EnrolmentApprovalExpired;
use App\Domains\Batches\Models\Enrolment;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled via Schedule::job() (bootstrap/app.php withSchedule). Real
 * queue retry as of S9 B10 — previously a plain class Schedule::job() ran
 * synchronously in-process (Laravel's dispatcher only queues a job that
 * implements ShouldQueue; a plain class just gets its handle() called
 * inline), so $tries/backoff() would have been dead configuration before
 * this. Idempotent: the row-level status='pending_approval' re-check
 * (below, under lock) already means re-running this whole job is safe —
 * confirmed before adding retry, not assumed.
 *
 * CurrentTenant explicitly cleared up front: this sweep relies on
 * BelongsToTenant's global scope being a no-op, which only holds when
 * CurrentTenant is unbound. That was always true for a fresh scheduler
 * process; it is no longer guaranteed now that a persistent Horizon worker
 * can run any other tenant-bound job (RebindsTenant sets CurrentTenant and
 * never clears it) immediately before this one in the same PHP process.
 */
class ExpirePendingEnrolments implements ShouldQueue
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

        Enrolment::where('status', 'pending_approval')
            ->where('expires_at', '<=', now())
            ->get()
            ->each(function (Enrolment $enrolment) {
                // Re-check status under a row lock before writing — the
                // sweep's own SELECT above can race a concurrent approve()/
                // reject() landing between it and this row's write, and an
                // unconditional update() would clobber that decision back to
                // expired (found in the post-S4 review pass).
                DB::transaction(function () use ($enrolment) {
                    $locked = Enrolment::whereKey($enrolment->id)
                        ->where('status', 'pending_approval')
                        ->lockForUpdate()
                        ->first();

                    if ($locked === null) {
                        return;
                    }

                    $locked->update(['status' => 'expired']);

                    EnrolmentApprovalExpired::dispatch($locked->id, $locked->tenant_id);
                });
            });
    }
}
