<?php

namespace App\Domains\Billing\Filament\Resources\TenantSubscriptions\Pages;

use App\Domains\Billing\Filament\Resources\TenantSubscriptions\TenantSubscriptionResource;
use Filament\Resources\Pages\ListRecords;

class ListTenantSubscriptions extends ListRecords
{
    protected static string $resource = TenantSubscriptionResource::class;
}
