<?php

namespace App\Domains\Assessments\Jobs;

use App\Domains\Assessments\Models\QuizAttempt;
use App\Domains\Assessments\Services\QuizAttemptService;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled via Schedule::job() (bootstrap/app.php withSchedule). Real
 * queue retry as of S9 B10 — see ExpirePendingEnrolments's own docblock
 * (Batches domain) for why $tries/backoff() were dead configuration on a
 * plain (non-ShouldQueue) class before this. Idempotent: the row-level
 * status='in_progress' re-check (below, under lock) already means
 * re-running this whole job is safe — confirmed before adding retry, not
 * assumed. CurrentTenant is explicitly cleared up front for the same
 * persistent-worker-leak reason ExpirePendingEnrolments documents — this
 * job's own top-level query already used withoutGlobalScope('tenant')
 * defensively (a lesson from this job's own S6 B7 build), but the clear()
 * keeps every job in this block consistent rather than relying on two
 * different defenses for the same underlying risk.
 *
 * Unlike ExpirePendingEnrolments (pure update, no wrinkle) this job's own
 * plan note assumed the same "pure update" shape and turned out to be wrong —
 * QuizAttemptService::submit() also *creates* QuizAnswer rows, and
 * BelongsToTenant's creating() hook reads CurrentTenant::id()
 * unconditionally, so calling submit() with nothing bound throws
 * MissingTenantContextException (caught by this job's own test, not
 * assumed away). submit() is also the real student-facing path and
 * shouldn't be special-cased with RecordVideoCoverageAttendance's
 * withoutEvents()+explicit-tenant-id sidestep — instead, CurrentTenant is
 * bound to each row's own tenant right before its own submit() call.
 *
 * "Grading whatever was answered" (design doc §3.3) is honest, not
 * generous: QuizRunner (B5/B6) never persists in-progress responses
 * anywhere the backend can read — a response only reaches the database at
 * the moment of an explicit submit() call. An attempt swept here therefore
 * grades as if every question were left blank, because as far as the
 * server is concerned, that's true; nothing exists to grade otherwise. A
 * student who answered everything but never clicked Submit loses that
 * work. This is a real, known gap in the current design, not a shortcut
 * taken in this job — closing it would mean giving QuizRunner some form of
 * incremental answer persistence (autosave), which is out of this block's
 * one-line scope and not built here.
 */
class AutoSubmitExpiredQuizAttempts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Shorter than the other four converted sweeps' 1/5/15-minute tiers —
     * this one is scheduled ->everyFiveMinutes(), not ->daily(). A 15-minute
     * final backoff would outlive the next scheduled run anyway, making
     * that retry attempt pointless; capped at 5 minutes to match.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 180, 300];
    }

    public function handle(): void
    {
        app(CurrentTenant::class)->clear();

        // Unscoped explicitly, not just left to whatever CurrentTenant
        // happens to be unbound at call time — the scheduler never binds
        // anything, but this makes that independent of caller state rather
        // than relying on it.
        QuizAttempt::withoutGlobalScope('tenant')
            ->where('status', 'in_progress')
            ->where('expires_at', '<=', now())
            ->get()
            ->each(function (QuizAttempt $attempt): void {
                // Re-check status under a row lock before writing — the
                // sweep's own SELECT above can race a student's own
                // in-flight submitAttempt() landing between it and this
                // row's write, same reasoning ExpirePendingEnrolments
                // documents for its own lock.
                DB::transaction(function () use ($attempt): void {
                    // Explicitly unscoped: binding CurrentTenant to the
                    // previous row's tenant (below) would otherwise leak
                    // into this re-fetch on the next iteration, silently
                    // tenant-scoping a lookup for a *different* tenant's row
                    // out of existence — caught by this job's own
                    // multi-tenant test, not assumed away.
                    $locked = QuizAttempt::withoutGlobalScope('tenant')
                        ->whereKey($attempt->id)
                        ->where('status', 'in_progress')
                        ->lockForUpdate()
                        ->first();

                    if ($locked === null) {
                        return;
                    }

                    app(CurrentTenant::class)->set(Tenant::findOrFail($locked->tenant_id));

                    app(QuizAttemptService::class)->submit($locked, [], 'auto_submitted');
                });
            });
    }
}
