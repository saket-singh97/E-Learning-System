<?php

namespace App\Domains\Content\Filament\Resources\Programs\Pages;

use App\Domains\Content\Filament\Resources\Programs\ProgramResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProgram extends CreateRecord
{
    protected static string $resource = ProgramResource::class;
}
