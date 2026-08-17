<?php

namespace App\Domains\Security\Listeners;

use App\Domains\Security\Services\DeviceRegistrar;
use App\Exceptions\DeviceLimitExceededException;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;

/**
 * Fires on `Illuminate\Auth\Events\Login` — the one point every login
 * surface in this app already funnels through (password, TOTP-completed,
 * Google OAuth, all three Filament panels, both portals), unlike
 * `TenantAuthenticator::validate()` which only covers the password path.
 * No controller needed touching to wire this in (design doc §4 decision 2).
 *
 * By the time this event fires, `SessionGuard::login()` has already
 * written the user id into the session — a bare thrown exception here
 * would leave a half-established session behind. A rejected device
 * explicitly logs the guard back out and invalidates the session first,
 * matching `AuthenticatedSessionController::destroy()`'s own real-logout
 * shape, before letting the exception propagate to the framework's normal
 * `abort(409, ...)` render (`bootstrap/app.php`).
 */
class RegisterDeviceOnLogin
{
    public function __construct(
        private readonly DeviceRegistrar $registrar,
    ) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User || $event->user->tenant_id === null) {
            return;
        }

        try {
            $this->registrar->registerLogin($event->user, request());
        } catch (DeviceLimitExceededException $e) {
            Auth::guard($event->guard)->logout();

            $session = request()->session();
            $session->invalidate();
            $session->regenerateToken();

            throw $e;
        }
    }
}
