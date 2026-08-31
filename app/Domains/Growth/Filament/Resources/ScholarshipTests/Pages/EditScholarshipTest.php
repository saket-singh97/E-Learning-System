<?php

namespace App\Domains\Growth\Filament\Resources\ScholarshipTests\Pages;

use App\Domains\Growth\Filament\Resources\ScholarshipTests\ScholarshipTestResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditScholarshipTest extends EditRecord
{
    protected static string $resource = ScholarshipTestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
