<?php

namespace App\Domains\Billing\Filament\Resources\Plans\Pages;

use App\Domains\Billing\Filament\Resources\Plans\PlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;
}
