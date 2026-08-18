<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\AiSpendCapExceededException;
use App\Exceptions\CircuitOpenException;
use App\Integrations\AiProvider;
use App\Integrations\ChatResult;
use App\Integrations\Drivers\CircuitBreakerGuardedAiDriver;
use App\Integrations\Drivers\SpendCapGuardedAiDriver;
use App\Integrations\TranscriptResult;
use App\Support\Resilience\CircuitBreaker;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

function failingAiProvider(): AiProvider
{
    return new class implements AiProvider
    {
        public function transcribe(string $diskPath): TranscriptResult
        {
            throw new RuntimeException('provider down');
        }

        public function chat(array $messages, ?array $responseSchema = null): ChatResult
        {
            throw new RuntimeException('provider down');
        }

        public function embed(string $text): array
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
    Redis::del('circuit_breaker:ai-provider:failures');
    Redis::del('circuit_breaker:ai-provider:open');
});

it('trips the shared ai-provider breaker across chat/embed/transcribe alike', function () {
    $driver = new CircuitBreakerGuardedAiDriver(failingAiProvider(), app(CircuitBreaker::class));

    foreach ([
        fn () => $driver->chat([['role' => 'user', 'content' => 'hi']]),
        fn () => $driver->embed('text'),
        fn () => $driver->transcribe('a/b.mp3'),
    ] as $call) {
        try {
            $call();
        } catch (RuntimeException) {
        }
    }

    expect(fn () => $driver->embed('text again'))->toThrow(CircuitOpenException::class);
});

/**
 * Layering proof — `AppServiceProvider` binds
 * `SpendCapGuardedAiDriver(CircuitBreakerGuardedAiDriver(...))`, breaker
 * innermost. A spend-cap denial must never reach (or count toward) the
 * breaker, since it's a business-rule pre-check, not a real provider
 * failure.
 */
it('never counts a spend-cap denial toward the breaker, since the breaker sits inside the spend-cap check', function () {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    LessonArtifact::create([
        'lesson_id' => ingestibleLesson($tenant)->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'token_cost' => ['in' => 1, 'out' => 1, 'usd' => 999.0],
    ]);

    $driver = app(AiProvider::class);
    expect($driver)->toBeInstanceOf(SpendCapGuardedAiDriver::class);

    foreach (range(1, 5) as $i) {
        try {
            $driver->embed('hello');
        } catch (AiSpendCapExceededException) {
        }
    }

    expect(app(CircuitBreaker::class)->isOpen('ai-provider'))->toBeFalse();
});
