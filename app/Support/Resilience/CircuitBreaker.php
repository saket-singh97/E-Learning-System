<?php

namespace App\Support\Resilience;

use App\Exceptions\CircuitOpenException;
use Illuminate\Support\Facades\Log;
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
            $this->recordFailure($key, $e);

            throw $e;
        }

        $this->recordSuccess($key);

        return $result;
    }

    public function isOpen(string $key): bool
    {
        return (bool) Redis::exists($this->openKey($key));
    }

    /**
     * `Log::critical()`, not a dedicated Sentry SDK call — `SENTRY_LARAVEL_DSN`
     * is a documented, not-yet-configured env var (`SETUP.md`, same V6/V7
     * "ops/deployment concern" boundary the design doc already draws
     * around installing the SDK itself). Sentry's own Laravel integration
     * auto-captures `critical`-level log entries once that DSN lands, with
     * zero extra app code — this is the real, permanent hook, not a stub
     * standing in for one.
     */
    protected function recordFailure(string $key, Throwable $e): void
    {
        $failures = Redis::incr($this->failuresKey($key));

        if ($failures >= $this->threshold()) {
            Redis::setex($this->openKey($key), $this->cooldownSeconds(), '1');

            Log::critical("Circuit breaker tripped for [{$key}]", [
                'provider' => $key,
                'failures' => $failures,
                'cooldown_seconds' => $this->cooldownSeconds(),
                'last_error' => $e->getMessage(),
            ]);
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
