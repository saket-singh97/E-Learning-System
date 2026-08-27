<?php

namespace App\Domains\Fees\Filament\Resources\StudentMembershipPlans\Pages;

use App\Domains\Fees\Filament\Resources\StudentMembershipPlans\StudentMembershipPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStudentMembershipPlans extends ListRecords
{
    protected static string $resource = StudentMembershipPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
