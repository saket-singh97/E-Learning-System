<?php

namespace App\Notifications;

use App\Domains\Analytics\Models\MonthlyReportCard;
use App\Models\User;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A5's monthly parent report card (S18 B10) — notifiable is the linked
 * parent (ParentGuardianLink), not the student, same recipient shift
 * SendAbsenceAlert already established for a parent-facing signal. Carries
 * a signed, time-limited download URL (design doc §4 decision 3) rather
 * than the PDF itself — CommsProvider::sendTemplate() has no media-
 * attachment method, so a link is the only thing that can travel over
 * WhatsApp today.
 */
class MonthlyReportCardReady extends Notification
{
    use Queueable;

    public function __construct(
        public readonly MonthlyReportCard $card,
        public readonly User $student,
        public readonly string $downloadUrl,
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
        return new WhatsAppMessage('monthly_report_card_ready', [
            'student_name' => $this->student->name,
            'period' => $this->card->period_month->format('F Y'),
            'download_url' => $this->downloadUrl,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'monthly_report_card_id' => $this->card->id,
            'student_user_id' => $this->student->id,
            'period_month' => $this->card->period_month->toDateString(),
        ];
    }
}
