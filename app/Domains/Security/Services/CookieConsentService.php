<?php

namespace App\Domains\Security\Services;

use App\Domains\Security\Models\CookieConsent;
use App\Models\User;

/**
 * Design doc §4 decision 9 — consent is stored per-session before login,
 * then reconciled to the user once authenticated. `PENDING_SESSION_KEY` is
 * the mechanism that makes reconciliation possible at all: `Login`'s own
 * event listener fires *after* `SessionGuard::login()` has already
 * regenerated the session ID (confirmed by tracing `SessionGuard::login()`
 * -> `updateSession()` -> `Store::regenerate()`), so a naive
 * `session()->getId()` lookup inside the listener would find zero rows —
 * the pre-login ID that `record()` wrote against is already gone by then.
 * Session *data* (unlike the ID) survives that regeneration, so `record()`
 * stashes the pre-login session ID under a session key instead of relying
 * on the ID itself; the `Login` listener reads that key back, still intact.
 */
class CookieConsentService
{
    public const PENDING_SESSION_KEY = 'cookie_consent_pending_session_id';

    /**
     * @param  array<string, bool>  $categories
     */
    public function record(string $sessionId, ?User $user, ?int $tenantId, array $categories): CookieConsent
    {
        $consent = CookieConsent::create([
            'tenant_id' => $tenantId,
            'user_id' => $user?->id,
            'session_id' => $sessionId,
            'categories' => $categories,
            'consented_at' => now(),
        ]);

        if ($user === null) {
            session()->put(self::PENDING_SESSION_KEY, $sessionId);
        }

        return $consent;
    }

    /**
     * A logged-in visitor is checked by `user_id` (covers a decision made
     * either before or after login, since reconciliation backfills the
     * pre-login row onto the same user), an anonymous one by `session_id`.
     */
    public function hasDecision(string $sessionId, ?User $user): bool
    {
        if ($user !== null) {
            return CookieConsent::where('user_id', $user->id)->exists();
        }

        return CookieConsent::where('session_id', $sessionId)->exists();
    }

    /**
     * Explicit `$sessionId` param, not read from `session()` internally —
     * same "explicit, never ambient" discipline `DeviceRegistrar` (B1)
     * already established, and it's what makes this method a plain,
     * directly-testable unit rather than one only exercisable through a
     * real session round-trip. The `Login` listener is what reads the
     * session key and passes the value in.
     */
    public function reconcile(string $sessionId, User $user): void
    {
        CookieConsent::where('session_id', $sessionId)
            ->whereNull('user_id')
            ->update(['user_id' => $user->id]);
    }
}
