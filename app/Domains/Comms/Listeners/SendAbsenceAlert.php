<?php

namespace App\Domains\Comms\Listeners;

use App\Domains\Batches\Events\AttendanceRecorded;
use App\Domains\Comms\Models\ParentGuardianLink;
use App\Models\User;
use App\Notifications\AbsenceAlertDue;

/**
 * Same-day only, no batching/digest (design doc §3.2, matches the roadmap's
 * own demo script — "a parent's phone receiving an absence message" the
 * day it happens).
 *
 * Dedupe is scoped per (parent, student, day), not just (parent, type,
 * day) as the design doc's own §2.4 decision 5 wording literally reads
 * ("same notifiable, same type, same day") — that wording alone would
 * silently swallow a second child's absence alert on the same day for a
 * parent linked to more than one student, since it's the same notifiable +
 * type + day already sent for the first. Narrowed here to also match which
 * student the alert is about, read back from the stored notification's own
 * data payload — a deliberate refinement, not a deviation found by a test
 * failure.
 */
class SendAbsenceAlert
{
    public function handle(AttendanceRecorded $event): void
    {
        if ($event->status !== 'absent') {
            return;
        }

        $student = User::find($event->userId);

        if ($student === null) {
            return;
        }

        ParentGuardianLink::where('student_user_id', $student->id)
            ->with('parentUser')
            ->get()
            ->pluck('parentUser')
            ->filter()
            ->each(function (User $parent) use ($student): void {
                if ($this->alreadySentToday($parent, $student)) {
                    return;
                }

                $parent->notify(new AbsenceAlertDue($student));
            });
    }

    protected function alreadySentToday(User $parent, User $student): bool
    {
        return $parent->notifications()
            ->where('type', AbsenceAlertDue::class)
            ->whereDate('created_at', now()->toDateString())
            ->get()
            ->contains(fn ($notification) => (int) ($notification->data['student_user_id'] ?? 0) === $student->id);
    }
}
