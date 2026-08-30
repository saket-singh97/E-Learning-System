<?php

namespace App\Domains\Content\Filament\Resources\Courses\Pages;

use App\Domains\Content\Filament\Resources\Courses\CourseResource;
use App\Filament\Teach\Widgets\CourseSyllabusTrackerWidget;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCourse extends EditRecord
{
    protected static string $resource = CourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * S17 B9 — InteractsWithRecord::getWidgetData() (Filament core) already
     * hands every footer widget ['record' => $this->getRecord()] for free.
     */
    protected function getFooterWidgets(): array
    {
        return [
            CourseSyllabusTrackerWidget::class,
        ];
    }
}
