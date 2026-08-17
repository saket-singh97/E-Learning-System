<?php

use App\Domains\Assessments\Jobs\AutoSubmitExpiredQuizAttempts;
use App\Domains\Assignments\Jobs\CheckCourseCompletionAndIssueCertificates;
use App\Domains\Batches\Jobs\ExpirePendingEnrolments;
use App\Domains\Batches\Jobs\RecordVideoCoverageAttendance;
use App\Domains\Billing\Jobs\SendGraceSuspensionReminders;
use App\Domains\Billing\Jobs\SuspendOverdueTenantSubscriptions;
use App\Domains\Comms\Jobs\SendInactivityNudges;
use App\Domains\Fees\Jobs\FlagOverdueInvoices;
use App\Domains\Fees\Jobs\SendFeeReminders;
use App\Exceptions\AiSpendCapExceededException;
use App\Exceptions\BandwidthQuotaExceededException;
use App\Exceptions\BatchFullException;
use App\Exceptions\CannotImpersonateException;
use App\Exceptions\CouponNotRedeemableException;
use App\Exceptions\DeviceLimitExceededException;
use App\Exceptions\EnrolmentApprovalDeniedException;
use App\Exceptions\InvalidFileTypeException;
use App\Exceptions\InvalidWebhookSignatureException;
use App\Exceptions\MissingTenantContextException;
use App\Exceptions\PlanDowngradeBlockedException;
use App\Exceptions\PrivilegeEscalationException;
use App\Exceptions\QuizAttemptAlreadySubmittedException;
use App\Exceptions\QuizAttemptLimitExceededException;
use App\Exceptions\QuizNotAvailableException;
use App\Exceptions\SeatLimitExceededException;
use App\Exceptions\StorageLimitExceededException;
use App\Exceptions\TenantAlreadySubscribedException;
use App\Exceptions\TenantNotFoundException;
use App\Exceptions\TenantNotSubscribedException;
use App\Exceptions\TenantSuspendedException;
use App\Http\Middleware\EnsureDeviceNotRevoked;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ResolveImpersonation;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // All five jobs below dispatch onto the 'scheduled' queue (S9 B10),
        // not Horizon's 'default' — see config/horizon.php's own comment:
        // these are unattended background sweeps with no one waiting on
        // the result, deliberately lower-priority than 'default' (AI
        // ingestion, CSV export), which a real person is often watching
        // for. Schedule::job()'s own $queue argument is what makes this a
        // real *queued* dispatch at all — before S9 B10 none of these five
        // implemented ShouldQueue, so Schedule::job() ran them inline in
        // the scheduler's own process instead (see each job's own docblock).

        // First scheduler use in this codebase (B5) — runs with no
        // CurrentTenant bound, so it sweeps every tenant's pending_approval
        // rows in one daily pass (see ExpirePendingEnrolments's own note).
        $schedule->job(new ExpirePendingEnrolments, 'scheduled')->daily();

        // S5 B7 — same no-tenant-bound posture; scans every tenant's
        // VideoWatchEvent rows in one daily pass (see the job's own note).
        $schedule->job(new RecordVideoCoverageAttendance, 'scheduled')->daily();

        // S6 B7 — same unscoped-sweep shape as the two jobs above, but NOT
        // daily: those two have day-granularity domain semantics (an
        // approval window measured in days, an end-of-day attendance
        // roll-up); a quiz's time limit is minutes, and nothing else in
        // this app enforces it (QuizRunner's own submit path never checks
        // expires_at either — B5/B6 both deliberately left all expiry
        // enforcement to this one sweep). A daily cadence would leave an
        // attempt sitting "expired" but still answerable for up to 24
        // hours, which defeats I3's own point.
        $schedule->job(new AutoSubmitExpiredQuizAttempts, 'scheduled')->everyFiveMinutes();

        // S7 B6 — same unscoped-sweep posture as the jobs above. Order
        // matters: flip pending invoices past due_date to overdue BEFORE
        // sending reminders, so the T+3 "overdue" reminder query (which
        // matches on due_date alone, not status) sees a status that's
        // already consistent with what the email says.
        $schedule->job(new FlagOverdueInvoices, 'scheduled')->daily();
        $schedule->job(new SendFeeReminders, 'scheduled')->daily();

        // S9 B13 — same unscoped-sweep shape as the jobs above.
        $schedule->job(new SendInactivityNudges, 'scheduled')->daily();

        // S10 B11 — same unscoped-sweep shape as the jobs above; daily
        // cadence is a deliberate tradeoff (design doc §2.4 decision 6),
        // not a default left unconsidered — a certificate has no
        // real-time expectation the way a live class or a quiz timer does.
        $schedule->job(new CheckCourseCompletionAndIssueCertificates, 'scheduled')->daily();

        // S11 B8 — same unscoped-sweep shape as the jobs above; the one
        // grace -> suspended transition no single webhook event can
        // trigger on its own (design doc §2.4 decision 7).
        $schedule->job(new SuspendOverdueTenantSubscriptions, 'scheduled')->daily();

        // S11 B14 — same unscoped-sweep shape as the jobs above.
        $schedule->job(new SendGraceSuspensionReminders, 'scheduled')->daily();

        // S9 B10 — spaced past the four ->daily() sweeps above (which all
        // default to midnight) rather than piling everything onto the same
        // instant. backup:clean runs first so a stale/oversized prior
        // backup is pruned before, not after, the new one lands.
        $schedule->command('backup:clean')->dailyAt('01:00');
        $schedule->command('backup:run')->dailyAt('01:15');

        // S9 B14 review pass — docs/FEATURES.md's T2 row ("failed-job
        // retention") was never actually true: 0001_01_01_000002_create_jobs_table.php
        // provisions the failed_jobs table, but nothing ever pruned it —
        // real command is queue:prune-failed, not the queue:prune-failed-jobs
        // name a first guess would reach for (confirmed via --help, not
        // assumed). 720 hours (30 days) reuses this app's own already-
        // established backup-retention number (T3, config/backup.php)
        // rather than inventing an unrelated one.
        $schedule->command('queue:prune-failed --hours=720')->daily();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'resolve.tenant' => ResolveTenant::class,
            'role' => EnsureRole::class,
        ]);

        // Livewire registers /livewire/update with no domain constraint and
        // only the `web` group, so tenant-scoped routes lose CurrentTenant on
        // every Livewire action unless the group itself resolves it. Appended
        // (not prepended) so it runs after StartSession — the suspended-tenant
        // path flushes the session.
        $middleware->web(append: [
            ResolveTenant::class.':optional',
            ResolveImpersonation::class,
            EnsureDeviceNotRevoked::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (TenantNotFoundException $e) => abort(404));
        $exceptions->render(fn (TenantSuspendedException $e) => abort(403));
        $exceptions->render(fn (PrivilegeEscalationException $e) => abort(403));
        $exceptions->render(fn (EnrolmentApprovalDeniedException $e) => abort(403));
        $exceptions->render(fn (BatchFullException $e) => abort(409, $e->getMessage()));
        $exceptions->render(fn (QuizAttemptLimitExceededException $e) => abort(409, $e->getMessage()));
        $exceptions->render(fn (QuizNotAvailableException $e) => abort(404));
        $exceptions->render(fn (QuizAttemptAlreadySubmittedException $e) => abort(409, $e->getMessage()));
        $exceptions->render(fn (InvalidWebhookSignatureException $e) => abort(400, $e->getMessage()));
        $exceptions->render(fn (CouponNotRedeemableException $e) => abort(422, $e->getMessage()));
        $exceptions->render(fn (BandwidthQuotaExceededException $e) => abort(409, $e->getMessage()));
        $exceptions->render(fn (AiSpendCapExceededException $e) => abort(409, $e->getMessage()));
        $exceptions->render(fn (DeviceLimitExceededException $e) => abort(409, $e->getMessage()));
        $exceptions->render(fn (SeatLimitExceededException $e) => abort(409, $e->getMessage()));
        $exceptions->render(fn (StorageLimitExceededException $e) => abort(409, $e->getMessage()));
        $exceptions->render(fn (TenantAlreadySubscribedException $e) => abort(409, $e->getMessage()));
        $exceptions->render(fn (PlanDowngradeBlockedException $e) => abort(409, $e->getMessage()));
        $exceptions->render(fn (TenantNotSubscribedException $e) => abort(409, $e->getMessage()));

        // "Should never happen" — a programmer error (tenant-scoped code
        // reached with no tenant bound), not a client-recoverable state, so
        // render as a plain 500 rather than leaking the default handler's
        // behaviour. Registered like every other tenancy exception per
        // CLAUDE.md's one-render-per-class convention.
        $exceptions->render(fn (MissingTenantContextException $e) => abort(500, $e->getMessage()));

        $exceptions->render(fn (InvalidFileTypeException $e) => response()->json([
            'message' => $e->getMessage(),
            'errors' => [$e->field => [$e->getMessage()]],
        ], 422));

        $exceptions->render(fn (CannotImpersonateException $e) => abort(403, $e->getMessage()));
    })->create();
