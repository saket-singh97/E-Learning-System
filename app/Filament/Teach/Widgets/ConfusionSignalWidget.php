<?php

namespace App\Filament\Teach\Widgets;

use App\Domains\Analytics\Services\ConfusionSignalService;
use App\Domains\Batches\Models\Batch;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * U9 pt.2 — placed on EditBatch's getFooterWidgets(), same
 * InteractsWithRecord::getWidgetData() wiring BatchWeakTopicsWidget/
 * BatchEngagementWidget already use. Needs no extra ownership check here:
 * BatchResource::getEloquentQuery() already restricts an instructor's
 * Teach panel to their own instructor_id batches, so a non-owning
 * instructor 404s before this widget ever mounts — covered once,
 * generically, by TeachPanelBatchScopingTest rather than re-tested per
 * footer widget (same reliance every prior EditBatch widget already takes).
 *
 * Line format follows docs/FEATURES.md's own U9 example ("Your batch is
 * stuck on Entropy — 34 doubts, 12 rewatches of 08:40-10:10, 61% failed
 * Q4") as closely as the data supports — ConfusionSignalService computes
 * one aggregate failure rate per lesson's own quiz, not a per-question
 * breakdown, so this reads as a percentage rather than naming a single
 * worst question (design doc §2.3: exact wording/weighting is a tuning
 * pass after real usage, not a day-one requirement).
 */
class ConfusionSignalWidget extends StatsOverviewWidget
{
    private const TOP_N = 5;

    public ?Batch $record = null;

    protected function getStats(): array
    {
        if ($this->record === null) {
            return [];
        }

        $ranked = app(ConfusionSignalService::class)->rankedFor($this->record)->take(self::TOP_N);

        if ($ranked->isEmpty()) {
            return [
                Stat::make('Confusion signal', 'No lessons taught yet'),
            ];
        }

        return $ranked
            ->map(fn (array $row): Stat => Stat::make($row['lesson']->title, $this->lineFor($row)))
            ->all();
    }

    private function lineFor(array $row): string
    {
        $rewatch = $row['hottest_rewatch'] !== null
            ? "{$row['hottest_rewatch']['replay_count']} rewatches of {$this->formatRange($row['hottest_rewatch']['bucket_start_s'], $row['hottest_rewatch']['bucket_end_s'])}"
            : 'no rewatch data';

        $failure = $row['failure_rate'] > 0
            ? number_format($row['failure_rate'] * 100, 0).'% failed'
            : 'no quiz data';

        return "{$row['doubt_count']} doubts, {$rewatch}, {$failure}";
    }

    private function formatRange(int $startSeconds, int $endSeconds): string
    {
        return sprintf('%s-%s', $this->formatTimestamp($startSeconds), $this->formatTimestamp($endSeconds));
    }

    private function formatTimestamp(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
