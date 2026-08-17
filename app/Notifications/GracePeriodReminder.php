<?php

namespace App\Notifications;

use App\Domains\Billing\Models\TenantSubscription;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * S11 B14 — same "one notification class, staged via a param" shape
 * FeeReminderDue (Fees P7) already established, mirrored here rather than
 * two near-identical classes. Sent to every institute_admin for the
 * tenant, not a dedicated "billing contact" — no such field/concept
 * exists anywhere in this app (Tenant has no billing-contact column), and
 * institute_admin is the only role this codebase already treats as
 * "manages this institute's account." Same posture FeeReminderDue's own
 * docblock takes toward an ambiguous recipient: resolved to the closest
 * real fit already in this codebase, not a guessed new concept.
 */
class GracePeriodReminder extends Notification
{
    use Queueable;

    /**
     * @param  'entered_grace'|'suspension_imminent'  $stage
     */
    public function __construct(
        public readonly TenantSubscription $subscription,
        public readonly string $stage,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database', WhatsAppChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->subject());
        $deadline = $this->subscription->grace_ends_at?->toFormattedDateString();

        return match ($this->stage) {
            'entered_grace' => $message->line(
                "A recent payment for your institute's subscription failed. You have until {$deadline} to update your billing details before access is suspended."
            ),
            'suspension_imminent' => $message->line(
                "Your institute's access will be suspended on {$deadline} unless the outstanding payment is resolved before then."
            ),
        };
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        return new WhatsAppMessage('billing_grace_period', [
            'stage' => $this->stage,
            'grace_ends_at' => $this->subscription->grace_ends_at?->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tenant_subscription_id' => $this->subscription->id,
            'stage' => $this->stage,
            'grace_ends_at' => $this->subscription->grace_ends_at?->toDateString(),
        ];
    }

    protected function subject(): string
    {
        return match ($this->stage) {
            'entered_grace' => 'Payment failed — action needed',
            'suspension_imminent' => 'Your institute will be suspended soon',
        };
    }
}
