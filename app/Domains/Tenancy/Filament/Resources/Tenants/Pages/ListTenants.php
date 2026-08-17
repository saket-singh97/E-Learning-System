<?php

namespace App\Domains\Tenancy\Filament\Resources\Tenants\Pages;

use App\Domains\Tenancy\Filament\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\ListRecords;

class ListTenants extends ListRecords
{
    protected static string $resource = TenantResource::class;
}
