<?php

namespace App\Livewire;

use App\Domains\Tenancy\Services\ImpersonationService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Shared across all three panels (Filament render hook, AppServiceProvider)
 * and both portals (embedded directly, same as notification-centre) — "Stop
 * impersonating" must be reachable from every surface a super_admin could
 * have impersonated into, not just a route nobody can find (S9 B3 plan
 * note). Renders nothing when no impersonation session is active.
 */
class ImpersonationBanner extends Component
{
    public function stop()
    {
        app(ImpersonationService::class)->stop();

        // Always back to the bare Super panel host, never a relative path —
        // this component can be stopped from a tenant subdomain, and
        // EnsureRole would 403 a super_admin (tenant_id null) the moment
        // CurrentTenant resolves to a bound tenant on that same host.
        return redirect()->away('http://lms.test/super');
    }

    public function render(): View
    {
        return view('livewire.impersonation-banner', [
            'impersonatedUser' => session()->has('impersonating_user_id') ? Auth::user() : null,
        ]);
    }
}
