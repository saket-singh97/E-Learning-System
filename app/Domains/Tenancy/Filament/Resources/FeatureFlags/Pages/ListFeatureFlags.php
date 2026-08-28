<?php

namespace App\Domains\Tenancy\Filament\Resources\FeatureFlags\Pages;

use App\Domains\Tenancy\Filament\Resources\FeatureFlags\FeatureFlagResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeatureFlags extends ListRecords
{
    protected static string $resource = FeatureFlagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
