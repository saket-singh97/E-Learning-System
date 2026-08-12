<?php

namespace App\Notifications\Messages;

/**
 * The return type every toWhatsApp() method hands WhatsAppChannel — the
 * WhatsApp equivalent of Laravel's own MailMessage, sized to exactly what
 * CommsProvider::sendTemplate() needs and nothing else.
 */
class WhatsAppMessage
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly string $template,
        public readonly array $params = [],
    ) {}
}
