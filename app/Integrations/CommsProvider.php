<?php

namespace App\Integrations;

interface CommsProvider
{
    /**
     * Send a pre-approved WhatsApp template message.
     *
     * @param  string  $toE164  Recipient phone number, E.164 format.
     * @param  string  $template  The vendor-approved template/campaign name.
     * @param  array<string, mixed>  $params  Template placeholder values, in the order the template expects.
     * @return array{message_id: string, status: string}
     */
    public function sendTemplate(string $toE164, string $template, array $params): array;

    /**
     * Send a freeform reply within an active WhatsApp session window (a
     * user-initiated conversation, no pre-approved template required —
     * S24 B1, WhatsApp doubt-bot replies).
     *
     * @param  string  $toE164  Recipient phone number, E.164 format.
     * @return array{message_id: string, status: string}
     */
    public function sendSessionMessage(string $toE164, string $text): array;
}
