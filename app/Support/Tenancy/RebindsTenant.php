<?php

namespace App\Support\Tenancy;

use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\MissingTenantContextException;

class RebindsTenant
{
    public function handle($job, callable $next): void
    {
        $tenantId = $job->tenant_id ?? null;

        if (! $tenantId) {
            $job->fail(MissingTenantContextException::make());

            return;
        }

        $tenant = Tenant::findOrFail($tenantId);

        app(CurrentTenant::class)->set($tenant);

        $next($job);
    }
}
