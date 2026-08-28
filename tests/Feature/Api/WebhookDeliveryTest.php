<?php

use App\Domains\Api\Models\WebhookDelivery;
use App\Domains\Api\Models\WebhookEndpoint;
use App\Domains\Api\Services\WebhookDispatcher;
use App\Domains\Fees\Events\PaymentCaptured;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('signs the delivered payload with the endpoint\'s own secret', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $endpoint = WebhookEndpoint::create(['url' => 'https://institute.example.com/hook', 'secret' => 'topsecret']);

    Http::fake(['institute.example.com/*' => Http::response(['ok' => true], 200)]);

    app(WebhookDispatcher::class)->dispatch($tenant, 'payment.captured', ['enrolment_id' => 1]);

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        $expected = hash_hmac('sha256', $request->body(), 'topsecret');

        return $request->header('X-Webhook-Signature')[0] === $expected;
    });

    $delivery = WebhookDelivery::where('webhook_endpoint_id', $endpoint->id)->sole();
    expect($delivery->status)->toBe('delivered')
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->delivered_at)->not->toBeNull();
});

it('sends nothing to a disabled endpoint', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    WebhookEndpoint::create(['url' => 'https://disabled.example.com/hook', 'secret' => 'x', 'status' => 'disabled']);

    Http::fake();

    app(WebhookDispatcher::class)->dispatch($tenant, 'payment.captured', ['enrolment_id' => 1]);

    Http::assertNothingSent();
    expect(WebhookDelivery::count())->toBe(0);
});

it('creates a real webhook_deliveries row per active endpoint', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    WebhookEndpoint::create(['url' => 'https://a.example.com/hook', 'secret' => 'a']);
    WebhookEndpoint::create(['url' => 'https://b.example.com/hook', 'secret' => 'b']);

    Http::fake(fn () => Http::response(['ok' => true], 200));

    app(WebhookDispatcher::class)->dispatch($tenant, 'payment.captured', ['enrolment_id' => 42]);

    expect(WebhookDelivery::count())->toBe(2)
        ->and(WebhookDelivery::pluck('event_type')->unique()->all())->toBe(['payment.captured']);
});

it('marks a delivery failed on a bad response and rethrows for the queue to retry', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    WebhookEndpoint::create(['url' => 'https://down.example.com/hook', 'secret' => 'x']);

    Http::fake(['down.example.com/*' => Http::response('', 500)]);

    // QUEUE_CONNECTION=sync in tests runs the job inline with no retry loop
    // of its own (that's the real queue worker's job, not something this
    // test harness simulates) — the thrown exception is expected here and
    // is exactly what lets a real worker's backoff/retry take over.
    try {
        app(WebhookDispatcher::class)->dispatch($tenant, 'payment.captured', ['enrolment_id' => 1]);
    } catch (\Illuminate\Http\Client\RequestException) {
        // expected — see comment above
    }

    $delivery = WebhookDelivery::sole();
    expect($delivery->status)->toBe('failed')
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->delivered_at)->toBeNull();
});

it('dispatches a webhook when PaymentCaptured fires, via DispatchPaymentCapturedWebhook', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    WebhookEndpoint::create(['url' => 'https://institute.example.com/hook', 'secret' => 'x']);

    Http::fake(fn () => Http::response(['ok' => true], 200));

    PaymentCaptured::dispatch(enrolmentId: 7, tenantId: $tenant->id);

    $delivery = WebhookDelivery::sole();
    expect($delivery->event_type)->toBe('payment.captured')
        ->and($delivery->payload)->toBe(['enrolment_id' => 7])
        ->and($delivery->status)->toBe('delivered');
});
