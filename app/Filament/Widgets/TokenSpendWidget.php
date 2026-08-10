<?php

namespace App\Filament\Widgets;

use App\Domains\AI\Services\TokenCostLedger;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * One shared class for both panels, not two near-duplicates: TokenCostLedger
 * reads platform-wide on /super (CurrentTenant unbound there) and
 * tenant-scoped on /admin (CurrentTenant bound), for free, via
 * BelongsToTenant's own bound-vs-unbound behavior — see the ledger's
 * docblock. Auto-discovered by SuperPanelProvider's app/Filament/Widgets
 * scan; AdminPanelProvider registers it explicitly since its discovery
 * root is app/Filament/Admin/Widgets instead.
 */
class TokenSpendWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $total = app(TokenCostLedger::class)->totalForDate(now());

        return [
            Stat::make('AI spend today', '$'.number_format($total['usd'], 4))
                ->description($total['count'].' lesson ingestion(s)'),
        ];
    }
}
