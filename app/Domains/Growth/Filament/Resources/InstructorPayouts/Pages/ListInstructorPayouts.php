<?php

namespace App\Domains\Growth\Filament\Resources\InstructorPayouts\Pages;

use App\Domains\Growth\Filament\Resources\InstructorPayouts\InstructorPayoutResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInstructorPayouts extends ListRecords
{
    protected static string $resource = InstructorPayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
