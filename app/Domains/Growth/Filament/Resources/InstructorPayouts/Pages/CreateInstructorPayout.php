<?php

namespace App\Domains\Growth\Filament\Resources\InstructorPayouts\Pages;

use App\Domains\Growth\Filament\Resources\InstructorPayouts\InstructorPayoutResource;
use App\Domains\Growth\Models\InstructorPayout;
use Filament\Resources\Pages\CreateRecord;

class CreateInstructorPayout extends CreateRecord
{
    protected static string $resource = InstructorPayoutResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['basis'] = InstructorPayout::BASIS_SALARIED;
        $data['status'] = 'pending';

        return $data;
    }
}
