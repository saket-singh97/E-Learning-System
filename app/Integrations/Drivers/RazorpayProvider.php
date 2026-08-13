<?php

namespace App\Integrations\Drivers;

use App\Domains\Fees\Models\FeeInvoice;
use App\Integrations\PaymentProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real driver, unit-testable without a live Razorpay account via
 * Http::fake() — the Orders API call and webhook HMAC verification are both
 * genuinely implemented, same "real class exists, ready for
 * AppServiceProvider to bind once credentials land" posture
 * ZoomVideoSdkProvider established in S5.
 *
 * Not the bound PaymentProvider yet: AppServiceProvider still binds
 * FakeRazorpayDriver, since no real RAZORPAY_KEY_ID/SECRET exist. Picking
 * Razorpay in a real checkout today would throw on missing credentials
 * rather than silently degrade — unlike Zoom, this driver isn't reachable
 * from any live call site until AppServiceProvider is repointed at it.
 */
class RazorpayProvider implements PaymentProvider
{
    public function createOrder(FeeInvoice $invoice, string $idempotencyKey): array
    {
        [$keyId, $keySecret] = $this->credentials();

        $response = Http::withBasicAuth($keyId, $keySecret)
            ->post('https://api.razorpay.com/v1/orders', [
                'amount' => (int) round($invoice->total_amount * 100), // paise
                'currency' => 'INR',
                // Razorpay's Orders API idempotency lives here, not in a
                // header (S9 B8 — verified against Razorpay's own API docs
                // at build time): a receipt already used on this account is
                // rejected with a 400 "Duplicate request", not handed back
                // the original order, so $idempotencyKey has to already be
                // unique per genuine new attempt by the time it gets here.
                'receipt' => $idempotencyKey,
                'notes' => [
                    'tenant_id' => (string) $invoice->tenant_id,
                    'fee_invoice_id' => (string) $invoice->id,
                ],
            ])
            ->throw()
            ->json();

        return [
            'order_id' => $response['id'],
            'amount' => (float) $invoice->total_amount,
            'currency' => 'INR',
        ];
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $webhookSecret = config('services.razorpay.webhook_secret');

        if (blank($webhookSecret)) {
            throw new RuntimeException(
                'Razorpay webhook secret is not configured (RAZORPAY_WEBHOOK_SECRET) — '.
                'this is exactly why it is not yet the bound PaymentProvider.'
            );
        }

        return hash_equals(hash_hmac('sha256', $payload, $webhookSecret), $signature);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function credentials(): array
    {
        $keyId = config('services.razorpay.key_id');
        $keySecret = config('services.razorpay.key_secret');

        if (blank($keyId) || blank($keySecret)) {
            throw new RuntimeException(
                'Razorpay credentials are not configured (RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET) — '.
                'this is exactly why it is not yet the bound PaymentProvider.'
            );
        }

        return [$keyId, $keySecret];
    }
}
