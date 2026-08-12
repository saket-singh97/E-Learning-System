<?php

namespace App\Domains\Comms\Filament\Resources\ParentGuardians\Pages;

use App\Domains\Comms\Filament\Resources\ParentGuardians\ParentGuardianResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListParentGuardians extends ListRecords
{
    protected static string $resource = ParentGuardianResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
