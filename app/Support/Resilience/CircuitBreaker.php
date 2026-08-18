<?php

namespace App\Support\Resilience;

use App\Exceptions\CircuitOpenException;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Design doc §4 decision 10 — breaker state lives in Redis, not the
 * database: ephemeral, read on every provider call, needs near-zero
 * latency, and this app already uses Redis for cache/queue/session (no
 * new infrastructure). Two keys per breaker `$key`, not three — "open/
 * closed/half-open" (decision 10's own wording) is fully derived from
 * just `failures` (a plain `INCR` counter) and `open` (a TTL-backed
 * marker), not a separately-stored third state:
 *
 * - Closed: `open` key absent, `failures` below threshold. A success
 *   resets `failures` to 0.
 * - Open: `failures` reached the threshold, `open` was `SETEX`'d for the
 *   cooldown window. Every call short-circuits via `CircuitOpenException`
 *   without ever invoking the real callback ("fails fast, no Nth+1 real
 *   call attempted" — the testing strategy's own wording).
 * - Half-open: implicit, not a stored state — the moment `open`'s own TTL
 *   expires, `isOpen()` naturally returns false again, so the very next
 *   `call()` genuinely invokes the callback as a probe. `failures` is
 *   deliberately *not* reset when tripping open, so a single failed probe
 *   re-trips immediately (still >= threshold) rather than needing a fresh
 *   run of N failures; a successful probe resets both keys, fully closing
 *   the breaker.
 */
class CircuitBreaker
{
    public function call(string $key, callable $callback): mixed
    {
        if ($this->isOpen($key)) {
            throw CircuitOpenException::forProvider($key);
        }

        try {
            $result = $callback();
        } catch (Throwable $e) {
            $this->recordFailure($key);

            throw $e;
        }

        $this->recordSuccess($key);

        return $result;
    }

    public function isOpen(string $key): bool
    {
        return (bool) Redis::exists($this->openKey($key));
    }

    protected function recordFailure(string $key): void
    {
        $failures = Redis::incr($this->failuresKey($key));

        if ($failures >= $this->threshold()) {
            Redis::setex($this->openKey($key), $this->cooldownSeconds(), '1');
        }
    }

    protected function recordSuccess(string $key): void
    {
        Redis::del($this->failuresKey($key));
        Redis::del($this->openKey($key));
    }

    protected function threshold(): int
    {
        return (int) config('resilience.circuit_breaker_failure_threshold');
    }

    protected function cooldownSeconds(): int
    {
        return (int) config('resilience.circuit_breaker_cooldown_seconds');
    }

    protected function failuresKey(string $key): string
    {
        return "circuit_breaker:{$key}:failures";
    }

    protected function openKey(string $key): string
    {
        return "circuit_breaker:{$key}:open";
    }
}
