<?php

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Models\FeatureFlag;
use App\Domains\Tenancy\Models\Tenant;

class FeatureGate
{
    /**
     * The one place a feature flag is ever checked (S21 design doc §4
     * decision 2) — call sites never read FeatureFlag directly. An unset
     * flag defaults to false: opt-in, never opt-out-by-accident.
     */
    public function enabled(Tenant $tenant, string $key): bool
    {
        return (bool) FeatureFlag::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('key', $key)
            ->value('enabled');
    }
}
