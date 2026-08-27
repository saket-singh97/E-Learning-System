<?php

namespace App\Domains\Fees\Filament\Resources\StudentMembershipPlans\Pages;

use App\Domains\Fees\Filament\Resources\StudentMembershipPlans\StudentMembershipPlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStudentMembershipPlan extends CreateRecord
{
    protected static string $resource = StudentMembershipPlanResource::class;
}
