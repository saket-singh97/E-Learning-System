<?php

namespace App\Domains\Api\Services;

use App\Domains\Api\Jobs\DeliverWebhook;
use App\Domains\Api\Models\WebhookEndpoint;
use App\Domains\Tenancy\Models\Tenant;

class WebhookDispatcher
{
    /**
     * Creates a `webhook_deliveries` row per active endpoint and queues the
     * actual HTTP send (design doc §4 decision 3) — a disabled endpoint
     * gets no row and no job at all, not a row that's skipped later.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(Tenant $tenant, string $eventType, array $payload): void
    {
        WebhookEndpoint::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->each(function (WebhookEndpoint $endpoint) use ($tenant, $eventType, $payload): void {
                $delivery = $endpoint->deliveries()->create([
                    'tenant_id' => $tenant->id,
                    'event_type' => $eventType,
                    'payload' => $payload,
                    'status' => 'pending',
                ]);

                DeliverWebhook::dispatch($delivery->id, $tenant->id);
            });
    }
}
