<?php

namespace App\Domains\Batches\Filament\Resources\TimetableTemplates\Pages;

use App\Domains\Batches\Filament\Resources\TimetableTemplates\TimetableTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTimetableTemplates extends ListRecords
{
    protected static string $resource = TimetableTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
