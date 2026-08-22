<?php

namespace App\Domains\Tenancy\Filament\Resources\InstructorInvitations\Pages;

use App\Domains\Tenancy\Filament\Resources\InstructorInvitations\InstructorInvitationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInstructorInvitations extends ListRecords
{
    protected static string $resource = InstructorInvitationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
