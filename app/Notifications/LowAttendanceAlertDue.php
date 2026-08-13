<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * app/Notifications/, not app/Domains/Comms/Notifications/ — same
 * convention AbsenceAlertDue/FeeReminderDue already established.
 *
 * Once-per-week only — SendLowAttendanceAlert (the listener that creates
 * one of these) is what enforces that via its own dedupe check against
 * the notifications table; this class has no state of its own beyond
 * which student the alert is about and their current numbers.
 */
class LowAttendanceAlertDue extends Notification
{
    use Queueable;

    public function __construct(
        public readonly User $student,
        public readonly ?float $attendancePercent,
        public readonly ?float $coveragePercent,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [WhatsAppChannel::class, 'database'];
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        return new WhatsAppMessage('low_attendance_alert', [
            'student_name' => $this->student->name,
            'attendance_percent' => $this->attendancePercent,
            'coverage_percent' => $this->coveragePercent,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'student_user_id' => $this->student->id,
            'student_name' => $this->student->name,
            'attendance_percent' => $this->attendancePercent,
            'coverage_percent' => $this->coveragePercent,
        ];
    }
}
