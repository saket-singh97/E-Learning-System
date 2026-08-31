<?php

namespace App\Domains\Growth\Filament\Resources\ScholarshipTests\Pages;

use App\Domains\Growth\Filament\Resources\ScholarshipTests\ScholarshipTestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListScholarshipTests extends ListRecords
{
    protected static string $resource = ScholarshipTestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
