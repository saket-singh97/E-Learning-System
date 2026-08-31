<?php

namespace App\Filament\Admin\Widgets;

use App\Domains\Fees\Services\FeeAnalyticsService;
use App\Support\Tenancy\CurrentTenant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * S24 U23 pt.2 — auto-discovered by AdminPanelProvider's discoverWidgets()
 * (lives directly in this directory, same as InstituteOverviewWidget, no
 * explicit ->widgets([...]) entry needed). Same aggregate-only posture
 * InstituteOverviewWidget/BatchEngagementWidget already establish — no
 * per-student/per-invoice breakdown here, only tenant-wide totals.
 * `FeeAnalyticsService`'s own numbers are all divide-by-zero-safe already
 * (collectionRate() returns 0.0 for a zero-total tenant, the other two
 * are plain sums over a possibly-empty set), so a zero-invoice tenant
 * renders 0%/₹0.00/0 defaulters here with no special-cased empty state
 * needed — unlike BatchEngagementWidget's two-tier text swap, which
 * exists because *that* service's own ratios could divide by zero.
 */
class FeeAnalyticsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        if (! app(CurrentTenant::class)->isBound()) {
            return [];
        }

        $tenant = app(CurrentTenant::class)->get();
        $analytics = app(FeeAnalyticsService::class);

        $collectionRate = $analytics->collectionRate($tenant);
        $projectedRevenue = $analytics->projectedRevenue($tenant);
        $ageing = $analytics->defaulterAgeing($tenant);
        $defaulterCount = $ageing['0-30']['count'] + $ageing['31-60']['count'] + $ageing['60+']['count'];

        return [
            Stat::make('Collection rate', number_format($collectionRate, 2).'%'),
            Stat::make('Projected revenue', '₹'.number_format($projectedRevenue, 2))
                ->description('Unpaid invoices due in the future'),
            Stat::make('Defaulters', (string) $defaulterCount)
                ->description("0-30d: {$ageing['0-30']['count']} · 31-60d: {$ageing['31-60']['count']} · 60+d: {$ageing['60+']['count']}"),
        ];
    }
}
