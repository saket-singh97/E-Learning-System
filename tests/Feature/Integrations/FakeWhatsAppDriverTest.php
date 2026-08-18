<?php

use App\Integrations\CommsProvider;
use App\Integrations\Drivers\CircuitBreakerGuardedCommsDriver;
use App\Integrations\Drivers\FakeWhatsAppDriver;

/**
 * S12 B11 — the container binding now resolves the circuit-breaker
 * decorator wrapping FakeWhatsAppDriver, not the bare fake directly; see
 * AppServiceProvider's own binding.
 */
it('resolves CommsProvider from the container as the circuit-breaker-guarded driver', function () {
    expect(app(CommsProvider::class))->toBeInstanceOf(CircuitBreakerGuardedCommsDriver::class);
});

it('sends a template message and returns a message_id and status', function () {
    $driver = new FakeWhatsAppDriver;

    $result = $driver->sendTemplate('+919876543210', 'absence_alert', ['student_name' => 'Riya']);

    expect($result)->toHaveKeys(['message_id', 'status'])
        ->and($result['status'])->toBe('sent');
});

it('never repeats a message_id across calls, even for the same recipient/template', function () {
    $driver = new FakeWhatsAppDriver;

    $first = $driver->sendTemplate('+919876543210', 'absence_alert', ['student_name' => 'Riya']);
    $second = $driver->sendTemplate('+919876543210', 'absence_alert', ['student_name' => 'Riya']);

    expect($first['message_id'])->not->toBe($second['message_id']);
});
