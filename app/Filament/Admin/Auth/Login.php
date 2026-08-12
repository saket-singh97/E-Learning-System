<?php

namespace App\Filament\Admin\Auth;

use App\Filament\Auth\TenantAwareLogin;
use App\Support\Tenancy\CurrentTenant;

/**
 * Same tenant-aware override as Teach — see App\Filament\Teach\Auth\Login.
 */
class Login extends TenantAwareLogin
{
    protected function resolveTenantId(): ?int
    {
        return app(CurrentTenant::class)->id();
    }
}
