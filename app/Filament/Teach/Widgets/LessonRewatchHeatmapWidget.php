<?php

namespace App\Filament\Teach\Widgets;

use App\Domains\Analytics\Services\RewatchHeatmapService;
use App\Domains\Content\Models\Lesson;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * S18 B3 — placed on ViewLessonArtifact's getFooterWidgets(), same
 * InteractsWithRecord::getWidgetData() wiring BatchWeakTopicsWidget (S17
 * B9) and BatchEngagementWidget (S18 B1) already use. Top-N most-replayed
 * windows only, timestamps formatted m:ss.
 */
class LessonRewatchHeatmapWidget extends StatsOverviewWidget
{
    private const TOP_N = 5;

    public ?Lesson $record = null;

    protected function getStats(): array
    {
        if ($this->record === null) {
            return [];
        }

        $heatmap = app(RewatchHeatmapService::class)->heatmapFor($this->record)->take(self::TOP_N);

        if ($heatmap->isEmpty()) {
            return [
                Stat::make('Rewatch heatmap', 'No watch data yet'),
            ];
        }

        return $heatmap
            ->map(fn (array $window): Stat => Stat::make(
                $this->formatRange($window['bucket_start_s'], $window['bucket_end_s']),
                "{$window['replay_count']} students",
            ))
            ->all();
    }

    protected function formatRange(int $startSeconds, int $endSeconds): string
    {
        return sprintf('%s-%s', $this->formatTimestamp($startSeconds), $this->formatTimestamp($endSeconds));
    }

    protected function formatTimestamp(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
