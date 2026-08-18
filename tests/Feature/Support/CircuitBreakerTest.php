<?php

use App\Exceptions\CircuitOpenException;
use App\Support\Resilience\CircuitBreaker;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->key = 'test-'.uniqid();
    config(['resilience.circuit_breaker_failure_threshold' => 3]);
    config(['resilience.circuit_breaker_cooldown_seconds' => 60]);
});

afterEach(function () {
    Redis::del("circuit_breaker:{$this->key}:failures");
    Redis::del("circuit_breaker:{$this->key}:open");
});

it('returns the callbacks own result when closed', function () {
    $result = app(CircuitBreaker::class)->call($this->key, fn () => 'ok');

    expect($result)->toBe('ok');
});

it('lets a successful call through without tripping anything', function () {
    app(CircuitBreaker::class)->call($this->key, fn () => 'ok');

    expect(app(CircuitBreaker::class)->isOpen($this->key))->toBeFalse();
});

it('stays closed below the failure threshold', function () {
    $breaker = app(CircuitBreaker::class);

    foreach (range(1, 2) as $i) {
        try {
            $breaker->call($this->key, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected — the real provider exception still propagates
        }
    }

    expect($breaker->isOpen($this->key))->toBeFalse();
});

it('trips open after N consecutive failures, matching the configured threshold', function () {
    $breaker = app(CircuitBreaker::class);

    foreach (range(1, 3) as $i) {
        try {
            $breaker->call($this->key, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }
    }

    expect($breaker->isOpen($this->key))->toBeTrue();
});

it('fails fast once open, never invoking the callback', function () {
    $breaker = app(CircuitBreaker::class);

    foreach (range(1, 3) as $i) {
        try {
            $breaker->call($this->key, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }
    }

    $called = false;

    expect(fn () => $breaker->call($this->key, function () use (&$called) {
        $called = true;

        return 'unreachable';
    }))->toThrow(CircuitOpenException::class);

    expect($called)->toBeFalse();
});

it('a successful half-open probe fully closes the breaker again', function () {
    $breaker = app(CircuitBreaker::class);

    foreach (range(1, 3) as $i) {
        try {
            $breaker->call($this->key, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }
    }
    expect($breaker->isOpen($this->key))->toBeTrue();

    // Simulate the cooldown window having passed — Redis's own TTL
    // guarantee isn't what this test is verifying, only that a call
    // reaching the callback again (whenever `open` next expires) behaves
    // as a real probe.
    Redis::del("circuit_breaker:{$this->key}:open");

    $result = $breaker->call($this->key, fn () => 'recovered');

    expect($result)->toBe('recovered');
    expect($breaker->isOpen($this->key))->toBeFalse();
});

it('a failed half-open probe re-trips the breaker immediately, not after another N failures', function () {
    $breaker = app(CircuitBreaker::class);

    foreach (range(1, 3) as $i) {
        try {
            $breaker->call($this->key, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }
    }

    Redis::del("circuit_breaker:{$this->key}:open");
    expect($breaker->isOpen($this->key))->toBeFalse();

    try {
        $breaker->call($this->key, fn () => throw new RuntimeException('still down'));
    } catch (RuntimeException) {
    }

    expect($breaker->isOpen($this->key))->toBeTrue();
});

it('really re-opens after a real cooldown window elapses, end to end', function () {
    config(['resilience.circuit_breaker_cooldown_seconds' => 1]);
    $breaker = app(CircuitBreaker::class);

    foreach (range(1, 3) as $i) {
        try {
            $breaker->call($this->key, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }
    }
    expect($breaker->isOpen($this->key))->toBeTrue();

    sleep(2);

    expect($breaker->isOpen($this->key))->toBeFalse();
});

it('keeps two different provider keys fully independent', function () {
    $breaker = app(CircuitBreaker::class);
    $otherKey = 'test-'.uniqid();

    foreach (range(1, 3) as $i) {
        try {
            $breaker->call($this->key, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }
    }

    expect($breaker->isOpen($this->key))->toBeTrue();
    expect($breaker->isOpen($otherKey))->toBeFalse();

    Redis::del("circuit_breaker:{$otherKey}:failures");
    Redis::del("circuit_breaker:{$otherKey}:open");
});
