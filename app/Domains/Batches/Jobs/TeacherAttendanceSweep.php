<?php

namespace App\Domains\Batches\Jobs;

use App\Domains\Batches\Models\BatchSession;
use App\Domains\Batches\Models\TeacherAttendanceFlag;
use App\Domains\Content\Models\AttendanceRecord;
use App\Models\User;
use App\Notifications\TeacherAttendanceFlagged;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * S16 B5/B6 (H13) — same unscoped-daily-sweep shape as
 * `CheckCourseCompletionAndIssueCertificates`/`SuspendOverdueTenantSubscriptions`:
 * `CurrentTenant` explicitly cleared up front, `BelongsToTenant`'s global
 * scope a genuine no-op across every tenant's rows.
 *
 * B6 (design doc §4 decision 10) — one `TeacherAttendanceFlag` per
 * detected gap, no automatic substitute matching. Once a session is
 * flagged it's never re-flagged on a later sweep, regardless of the
 * flag's own status — an admin resolving one doesn't retroactively
 * backfill an instructor attendance row for that same past session, so
 * without this check the sweep would re-find and re-flag the identical
 * gap every single day forever.
 *
 * Same `withoutEvents()` + explicit `tenant_id` shape
 * `RecordVideoCoverageAttendance` (S5 B7) already established for
 * creating a `BelongsToTenant` row from an unscoped sweep —
 * `BelongsToTenant`'s `creating()` hook reads `CurrentTenant::id()`
 * unconditionally and throws with nothing bound.
 */
class TeacherAttendanceSweep implements ShouldQueue
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

        $this->unmarkedSessions()->each(function (BatchSession $session): void {
            if (TeacherAttendanceFlag::where('batch_session_id', $session->id)->exists()) {
                return;
            }

            $batch = $session->batch;

            if ($batch === null || $batch->instructor_id === null) {
                return;
            }

            $flag = null;

            TeacherAttendanceFlag::withoutEvents(function () use ($session, $batch, &$flag): void {
                $flag = TeacherAttendanceFlag::create([
                    'tenant_id' => $session->tenant_id,
                    'batch_session_id' => $session->id,
                    'instructor_id' => $batch->instructor_id,
                    'flagged_at' => now(),
                    'status' => 'open',
                ]);
            });

            $admins = User::where('tenant_id', $session->tenant_id)->role('institute_admin')->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new TeacherAttendanceFlagged($flag));
            }
        });
    }

    /**
     * Every `BatchSession` whose own end time (`starts_at` +
     * `duration_minutes`) has passed with no `role = 'instructor'`
     * `AttendanceRecord` written for it. `starts_at` is filtered in SQL (a
     * session that hasn't started yet can't have ended); the exact
     * end-time comparison happens in PHP since it's a computed value, not
     * a stored column, and this app's security baseline forbids raw SQL
     * query methods in `app/Domains/`.
     *
     * B17 review pass — bounded to the last 30 days: an unbounded
     * `starts_at < now()` scan loads every tenant's entire session
     * history into memory on every daily run, and on first deploy would
     * flag/notify admins about years of pre-existing unmarked sessions
     * in one pass. 30 days comfortably covers any genuinely recent gap
     * without an unbounded, ever-growing daily cost.
     *
     * @return Collection<int, BatchSession>
     */
    public function unmarkedSessions(): Collection
    {
        return BatchSession::where('starts_at', '<', now())
            ->where('starts_at', '>=', now()->subDays(30))
            ->get()
            ->filter(function (BatchSession $session): bool {
                $endsAt = $session->starts_at->clone()->addMinutes($session->duration_minutes);

                if ($endsAt->isFuture()) {
                    return false;
                }

                return ! AttendanceRecord::where('attendable_type', BatchSession::class)
                    ->where('attendable_id', $session->id)
                    ->where('role', 'instructor')
                    ->exists();
            })
            ->values();
    }
}
