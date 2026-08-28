<?php

use App\Domains\Api\Jobs\DeliverWebhook;
use App\Domains\Api\Models\WebhookDelivery;
use App\Domains\Api\Models\WebhookEndpoint;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Each direct ->handle() call here stands in for one real queue-worker
 * attempt (the worker is what actually waits out backoff() between
 * attempts — that loop belongs to Laravel's own queue engine, not
 * something these tests re-implement).
 */
function attemptDelivery(int $deliveryId, int $tenantId): void
{
    try {
        (new DeliverWebhook($deliveryId, $tenantId))->handle();
    } catch (RequestException) {
        // expected on a failing attempt — the job rethrows so a real
        // worker's $tries/backoff() takes over.
    }
}

it('retries up to 5 times against a permanently-down endpoint, then stays failed', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $endpoint = WebhookEndpoint::create(['url' => 'https://down.example.com/hook', 'secret' => 'x']);
    $delivery = WebhookDelivery::create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_type' => 'payment.captured',
        'payload' => ['enrolment_id' => 1],
        'status' => 'pending',
    ]);

    Http::fake(['down.example.com/*' => Http::response('', 500)]);

    for ($i = 0; $i < 5; $i++) {
        attemptDelivery($delivery->id, $tenant->id);
    }

    $delivery->refresh();
    expect($delivery->status)->toBe('failed')
        ->and($delivery->attempt_count)->toBe(5)
        ->and($delivery->delivered_at)->toBeNull();
});

it('marks delivered once a recovering endpoint succeeds on a later attempt', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $endpoint = WebhookEndpoint::create(['url' => 'https://flaky.example.com/hook', 'secret' => 'x']);
    $delivery = WebhookDelivery::create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_type' => 'payment.captured',
        'payload' => ['enrolment_id' => 1],
        'status' => 'pending',
    ]);

    // One Http::fake() registration, not two — Http::fake() stubs are
    // matched first-registered-wins (Factory::stubUrl() merges onto the
    // same collection, PendingRequest::buildStubHandler() takes ->first()
    // of the matches), so a second Http::fake() call for the same URL
    // pattern would never actually override the first. A response
    // sequence is the correct way to vary the response across successive
    // calls to the same URL within one test.
    Http::fake(['flaky.example.com/*' => Http::sequence()
        ->push('', 500)
        ->push(['ok' => true], 200),
    ]);

    attemptDelivery($delivery->id, $tenant->id);
    expect($delivery->refresh()->status)->toBe('failed');

    attemptDelivery($delivery->id, $tenant->id);

    $delivery->refresh();
    expect($delivery->status)->toBe('delivered')
        ->and($delivery->attempt_count)->toBe(2)
        ->and($delivery->delivered_at)->not->toBeNull();
});

it('keeps an accurate attempt history across retries', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $endpoint = WebhookEndpoint::create(['url' => 'https://down.example.com/hook', 'secret' => 'x']);
    $delivery = WebhookDelivery::create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_type' => 'payment.captured',
        'payload' => ['enrolment_id' => 1],
        'status' => 'pending',
    ]);

    Http::fake(['down.example.com/*' => Http::response('', 500)]);

    attemptDelivery($delivery->id, $tenant->id);
    expect($delivery->refresh()->attempt_count)->toBe(1);
    $firstAttemptAt = $delivery->last_attempted_at;

    attemptDelivery($delivery->id, $tenant->id);
    expect($delivery->refresh()->attempt_count)->toBe(2)
        ->and($delivery->last_attempted_at->gte($firstAttemptAt))->toBeTrue();
});

it('a job for an already-delivered row is a no-op', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $endpoint = WebhookEndpoint::create(['url' => 'https://ok.example.com/hook', 'secret' => 'x']);
    $delivery = WebhookDelivery::create([
        'webhook_endpoint_id' => $endpoint->id,
        'event_type' => 'payment.captured',
        'payload' => ['enrolment_id' => 1],
        'status' => 'delivered',
        'attempt_count' => 1,
        'delivered_at' => now(),
    ]);

    Http::fake();

    (new DeliverWebhook($delivery->id, $tenant->id))->handle();

    Http::assertNothingSent();
});
