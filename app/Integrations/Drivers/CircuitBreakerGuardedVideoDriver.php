<?php

namespace App\Integrations\Drivers;

use App\Domains\Content\Models\Lesson;
use App\Integrations\VideoProvider;
use App\Support\Resilience\CircuitBreaker;
use Illuminate\Http\UploadedFile;

/**
 * S12 B10 — decorates the bound VideoProvider ("Bunny" per the design
 * doc's own vendor-name shorthand; no real driver exists yet, only
 * `FakeVideoDriver` — see that class and `AppServiceProvider`'s own
 * binding), same "decorate the container binding, not each call site"
 * shape `SpendCapGuardedAiDriver` (S9 B12) already established. One
 * breaker key for the whole provider, not one per method — a real outage
 * fails every method together, and the point of a circuit breaker is
 * treating those failures as one signal, not three independent counters.
 */
class CircuitBreakerGuardedVideoDriver implements VideoProvider
{
    private const BREAKER_KEY = 'video-provider';

    public function __construct(
        private readonly VideoProvider $inner,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function store(UploadedFile $file, Lesson $lesson): string
    {
        return $this->breaker->call(self::BREAKER_KEY, fn (): string => $this->inner->store($file, $lesson));
    }

    public function url(Lesson $lesson): string
    {
        return $this->breaker->call(self::BREAKER_KEY, fn (): string => $this->inner->url($lesson));
    }

    public function delete(Lesson $lesson): void
    {
        $this->breaker->call(self::BREAKER_KEY, function () use ($lesson): void {
            $this->inner->delete($lesson);
        });
    }
}
