<?php

namespace App\Integrations\Drivers;

use App\Domains\Batches\Models\BatchSession;
use App\Integrations\LiveClassProvider;
use App\Models\User;
use App\Support\Resilience\CircuitBreaker;

/**
 * S12 B11 — decorates the bound LiveClassProvider, same shape as B10's
 * Video/AiProvider decorators. `markEnded()` is documented as "best-
 * effort," but that's about how tolerant its own caller is of failure,
 * not whether a real vendor-side failure there is a meaningless signal
 * — wrapped like every other outbound call on this interface.
 */
class CircuitBreakerGuardedLiveClassDriver implements LiveClassProvider
{
    private const BREAKER_KEY = 'live-class-provider';

    public function __construct(
        private readonly LiveClassProvider $inner,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function createRoom(BatchSession $session): string
    {
        return $this->breaker->call(self::BREAKER_KEY, fn (): string => $this->inner->createRoom($session));
    }

    public function joinUrl(BatchSession $session, User $viewer): string
    {
        return $this->breaker->call(self::BREAKER_KEY, fn (): string => $this->inner->joinUrl($session, $viewer));
    }

    public function markEnded(BatchSession $session): void
    {
        $this->breaker->call(self::BREAKER_KEY, function () use ($session): void {
            $this->inner->markEnded($session);
        });
    }
}
