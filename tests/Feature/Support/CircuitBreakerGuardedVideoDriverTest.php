<?php

use App\Domains\Content\Models\Lesson;
use App\Exceptions\CircuitOpenException;
use App\Integrations\Drivers\CircuitBreakerGuardedVideoDriver;
use App\Integrations\VideoProvider;
use App\Support\Resilience\CircuitBreaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Redis;

function failingVideoProvider(): VideoProvider
{
    return new class implements VideoProvider
    {
        public function store(UploadedFile $file, Lesson $lesson): string
        {
            throw new RuntimeException('provider down');
        }

        public function url(Lesson $lesson): string
        {
            throw new RuntimeException('provider down');
        }

        public function delete(Lesson $lesson): void
        {
            throw new RuntimeException('provider down');
        }
    };
}

function succeedingVideoProvider(): VideoProvider
{
    return new class implements VideoProvider
    {
        public function store(UploadedFile $file, Lesson $lesson): string
        {
            return 'videos/1/1.mp4';
        }

        public function url(Lesson $lesson): string
        {
            return 'https://cdn.example/videos/1/1.mp4';
        }

        public function delete(Lesson $lesson): void {}
    };
}

beforeEach(function () {
    config(['resilience.circuit_breaker_failure_threshold' => 3]);
    config(['resilience.circuit_breaker_cooldown_seconds' => 60]);
});

afterEach(function () {
    Redis::del('circuit_breaker:video-provider:failures');
    Redis::del('circuit_breaker:video-provider:open');
});

it('passes a successful call straight through', function () {
    $driver = new CircuitBreakerGuardedVideoDriver(succeedingVideoProvider(), app(CircuitBreaker::class));

    expect($driver->url(new Lesson))->toBe('https://cdn.example/videos/1/1.mp4');
});

it('trips the shared video-provider breaker after repeated failures across different methods', function () {
    $driver = new CircuitBreakerGuardedVideoDriver(failingVideoProvider(), app(CircuitBreaker::class));

    foreach ([fn () => $driver->url(new Lesson), fn () => $driver->store(UploadedFile::fake()->create('x.mp4'), new Lesson), fn () => $driver->url(new Lesson)] as $call) {
        try {
            $call();
        } catch (RuntimeException) {
        }
    }

    expect(fn () => $driver->url(new Lesson))->toThrow(CircuitOpenException::class);
});
