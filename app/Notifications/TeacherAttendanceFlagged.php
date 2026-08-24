<?php

namespace App\Notifications;

use App\Domains\Batches\Models\TeacherAttendanceFlag;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * S16 B6 (H13 pt.2) — database-only, same posture MessageReportResource's
 * own moderation queue takes: an internal admin worklist item, not a
 * customer-facing alert, so no mail/WhatsApp channel like
 * GracePeriodReminder. Sent to every institute_admin for the tenant, same
 * "no dedicated recipient concept, institute_admin is the closest real
 * fit" reasoning GracePeriodReminder's own docblock already established.
 */
class TeacherAttendanceFlagged extends Notification
{
    use Queueable;

    public function __construct(
        public readonly TeacherAttendanceFlag $flag,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'teacher_attendance_flag_id' => $this->flag->id,
            'batch_session_id' => $this->flag->batch_session_id,
            'instructor_name' => $this->flag->instructor?->name,
        ];
    }
}
