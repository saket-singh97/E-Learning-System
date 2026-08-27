<?php

namespace App\Integrations\Drivers;

use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Integrations\PaymentProvider;

/**
 * Hash-seeded fake, traceable to the invoice but never repeating an
 * order_id across calls — a real Razorpay order_id is always unique per
 * API call, even for the same invoice/amount, and B2's retry-on-failure
 * flow depends on that: PaymentReconciler correlates a webhook to exactly
 * one FeePayment by provider_order_id, so two attempts against the same
 * invoice sharing one order_id would let a webhook capture the wrong
 * attempt's row. B0's own first version of this method was deterministic
 * purely by invoice id + amount (no per-call uniqueness) — reproducible
 * for a single order, but broke the moment a real retry test exercised a
 * second order against the same invoice; fixed here rather than carried
 * forward once B2 actually needed two.
 */
class FakeRazorpayDriver implements PaymentProvider
{
    public const FAKE_SECRET = 'fake-razorpay-webhook-secret';

    public function createOrder(FeeInvoice $invoice, string $idempotencyKey): array
    {
        return [
            'order_id' => 'order_fake_'.dechex(crc32("invoice#{$invoice->id}#{$idempotencyKey}#".uniqid('', true))),
            'amount' => (float) $invoice->total_amount,
            'currency' => 'INR',
        ];
    }

    /**
     * Verifies against the fake's own synthetic secret rather than always
     * returning true — webhook-flow tests construct a real
     * payload+signature pair with FAKE_SECRET, so a test that forgets to
     * sign correctly still fails instead of silently passing.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        return hash_equals(hash_hmac('sha256', $payload, self::FAKE_SECRET), $signature);
    }

    public function refund(FeePayment $payment, float $amount, string $idempotencyKey): array
    {
        return [
            'refund_id' => 'rfnd_fake_'.dechex(crc32("payment#{$payment->id}#{$idempotencyKey}#".uniqid('', true))),
        ];
    }
}
