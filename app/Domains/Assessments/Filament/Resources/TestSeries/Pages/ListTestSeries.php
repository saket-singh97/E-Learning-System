<?php

namespace App\Domains\Assessments\Filament\Resources\TestSeries\Pages;

use App\Domains\Assessments\Filament\Resources\TestSeries\TestSeriesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTestSeries extends ListRecords
{
    protected static string $resource = TestSeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
