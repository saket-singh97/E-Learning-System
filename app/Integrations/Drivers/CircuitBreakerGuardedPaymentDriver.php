<?php

namespace App\Integrations\Drivers;

use App\Domains\Fees\Models\FeeInvoice;
use App\Integrations\PaymentProvider;
use App\Support\Resilience\CircuitBreaker;

/**
 * S12 B11 — decorates the bound PaymentProvider (Razorpay's Orders API),
 * same "decorate the container binding" shape B10 already established
 * for Video/AiProvider. Only `createOrder()` is wrapped —
 * `verifyWebhookSignature()` is a pure local HMAC comparison, never an
 * outbound call, so a `false` result is a genuinely invalid signature,
 * not a sign the provider is down; wrapping it would let webhook replay
 * noise falsely trip the breaker.
 */
class CircuitBreakerGuardedPaymentDriver implements PaymentProvider
{
    private const BREAKER_KEY = 'payment-provider';

    public function __construct(
        private readonly PaymentProvider $inner,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function createOrder(FeeInvoice $invoice, string $idempotencyKey): array
    {
        return $this->breaker->call(self::BREAKER_KEY, fn (): array => $this->inner->createOrder($invoice, $idempotencyKey));
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        return $this->inner->verifyWebhookSignature($payload, $signature);
    }
}
