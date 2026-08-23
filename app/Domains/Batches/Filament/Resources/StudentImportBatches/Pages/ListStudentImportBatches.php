<?php

namespace App\Domains\Batches\Filament\Resources\StudentImportBatches\Pages;

use App\Domains\Batches\Filament\Resources\StudentImportBatches\StudentImportBatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStudentImportBatches extends ListRecords
{
    protected static string $resource = StudentImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Upload CSV'),
        ];
    }
}
