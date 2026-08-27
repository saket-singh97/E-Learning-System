<?php

namespace App\Domains\Fees\Filament\Resources\StudentMembershipPlans\Pages;

use App\Domains\Fees\Filament\Resources\StudentMembershipPlans\StudentMembershipPlanResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStudentMembershipPlan extends EditRecord
{
    protected static string $resource = StudentMembershipPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
