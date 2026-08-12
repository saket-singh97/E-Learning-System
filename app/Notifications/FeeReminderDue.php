<?php

namespace App\Notifications;

use App\Domains\Fees\Models\FeeInvoice;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * app/Notifications/, not app/Domains/Fees/Notifications/ — the design
 * doc's own file tree sketched the latter, but CLAUDE.md's domain layout
 * template doesn't list Notifications/ as a standard per-domain subfolder,
 * and the one precedent that already exists (EnrolmentRequestExpired, S4)
 * lives at this same shared root. Matched to the established convention
 * over the earlier speculative file tree, same as any other block-time
 * correction in this build.
 *
 * WhatsApp gap closed in S8 B4 — same notifiable as always (the enrolled
 * student, unchanged from S7), one more channel on the same notification;
 * B4's own plan note says so explicitly ("no change to the sweep"). Worth
 * knowing: nowhere in this app does a student ever get a `phone` set
 * (only `ParentGuardianResource`, S8 B1, captures one, for parents) — so
 * today this channel silently no-ops on every real send via
 * WhatsAppChannel's own blank-phone guard, not because anything here is
 * broken. FEATURES.md files O4 under its Parent/Guardian section, which
 * reads as this eventually reaching a linked parent rather than the
 * student — but the design doc's own data-flow (§3.2) has no
 * parent_student_links resolution step for this notification the way B3's
 * absence alert explicitly does, so re-routing it wasn't assumed here.
 * Flagged, not silently "fixed" by guessing a recipient change B4 was
 * never asked to make.
 */
class FeeReminderDue extends Notification
{
    use Queueable;

    /**
     * @param  'upcoming'|'due_today'|'overdue'  $stage
     */
    public function __construct(
        public readonly FeeInvoice $invoice,
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
        $amount = number_format((float) $this->invoice->total_amount, 2);
        $dueDate = $this->invoice->due_date->format('Y-m-d');

        $message = (new MailMessage)->subject($this->subject());

        return match ($this->stage) {
            'upcoming' => $message->line("Your payment of ₹{$amount} for \"{$this->invoice->description}\" is due on {$dueDate}."),
            'due_today' => $message->line("Your payment of ₹{$amount} for \"{$this->invoice->description}\" is due today."),
            'overdue' => $message->line("Your payment of ₹{$amount} for \"{$this->invoice->description}\" was due on {$dueDate} and is now overdue."),
        };
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        return new WhatsAppMessage('fee_reminder', [
            'stage' => $this->stage,
            'amount' => number_format((float) $this->invoice->total_amount, 2),
            'description' => $this->invoice->description,
            'due_date' => $this->invoice->due_date->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'fee_invoice_id' => $this->invoice->id,
            'stage' => $this->stage,
            'due_date' => $this->invoice->due_date->toDateString(),
        ];
    }

    protected function subject(): string
    {
        return match ($this->stage) {
            'upcoming' => 'Payment due soon',
            'due_today' => 'Payment due today',
            'overdue' => 'Payment overdue',
        };
    }
}
