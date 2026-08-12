<?php

namespace App\Notifications\Channels;

use App\Integrations\CommsProvider;
use Illuminate\Notifications\Notification;

class WhatsAppChannel
{
    public function __construct(protected CommsProvider $provider) {}

    /**
     * @return array{message_id: string, status: string}|null
     */
    public function send(object $notifiable, Notification $notification): ?array
    {
        $phone = $notifiable->phone ?? null;

        if (blank($phone)) {
            return null;
        }

        $message = $notification->toWhatsApp($notifiable);

        return $this->provider->sendTemplate($phone, $message->template, $message->params);
    }
}
