<?php

namespace App\Domains\Security\Listeners;

use App\Domains\Security\Services\CookieConsentService;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * S12 B7 (FEATURES.md B15 pt.1) — a third sibling listener on the same
 * `Login` event chokepoint `RegisterDeviceOnLogin` (B1) established, same
 * "one listener per concern" shape as `RecordLoginHistory` (B3).
 *
 * Unlike those two, this one is never skipped for a null-tenant
 * (`super_admin`) login — cookie consent isn't a tenant-scoped concern at
 * all, a platform-level visitor's own pre-login consent is exactly as
 * real as anyone else's.
 */
class ReconcileCookieConsent
{
    public function __construct(private readonly CookieConsentService $consent) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $sessionId = session(CookieConsentService::PENDING_SESSION_KEY);

        if ($sessionId === null) {
            return;
        }

        $this->consent->reconcile($sessionId, $event->user);

        session()->forget(CookieConsentService::PENDING_SESSION_KEY);
    }
}
