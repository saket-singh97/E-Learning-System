<?php

namespace App\Domains\Tenancy\Filament\Resources\FeatureFlags\Pages;

use App\Domains\Tenancy\Filament\Resources\FeatureFlags\FeatureFlagResource;
use App\Domains\Tenancy\Models\FeatureFlag;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateFeatureFlag extends CreateRecord
{
    protected static string $resource = FeatureFlagResource::class;

    /**
     * Super panel has no bound CurrentTenant (a super_admin has no tenant of
     * their own) — BelongsToTenant's creating() hook would otherwise
     * overwrite tenant_id from CurrentTenant and throw
     * MissingTenantContextException. Same withoutEvents + explicit
     * tenant_id shape PlanChangeService::subscribe() already established
     * for creating a tenant-scoped row from this exact unscoped position.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return FeatureFlag::withoutEvents(fn () => FeatureFlag::create($data));
    }
}
