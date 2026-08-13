<?php

namespace App\Notifications;

use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Messages\WhatsAppMessage;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * app/Notifications/, not app/Domains/Comms/Notifications/ — same
 * convention every other notification in this app already follows.
 *
 * Sent to the student directly (M15's own "3-day inactivity" framing is a
 * student-facing re-engagement nudge, not a parent alert — that's what
 * LowAttendanceAlertDue already covers). WhatsAppChannel is included for
 * shape-parity with every other dual-channel notification in this app,
 * but no student anywhere in this app has ever had a phone captured
 * (HANDOVER.md's own documented gap, ParentGuardianResource is the only
 * write site and only for parents) — the WhatsApp half silently no-ops
 * for every real send today, exactly like FeeReminderDue's own existing
 * student-facing WhatsApp channel already does. The database channel
 * still lands and surfaces via NotificationCentre regardless.
 */
class InactivityNudgeDue extends Notification
{
    use Queueable;

    public function __construct(
        public readonly ?CarbonInterface $lastActiveAt,
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
        return new WhatsAppMessage('inactivity_nudge', [
            'last_active_at' => $this->lastActiveAt?->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'last_active_at' => $this->lastActiveAt?->toDateString(),
        ];
    }
}
