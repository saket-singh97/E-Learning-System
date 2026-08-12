<?php

namespace App\Integrations\Drivers;

use App\Integrations\CommsProvider;

/**
 * Hash-seeded fake, same shape as FakeRazorpayDriver: deterministic per
 * call but never repeating a message_id, since a real WhatsApp BSP mints a
 * fresh one per send even for the same recipient/template (B3's dedupe
 * check reads the notifications table, not this id, so uniqueness here is
 * about not masking a bug that assumes idempotent ids rather than about
 * dedupe itself).
 */
class FakeWhatsAppDriver implements CommsProvider
{
    public function sendTemplate(string $toE164, string $template, array $params): array
    {
        return [
            'message_id' => 'wamid_fake_'.dechex(crc32("{$toE164}#{$template}#".uniqid('', true))),
            'status' => 'sent',
        ];
    }
}
