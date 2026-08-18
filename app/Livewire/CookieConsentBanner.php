<?php

namespace App\Livewire;

use App\Domains\Security\Services\CookieConsentService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * S12 B7 (FEATURES.md B15 pt.1) — shared across every surface a first
 * visit could land on, same "every surface, one component" shape
 * `ImpersonationBanner`/`NotificationCentre` already established: the
 * three Filament panels' guest login page (`SIMPLE_PAGE_END` render hook,
 * `AppServiceProvider`) and authenticated shell (`TOPBAR_END`), plus both
 * portals' single shared dashboard blade (covers their own guest/
 * authenticated toggle already).
 *
 * Only two choices offered ("accept all" / "necessary only"), not a
 * per-category toggle UI — the smallest surface `cookie_consents.categories`
 * (a JSON map) actually needs recorded, matching every other block in this
 * slice's own "smallest version that delivers the block's value" posture.
 */
class CookieConsentBanner extends Component
{
    public function acceptAll(): void
    {
        $this->save(['necessary' => true, 'analytics' => true, 'marketing' => true]);
    }

    public function acceptNecessaryOnly(): void
    {
        $this->save(['necessary' => true, 'analytics' => false, 'marketing' => false]);
    }

    /**
     * @param  array<string, bool>  $categories
     */
    protected function save(array $categories): void
    {
        $currentTenant = app(CurrentTenant::class);

        app(CookieConsentService::class)->record(
            sessionId: session()->getId(),
            user: Auth::user(),
            tenantId: $currentTenant->isBound() ? $currentTenant->id() : null,
            categories: $categories,
        );
    }

    public function render(): View
    {
        $shouldShow = ! app(CookieConsentService::class)->hasDecision(session()->getId(), Auth::user());

        return view('livewire.cookie-consent-banner', ['shouldShow' => $shouldShow]);
    }
}
