<?php

namespace App\Domains\Content\Filament\Resources\CourseSections\Pages;

use App\Domains\Content\Filament\Resources\CourseSections\CourseSectionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCourseSection extends EditRecord
{
    protected static string $resource = CourseSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
