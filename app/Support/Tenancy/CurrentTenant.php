<?php

namespace App\Support\Tenancy;

use App\Domains\Tenancy\Models\Tenant;
use App\Exceptions\MissingTenantContextException;

class CurrentTenant
{
    protected ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    public function isBound(): bool
    {
        return $this->tenant !== null;
    }

    public function get(): Tenant
    {
        if ($this->tenant === null) {
            throw MissingTenantContextException::make();
        }

        return $this->tenant;
    }

    public function id(): int
    {
        return $this->get()->id;
    }
}
