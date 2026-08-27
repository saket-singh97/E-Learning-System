<?php

namespace App\Integrations;

use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;

interface PaymentProvider
{
    /**
     * Create the vendor-side order for this invoice's total_amount. The
     * returned order_id is what the client-side checkout widget opens
     * against, and what the webhook later correlates back to this invoice.
     *
     * $idempotencyKey is deterministic per invoice+attempt (S9 B8) —
     * Razorpay's own Orders API has no idempotency *header* (that only
     * exists on its Payouts/Refunds APIs, confirmed against Razorpay's own
     * docs, not assumed from this deferred feature's own original naming);
     * its `receipt` field is what plays that role, and a receipt reused
     * across two calls is rejected outright rather than handed back the
     * original order — so FeeInvoiceService's own already-created-row
     * guard is what actually prevents a duplicate order, this key just
     * needs to be reproducible enough that a genuine retry of the *same*
     * attempt (not a fresh one) never varies.
     *
     * @return array{order_id: string, amount: float, currency: string}
     */
    public function createOrder(FeeInvoice $invoice, string $idempotencyKey): array;

    /**
     * Verify a webhook payload was genuinely signed by the vendor, using
     * the configured webhook secret — never trust an unverified payload
     * enough to mark an invoice paid.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool;

    /**
     * Refunds part or all of an already-captured payment, against its own
     * gateway payment id (never the order id — Razorpay's Refunds API is
     * scoped to a payment, not an order). $idempotencyKey is sent as
     * Razorpay's own `X-Refund-Idempotency` header (confirmed against
     * Razorpay's Refunds API docs, not assumed) so a retried approval
     * click can never double-refund the same request.
     *
     * @return array{refund_id: string}
     */
    public function refund(FeePayment $payment, float $amount, string $idempotencyKey): array;
}
