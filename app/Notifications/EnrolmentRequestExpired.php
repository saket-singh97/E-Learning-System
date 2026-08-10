<?php

namespace App\Notifications;

use App\Domains\Batches\Models\Enrolment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EnrolmentRequestExpired extends Notification
{
    use Queueable;

    public function __construct(public readonly Enrolment $enrolment) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your enrolment request has expired')
            ->line("Your request to join \"{$this->enrolment->batch->title}\" was not actioned within 7 days and has expired.")
            ->line('You may submit a new request if seats are still open.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'enrolment_id' => $this->enrolment->id,
            'batch_id' => $this->enrolment->batch_id,
            'batch_title' => $this->enrolment->batch->title,
        ];
    }
}
