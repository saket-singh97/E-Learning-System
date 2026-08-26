<?php

namespace App\Notifications;

use App\Domains\Batches\Models\BatchSession;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * M15's missed-class-replay nudge (S18 B6) — same student-facing,
 * dual-channel shape as InactivityNudgeDue; the WhatsApp half silently
 * no-ops for a student with no phone captured, same documented gap.
 * SendMissedClassReplayNudges owns the once-per-week dedupe; this class
 * only carries which session was missed.
 */
class MissedClassReplayNudgeDue extends Notification
{
    use Queueable;

    public function __construct(
        public readonly BatchSession $batchSession,
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
        return new WhatsAppMessage('missed_class_replay_nudge', [
            'session_title' => $this->batchSession->title,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'batch_session_id' => $this->batchSession->id,
            'lesson_id' => $this->batchSession->lesson_id,
            'session_title' => $this->batchSession->title,
        ];
    }
}
