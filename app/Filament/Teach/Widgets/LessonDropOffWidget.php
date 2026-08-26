<?php

namespace App\Filament\Teach\Widgets;

use App\Domains\Analytics\Services\DropOffService;
use App\Domains\Content\Models\Lesson;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * S18 B5 — same ViewLessonArtifact footer as LessonRewatchHeatmapWidget
 * (B3), as its own separate widget/section rather than folded into it —
 * drop-off and rewatch density are two different questions an instructor
 * asks separately.
 */
class LessonDropOffWidget extends StatsOverviewWidget
{
    public ?Lesson $record = null;

    protected function getStats(): array
    {
        if ($this->record === null) {
            return [];
        }

        $details = app(DropOffService::class)->detailsFor($this->record);

        if ($details === null) {
            return [
                Stat::make('Drop-off point', 'No significant drop-off'),
            ];
        }

        return [
            Stat::make('Drop-off point', "{$details['percentage_of_runtime']}% through the video")
                ->description("{$details['students_affected']} students stopped here"),
        ];
    }
}
