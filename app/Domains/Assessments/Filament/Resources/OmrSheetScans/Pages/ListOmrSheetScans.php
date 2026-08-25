<?php

namespace App\Domains\Assessments\Filament\Resources\OmrSheetScans\Pages;

use App\Domains\Assessments\Filament\Resources\OmrSheetScans\OmrSheetScanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOmrSheetScans extends ListRecords
{
    protected static string $resource = OmrSheetScanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
