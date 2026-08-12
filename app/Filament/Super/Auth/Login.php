<?php

namespace App\Filament\Super\Auth;

use App\Filament\Auth\TenantAwareLogin;

/**
 * Super panel has no domain/ResolveTenant — tenant_id is always null here,
 * same as super_admin everywhere else in this codebase (Auth::attempt with
 * tenant_id => null resolves to WHERE tenant_id IS NULL).
 */
class Login extends TenantAwareLogin
{
    protected function resolveTenantId(): ?int
    {
        return null;
    }
}
