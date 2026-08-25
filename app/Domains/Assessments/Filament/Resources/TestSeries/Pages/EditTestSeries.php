<?php

namespace App\Domains\Assessments\Filament\Resources\TestSeries\Pages;

use App\Domains\Assessments\Filament\Resources\TestSeries\TestSeriesResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTestSeries extends EditRecord
{
    protected static string $resource = TestSeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
