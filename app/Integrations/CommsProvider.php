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
}
