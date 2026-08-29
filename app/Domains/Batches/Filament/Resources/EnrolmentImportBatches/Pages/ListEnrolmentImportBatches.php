<?php

namespace App\Domains\Batches\Filament\Resources\EnrolmentImportBatches\Pages;

use App\Domains\Batches\Filament\Resources\EnrolmentImportBatches\EnrolmentImportBatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEnrolmentImportBatches extends ListRecords
{
    protected static string $resource = EnrolmentImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Upload CSV'),
        ];
    }
}
