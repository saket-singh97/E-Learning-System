<?php

namespace App\Integrations\Drivers;

use App\Integrations\CommsProvider;
use App\Support\Resilience\CircuitBreaker;

/**
 * S12 B11 — decorates the bound CommsProvider (WhatsApp BSP), same shape
 * as B10's Video/AiProvider decorators.
 */
class CircuitBreakerGuardedCommsDriver implements CommsProvider
{
    private const BREAKER_KEY = 'comms-provider';

    public function __construct(
        private readonly CommsProvider $inner,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function sendTemplate(string $toE164, string $template, array $params): array
    {
        return $this->breaker->call(self::BREAKER_KEY, fn (): array => $this->inner->sendTemplate($toE164, $template, $params));
    }
}
