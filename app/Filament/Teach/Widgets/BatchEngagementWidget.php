<?php

namespace App\Filament\Teach\Widgets;

use App\Domains\Analytics\Services\EngagementMetricsService;
use App\Domains\Batches\Models\Batch;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * S18 B1 — placed on EditBatch's getFooterWidgets() alongside
 * BatchWeakTopicsWidget (same InteractsWithRecord::getWidgetData() wiring,
 * S17 B9 precedent). Aggregate only, per-batch — never a per-student
 * breakdown.
 */
class BatchEngagementWidget extends StatsOverviewWidget
{
    public ?Batch $record = null;

    protected function getStats(): array
    {
        if ($this->record === null) {
            return [];
        }

        $engagement = app(EngagementMetricsService::class)->classEngagementFor($this->record);

        if ($engagement['total_students'] === 0) {
            return [
                Stat::make('Engagement', 'No students enrolled yet'),
            ];
        }

        if ($engagement['sample_size'] === 0) {
            return [
                Stat::make('Engagement', 'No watch data yet'),
            ];
        }

        return [
            Stat::make('Avg engagement ratio', number_format($engagement['avg_ratio'] * 100, 0).'%'),
            Stat::make('Sustained 2x playback', "{$engagement['sustained_2x_count']} / {$engagement['total_students']} students"),
        ];
    }
}
