<?php

namespace App\Domains\Batches\Jobs;

use App\Domains\Batches\Models\BatchSession;
use App\Domains\Content\Models\AttendanceRecord;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * S16 B5 (H13 pt.1) — same unscoped-daily-sweep shape as
 * `CheckCourseCompletionAndIssueCertificates`/`SuspendOverdueTenantSubscriptions`:
 * `CurrentTenant` explicitly cleared up front, `BelongsToTenant`'s global
 * scope a genuine no-op across every tenant's rows.
 *
 * Detection only in this block — B6 turns each row `unmarkedSessions()`
 * finds into a `TeacherAttendanceFlag` (that table doesn't exist until
 * B6).
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

        $this->unmarkedSessions();
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
     * @return Collection<int, BatchSession>
     */
    public function unmarkedSessions(): Collection
    {
        return BatchSession::where('starts_at', '<', now())
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
