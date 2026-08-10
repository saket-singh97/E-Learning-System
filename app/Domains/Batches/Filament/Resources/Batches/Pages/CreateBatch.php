<?php

namespace App\Domains\Batches\Filament\Resources\Batches\Pages;

use App\Domains\Batches\Filament\Resources\Batches\BatchResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBatch extends CreateRecord
{
    protected static string $resource = BatchResource::class;
}
