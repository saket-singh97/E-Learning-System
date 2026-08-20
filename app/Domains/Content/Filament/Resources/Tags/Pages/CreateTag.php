<?php

namespace App\Domains\Content\Filament\Resources\Tags\Pages;

use App\Domains\Content\Filament\Resources\Tags\TagResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTag extends CreateRecord
{
    protected static string $resource = TagResource::class;
}
