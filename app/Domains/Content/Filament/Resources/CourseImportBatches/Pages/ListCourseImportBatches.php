<?php

namespace App\Domains\Content\Filament\Resources\CourseImportBatches\Pages;

use App\Domains\Content\Filament\Resources\CourseImportBatches\CourseImportBatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCourseImportBatches extends ListRecords
{
    protected static string $resource = CourseImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Upload CSV'),
        ];
    }
}
