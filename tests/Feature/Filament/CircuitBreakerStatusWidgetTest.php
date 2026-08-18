<?php

use App\Filament\Widgets\CircuitBreakerStatusWidget;
use App\Support\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;

beforeEach(function () {
    config(['resilience.circuit_breaker_failure_threshold' => 3]);
    config(['resilience.circuit_breaker_cooldown_seconds' => 60]);
});

afterEach(function () {
    Redis::del('circuit_breaker:payment-provider:failures');
    Redis::del('circuit_breaker:payment-provider:open');
});

it('shows every wired provider as closed when healthy', function () {
    Livewire::test(CircuitBreakerStatusWidget::class)
        ->assertSee('Video')
        ->assertSee('AI')
        ->assertSee('Payment')
        ->assertSee('Subscription')
        ->assertSee('WhatsApp')
        ->assertSee('Live class')
        ->assertSee('Closed')
        ->assertDontSee('Open');
});

it('shows a tripped provider as open', function () {
    $breaker = app(CircuitBreaker::class);
    foreach (range(1, 3) as $i) {
        try {
            $breaker->call('payment-provider', fn () => throw new RuntimeException('down'));
        } catch (RuntimeException) {
        }
    }

    Livewire::test(CircuitBreakerStatusWidget::class)
        ->assertSee('Open');
});
