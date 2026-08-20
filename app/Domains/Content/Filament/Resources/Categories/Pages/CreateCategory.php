<?php

namespace App\Domains\Content\Filament\Resources\Categories\Pages;

use App\Domains\Content\Filament\Resources\Categories\CategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;
}
