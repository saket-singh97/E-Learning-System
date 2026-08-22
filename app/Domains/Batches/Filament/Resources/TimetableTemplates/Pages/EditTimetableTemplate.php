<?php

namespace App\Domains\Batches\Filament\Resources\TimetableTemplates\Pages;

use App\Domains\Batches\Filament\Resources\TimetableTemplates\TimetableTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTimetableTemplate extends EditRecord
{
    protected static string $resource = TimetableTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
