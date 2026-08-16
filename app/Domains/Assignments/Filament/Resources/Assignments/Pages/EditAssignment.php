<?php

namespace App\Domains\Assignments\Filament\Resources\Assignments\Pages;

use App\Domains\Assignments\Filament\Resources\Assignments\AssignmentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAssignment extends EditRecord
{
    protected static string $resource = AssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
