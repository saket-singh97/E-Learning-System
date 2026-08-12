<?php

use App\Integrations\Drivers\AiSensyProvider;
use Illuminate\Support\Facades\Http;

it('refuses to send a template when the AiSensy API key is not configured', function () {
    config(['services.aisensy.api_key' => null]);

    $driver = new AiSensyProvider;

    expect(fn () => $driver->sendTemplate('+919876543210', 'absence_alert', ['student_name' => 'Riya']))
        ->toThrow(RuntimeException::class);
});

it('sends a template via the AiSensy campaign API and maps the response', function () {
    config(['services.aisensy.api_key' => 'test-api-key']);

    Http::fake([
        'backend.aisensy.com/*' => Http::response(['success' => true, 'submitted_message_id' => 'wamid.abc123'], 200),
    ]);

    $driver = new AiSensyProvider;
    $result = $driver->sendTemplate('+919876543210', 'absence_alert', ['student_name' => 'Riya']);

    expect($result)->toBe(['message_id' => 'wamid.abc123', 'status' => 'submitted']);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://backend.aisensy.com/campaign/t1/api/v2'
            && $request['apiKey'] === 'test-api-key'
            && $request['campaignName'] === 'absence_alert'
            && $request['destination'] === '+919876543210';
    });
});
