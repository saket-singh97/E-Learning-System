<?php

namespace App\Filament\Teach\Auth;

use App\Filament\Auth\TenantAwareLogin;
use App\Support\Tenancy\CurrentTenant;

/**
 * Filament's stock login does a plain email/password Auth::attempt with no
 * tenant_id key, which would bypass the tenant-scoped credential lookup
 * Block 5 built (same collision risk Block 5 already solved once for the
 * JSON endpoint). TenantAwareLogin delegates the credential check to
 * TenantAuthenticator while keeping Filament's own multi-factor challenge
 * bookkeeping intact.
 */
class Login extends TenantAwareLogin
{
    protected function resolveTenantId(): ?int
    {
        return app(CurrentTenant::class)->id();
    }
}
