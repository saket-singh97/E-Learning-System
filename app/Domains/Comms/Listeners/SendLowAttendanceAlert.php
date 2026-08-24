<?php

namespace App\Domains\Comms\Listeners;

use App\Domains\Batches\Events\AttendanceRecorded;
use App\Domains\Comms\Models\ParentGuardianLink;
use App\Domains\Comms\Services\StudentMetrics;
use App\Models\User;
use App\Notifications\LowAttendanceAlertDue;

/**
 * Reuses SendAbsenceAlert's exact plumbing (S8 B3) — same
 * AttendanceRecorded-adjacent trigger, same WhatsAppChannel/database
 * dual-channel notification, same dedupe-by-notifications-table pattern —
 * not a new notification mechanism (S9 design doc's own decision 7).
 *
 * "Fires once when a student's attendance/coverage first crosses the
 * threshold, not on every subsequent check while still below it" (S9 B13
 * plan note) — without a new schema column, there is no way to detect a
 * true state *transition* (was above, now below) from an event handler
 * alone; a once-per-week dedupe is this block's own judgment call for
 * approximating "not on every check" (the plan note itself offers
 * "student+day or student+week" as options, not a single prescribed key).
 * Weekly reads closer to "notified once, not spammed" than daily would,
 * while still periodically re-surfacing a persistently at-risk student —
 * same posture as InstituteOverviewWidget's own 60% threshold being an
 * undocumented judgment call nothing else in this app pins down.
 */
class SendLowAttendanceAlert
{
    public function handle(AttendanceRecorded $event): void
    {
        $student = User::find($event->userId);

        if ($student === null) {
            return;
        }

        $metrics = app(StudentMetrics::class);
        $summary = $metrics->summarize($student);

        if (! $metrics->isAtRisk($summary, $student)) {
            return;
        }

        ParentGuardianLink::where('student_user_id', $student->id)
            ->with('parentUser')
            ->get()
            ->pluck('parentUser')
            ->filter()
            ->each(function (User $parent) use ($student, $summary): void {
                if ($this->alreadySentThisWeek($parent, $student)) {
                    return;
                }

                $parent->notify(new LowAttendanceAlertDue(
                    $student,
                    $summary['attendance_percent'],
                    $summary['coverage_percent'],
                ));
            });
    }

    protected function alreadySentThisWeek(User $parent, User $student): bool
    {
        return $parent->notifications()
            ->where('type', LowAttendanceAlertDue::class)
            ->where('created_at', '>=', now()->startOfWeek())
            ->get()
            ->contains(fn ($notification) => (int) ($notification->data['student_user_id'] ?? 0) === $student->id);
    }
}
