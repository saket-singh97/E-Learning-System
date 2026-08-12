<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * app/Notifications/, not app/Domains/Comms/Notifications/ — matches
 * FeeReminderDue's own precedent (S7/B0): CLAUDE.md's domain layout
 * template doesn't list Notifications/ as a standard per-domain subfolder.
 *
 * Same-day only, no batching/digest — SendAbsenceAlert (the listener that
 * creates one of these) is what enforces "same day" via its own dedupe
 * check against the notifications table; this class has no state of its
 * own beyond which student the alert is about.
 */
class AbsenceAlertDue extends Notification
{
    use Queueable;

    public function __construct(
        public readonly User $student,
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
        return new WhatsAppMessage('absence_alert', [
            'student_name' => $this->student->name,
            'date' => now()->toDateString(),
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
            'date' => now()->toDateString(),
        ];
    }
}
