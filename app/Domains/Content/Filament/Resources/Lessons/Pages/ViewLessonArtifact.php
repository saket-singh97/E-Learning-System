<?php

namespace App\Domains\Content\Filament\Resources\Lessons\Pages;

use App\Domains\Content\Filament\Resources\Lessons\LessonResource;
use App\Filament\Teach\Widgets\LessonDropOffWidget;
use App\Filament\Teach\Widgets\LessonRewatchHeatmapWidget;
use Filament\Resources\Pages\ViewRecord;

class ViewLessonArtifact extends ViewRecord
{
    protected static string $resource = LessonResource::class;

    /**
     * S18 B3/B5 — InteractsWithRecord::getWidgetData() (Filament core)
     * hands this ['record' => $this->getRecord()] for free, same wiring
     * EditBatch's own footer widgets (S17 B9, S18 B1) already use.
     */
    protected function getFooterWidgets(): array
    {
        return [
            LessonRewatchHeatmapWidget::class,
            LessonDropOffWidget::class,
        ];
    }
}
