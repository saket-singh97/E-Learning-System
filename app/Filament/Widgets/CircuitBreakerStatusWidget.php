<?php

namespace App\Filament\Widgets;

use App\Support\Resilience\CircuitBreaker;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * S12 B11 — design doc §2.3's own explicit call: "V1 needs breaker state
 * *visible*, not a dedicated CRUD surface... a read-only widget on the
 * existing Super-panel/Admin-panel overview pages is enough." One shared
 * class for both panels, same reasoning `TokenSpendWidget` already
 * established — breaker state (Redis) isn't tenant-scoped at all, so
 * there's nothing panel-specific to branch on. Auto-discovered by
 * `SuperPanelProvider`'s own `app/Filament/Widgets` scan;
 * `AdminPanelProvider` registers it explicitly since its discovery root
 * is `app/Filament/Admin/Widgets` instead.
 *
 * Lists every provider this slice wired a breaker onto — B10's
 * `video-provider`/`ai-provider` and B11's own
 * `payment-provider`/`subscription-provider`/`comms-provider`/
 * `live-class-provider` — not just this block's own four, since a
 * partial dashboard would be a worse status page than a complete one.
 */
class CircuitBreakerStatusWidget extends StatsOverviewWidget
{
    private const PROVIDERS = [
        'video-provider' => 'Video',
        'ai-provider' => 'AI',
        'payment-provider' => 'Payment',
        'subscription-provider' => 'Subscription',
        'comms-provider' => 'WhatsApp',
        'live-class-provider' => 'Live class',
    ];

    protected function getStats(): array
    {
        $breaker = app(CircuitBreaker::class);

        return array_map(
            fn (string $key, string $label): Stat => $breaker->isOpen($key)
                ? Stat::make($label, 'Open')->color('danger')->description('Failing fast until the cooldown window passes')
                : Stat::make($label, 'Closed')->color('success')->description('Healthy'),
            array_keys(self::PROVIDERS),
            self::PROVIDERS,
        );
    }
}
