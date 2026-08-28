<?php

namespace App\Integrations\Drivers;

use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Integrations\PaymentProvider;
use App\Support\Resilience\CircuitBreaker;

/**
 * S12 B11 — decorates the bound PaymentProvider (Razorpay's Orders/Refunds
 * APIs), same "decorate the container binding" shape B10 already
 * established for Video/AiProvider. `createOrder()` and `refund()` (S20 B1)
 * are both wrapped — both are real outbound calls to the same vendor.
 * `verifyWebhookSignature()` stays unwrapped: it's a pure local HMAC
 * comparison, never an outbound call, so a `false` result is a genuinely
 * invalid signature, not a sign the provider is down; wrapping it would let
 * webhook replay noise falsely trip the breaker.
 */
class CircuitBreakerGuardedPaymentDriver implements PaymentProvider
{
    private const BREAKER_KEY = 'payment-provider';

    public function __construct(
        private readonly PaymentProvider $inner,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function createOrder(FeeInvoice $invoice, string $idempotencyKey, ?int $emiDurationMonths = null): array
    {
        return $this->breaker->call(self::BREAKER_KEY, fn (): array => $this->inner->createOrder($invoice, $idempotencyKey, $emiDurationMonths));
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        return $this->inner->verifyWebhookSignature($payload, $signature);
    }

    public function refund(FeePayment $payment, float $amount, string $idempotencyKey): array
    {
        return $this->breaker->call(self::BREAKER_KEY, fn (): array => $this->inner->refund($payment, $amount, $idempotencyKey));
    }
}
