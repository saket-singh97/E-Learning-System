<?php

namespace App\Domains\Batches\Filament\Resources\Batches\Pages;

use App\Domains\Batches\Filament\Resources\Batches\BatchResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBatch extends EditRecord
{
    protected static string $resource = BatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
