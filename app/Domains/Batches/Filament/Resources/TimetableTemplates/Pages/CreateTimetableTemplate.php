<?php

namespace App\Domains\Batches\Filament\Resources\TimetableTemplates\Pages;

use App\Domains\Batches\Filament\Resources\TimetableTemplates\TimetableTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTimetableTemplate extends CreateRecord
{
    protected static string $resource = TimetableTemplateResource::class;
}
