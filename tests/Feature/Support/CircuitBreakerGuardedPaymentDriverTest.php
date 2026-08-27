<?php

use App\Domains\Fees\Models\FeeInvoice;
use App\Domains\Fees\Models\FeePayment;
use App\Exceptions\CircuitOpenException;
use App\Integrations\Drivers\CircuitBreakerGuardedPaymentDriver;
use App\Integrations\PaymentProvider;
use App\Support\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Redis;

function failingPaymentProvider(): PaymentProvider
{
    return new class implements PaymentProvider
    {
        public bool $webhookChecked = false;

        public function createOrder(FeeInvoice $invoice, string $idempotencyKey): array
        {
            throw new RuntimeException('provider down');
        }

        public function verifyWebhookSignature(string $payload, string $signature): bool
        {
            $this->webhookChecked = true;

            return false;
        }

        public function refund(FeePayment $payment, float $amount, string $idempotencyKey): array
        {
            throw new RuntimeException('provider down');
        }
    };
}

beforeEach(function () {
    config(['resilience.circuit_breaker_failure_threshold' => 3]);
    config(['resilience.circuit_breaker_cooldown_seconds' => 60]);
});

afterEach(function () {
    Redis::del('circuit_breaker:payment-provider:failures');
    Redis::del('circuit_breaker:payment-provider:open');
});

it('trips the payment-provider breaker after repeated createOrder failures', function () {
    $driver = new CircuitBreakerGuardedPaymentDriver(failingPaymentProvider(), app(CircuitBreaker::class));

    foreach (range(1, 3) as $i) {
        try {
            $driver->createOrder(new FeeInvoice, 'idem-key');
        } catch (RuntimeException) {
        }
    }

    expect(fn () => $driver->createOrder(new FeeInvoice, 'idem-key'))->toThrow(CircuitOpenException::class);
});

it('trips the shared payment-provider breaker after repeated refund failures too', function () {
    $driver = new CircuitBreakerGuardedPaymentDriver(failingPaymentProvider(), app(CircuitBreaker::class));

    foreach (range(1, 3) as $i) {
        try {
            $driver->refund(new FeePayment, 100.0, 'idem-key');
        } catch (RuntimeException) {
        }
    }

    expect(fn () => $driver->refund(new FeePayment, 100.0, 'idem-key'))->toThrow(CircuitOpenException::class);
});

it('never wraps verifyWebhookSignature — a local check, not an outbound call', function () {
    $inner = failingPaymentProvider();
    $driver = new CircuitBreakerGuardedPaymentDriver($inner, app(CircuitBreaker::class));

    expect($driver->verifyWebhookSignature('payload', 'sig'))->toBeFalse();
    expect($inner->webhookChecked)->toBeTrue();
    expect(app(CircuitBreaker::class)->isOpen('payment-provider'))->toBeFalse();
});
