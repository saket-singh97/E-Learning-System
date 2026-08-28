<?php

namespace App\Domains\Api\Jobs;

use App\Domains\Api\Models\WebhookDelivery;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * S21 B5/B6 (T5 pt.2/3, design doc §4 decision 3) — an institute's own
 * endpoint being slow or down must never block the request that triggered
 * the webhook, so this always runs off the queue, never inline. 5 tries
 * (T5's own spec), same $tries/backoff() shape every other queued sweep in
 * this app already uses (FlagOverdueInvoices et al.) — rethrowing on
 * failure is what lets Laravel's own queue worker re-run handle() after
 * each backoff delay; this class only records what happened on each real
 * attempt, it doesn't loop or sleep itself.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $webhookDeliveryId,
        public readonly int $tenantId,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        app(CurrentTenant::class)->set(Tenant::findOrFail($this->tenantId));

        $delivery = WebhookDelivery::with('webhookEndpoint')->find($this->webhookDeliveryId);

        if ($delivery === null || $delivery->status === 'delivered') {
            return;
        }

        $endpoint = $delivery->webhookEndpoint;
        $body = json_encode($delivery->payload);
        $signature = hash_hmac('sha256', $body, $endpoint->secret);

        try {
            Http::withHeaders(['X-Webhook-Signature' => $signature])
                ->withBody($body, 'application/json')
                ->post($endpoint->url)
                ->throw();

            $delivery->update([
                'status' => 'delivered',
                'attempt_count' => $delivery->attempt_count + 1,
                'last_attempted_at' => now(),
                'delivered_at' => now(),
            ]);
        } catch (Throwable $e) {
            $delivery->update([
                'status' => 'failed',
                'attempt_count' => $delivery->attempt_count + 1,
                'last_attempted_at' => now(),
            ]);

            throw $e;
        }
    }
}
