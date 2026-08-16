<?php

namespace App\Domains\Assignments\Filament\Resources\Assignments\Pages;

use App\Domains\Assignments\Filament\Resources\Assignments\AssignmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAssignment extends CreateRecord
{
    protected static string $resource = AssignmentResource::class;
}
