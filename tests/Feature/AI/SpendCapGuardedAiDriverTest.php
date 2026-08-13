<?php

use App\Domains\AI\Models\LessonArtifact;
use App\Domains\AI\Services\AiSpendCap;
use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\AiSpendCapExceededException;
use App\Integrations\AiProvider;
use App\Integrations\Drivers\FakeAiDriver;
use App\Integrations\Drivers\SpendCapGuardedAiDriver;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($this->tenant);
});

it('resolves the decorated driver from the AiProvider binding, not the bare fake', function () {
    expect(app(AiProvider::class))->toBeInstanceOf(SpendCapGuardedAiDriver::class);
});

it('allows a call through while comfortably under the cap', function () {
    $driver = new SpendCapGuardedAiDriver(new FakeAiDriver, app(AiSpendCap::class));

    expect($driver->embed('hello'))->toBeArray();
});

it('blocks transcribe, chat, and embed alike once the tenant is already over its daily spend cap', function () {
    LessonArtifact::create([
        'lesson_id' => ingestibleLesson($this->tenant)->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'token_cost' => ['in' => 1, 'out' => 1, 'usd' => 999.0],
    ]);

    $driver = new SpendCapGuardedAiDriver(new FakeAiDriver, app(AiSpendCap::class));

    expect(fn () => $driver->transcribe('videos/whatever.mp4'))
        ->toThrow(AiSpendCapExceededException::class)
        ->and(fn () => $driver->chat([['role' => 'user', 'content' => 'hi']]))
        ->toThrow(AiSpendCapExceededException::class)
        ->and(fn () => $driver->embed('hello'))
        ->toThrow(AiSpendCapExceededException::class);
});

it('does not call the inner driver at all once over cap', function () {
    LessonArtifact::create([
        'lesson_id' => ingestibleLesson($this->tenant)->id,
        'version' => 1,
        'source_asset_version' => 'v1',
        'status' => 'ready',
        'token_cost' => ['in' => 1, 'out' => 1, 'usd' => 999.0],
    ]);

    $inner = new class extends FakeAiDriver
    {
        public bool $called = false;

        public function embed(string $text): array
        {
            $this->called = true;

            return parent::embed($text);
        }
    };

    $driver = new SpendCapGuardedAiDriver($inner, app(AiSpendCap::class));

    try {
        $driver->embed('hello');
    } catch (AiSpendCapExceededException) {
        // expected
    }

    expect($inner->called)->toBeFalse();
});
