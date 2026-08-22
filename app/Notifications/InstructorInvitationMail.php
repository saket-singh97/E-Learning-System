<?php

namespace App\Notifications;

use App\Domains\Tenancy\Models\InstructorInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InstructorInvitationMail extends Notification
{
    use Queueable;

    public function __construct(public readonly InstructorInvitation $invitation) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url("/instructor-invitations/{$this->invitation->token}/accept");

        return (new MailMessage)
            ->subject('You have been invited to teach')
            ->line('You have been invited to join as an instructor.')
            ->action('Accept invitation', $url)
            ->line('This invitation expires on '.$this->invitation->expires_at->toDayDateTimeString().'.');
    }
}
