<?php

namespace App\Integrations\Drivers;

use App\Integrations\CommsProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real driver, unit-testable without a live AiSensy account via
 * Http::fake() — same "real class exists, ready for AppServiceProvider to
 * bind once credentials land" posture as RazorpayProvider/
 * ZoomVideoSdkProvider. Request field names (apiKey, campaignName,
 * destination, userName, templateParams) match AiSensy's v2 Campaign API
 * (POST https://backend.aisensy.com/campaign/t1/api/v2, confirmed against
 * their published API reference at build time, not guessed) — a
 * "campaign" in AiSensy's dashboard is the pre-approved WhatsApp template
 * this interface calls $template.
 *
 * Not the bound CommsProvider yet: AppServiceProvider still binds
 * FakeWhatsAppDriver, since no real AISENSY_API_KEY exists.
 */
class AiSensyProvider implements CommsProvider
{
    protected const ENDPOINT = 'https://backend.aisensy.com/campaign/t1/api/v2';

    public function sendTemplate(string $toE164, string $template, array $params): array
    {
        $response = Http::post(self::ENDPOINT, [
            'apiKey' => $this->apiKey(),
            'campaignName' => $template,
            'destination' => $toE164,
            'userName' => (string) ($params['user_name'] ?? ''),
            'templateParams' => array_values(array_map('strval', $params)),
        ])->throw()->json();

        return [
            'message_id' => (string) ($response['submitted_message_id'] ?? $response['id'] ?? ''),
            'status' => ($response['success'] ?? false) ? 'submitted' : 'failed',
        ];
    }

    public function sendSessionMessage(string $toE164, string $text): array
    {
        $response = Http::post(self::ENDPOINT, [
            'apiKey' => $this->apiKey(),
            'destination' => $toE164,
            'message' => $text,
        ])->throw()->json();

        return [
            'message_id' => (string) ($response['submitted_message_id'] ?? $response['id'] ?? ''),
            'status' => ($response['success'] ?? false) ? 'submitted' : 'failed',
        ];
    }

    protected function apiKey(): string
    {
        $apiKey = config('services.aisensy.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException(
                'AiSensy API key is not configured (AISENSY_API_KEY) — '.
                'this is exactly why it is not yet the bound CommsProvider.'
            );
        }

        return $apiKey;
    }
}
