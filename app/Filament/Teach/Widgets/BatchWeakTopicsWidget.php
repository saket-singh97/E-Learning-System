<?php

namespace App\Filament\Teach\Widgets;

use App\Domains\AI\Services\WeakTopicDetectionService;
use App\Domains\Batches\Models\Batch;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * L13 pt.3 — placed on EditBatch's getFooterWidgets(); Filament's
 * InteractsWithRecord::getWidgetData() automatically hands this
 * ['record' => $batch] (same mechanism InstituteOverviewWidget's own
 * tenant-wide stats don't need, being page-wide rather than per-record).
 * Aggregate only — top weak concepts across the batch's own actively
 * enrolled students, never a per-student breakdown (that's WeakTopics,
 * B8, and only the student themself sees it).
 */
class BatchWeakTopicsWidget extends StatsOverviewWidget
{
    private const TOP_N = 5;

    public ?Batch $record = null;

    protected function getStats(): array
    {
        if ($this->record === null) {
            return [];
        }

        $weakTopics = app(WeakTopicDetectionService::class)
            ->classWideWeakTopicsFor($this->record)
            ->take(self::TOP_N);

        if ($weakTopics->isEmpty()) {
            return [
                Stat::make('Weak topics', 'None yet')
                    ->description('No quiz attempts recorded for this batch yet.'),
            ];
        }

        return $weakTopics
            ->map(fn (array $topic): Stat => Stat::make(
                $topic['concept'],
                "{$topic['weak_student_count']}/{$topic['total_students']} students weak",
            ))
            ->all();
    }
}
