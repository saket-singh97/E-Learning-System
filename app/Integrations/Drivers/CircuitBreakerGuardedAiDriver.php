<?php

namespace App\Integrations\Drivers;

use App\Integrations\AiProvider;
use App\Integrations\ChatResult;
use App\Integrations\TranscriptResult;
use App\Support\Resilience\CircuitBreaker;

/**
 * S12 B10 — decorates the bound AiProvider ("Claude" per the design
 * doc's own vendor-name shorthand; no real driver exists yet, only
 * `FakeAiDriver` — see `AppServiceProvider`'s own binding). Sits *inside*
 * `SpendCapGuardedAiDriver` (S9 B12), not outside it: a spend-cap denial
 * is a business-rule pre-check, not a provider failure, so it must never
 * count toward tripping this breaker — only a real call that actually
 * reached (or tried to reach) the provider should.
 */
class CircuitBreakerGuardedAiDriver implements AiProvider
{
    private const BREAKER_KEY = 'ai-provider';

    public function __construct(
        private readonly AiProvider $inner,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function transcribe(string $diskPath): TranscriptResult
    {
        return $this->breaker->call(self::BREAKER_KEY, fn (): TranscriptResult => $this->inner->transcribe($diskPath));
    }

    public function chat(array $messages, ?array $responseSchema = null): ChatResult
    {
        return $this->breaker->call(self::BREAKER_KEY, fn (): ChatResult => $this->inner->chat($messages, $responseSchema));
    }

    public function embed(string $text): array
    {
        return $this->breaker->call(self::BREAKER_KEY, fn (): array => $this->inner->embed($text));
    }
}
