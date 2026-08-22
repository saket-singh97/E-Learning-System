<?php

namespace App\Domains\Tenancy\Services;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The one place tenant-scoped login + lockout lives. Block 5 built this logic
 * once for the JSON auth endpoint; Block 0 (S2) reuses it for the three
 * Filament panel login pages instead of re-implementing lockout four times
 * and risking the double-throttle collision Block 5 already hit once.
 *
 * S9 B1 split the old `attempt()` (validate + log in, one step) into
 * `validate()` (credentials + lockout only, no login) because every caller
 * now needs a chance to check `User::hasTwoFactorEnabled()` and detour into
 * a code challenge *before* the session is established — logging in first
 * and challenging after would mean a wrong/missing code still leaves an
 * authenticated session sitting there. `challengeTwoFactor()` is the
 * second-step counterpart, reused by both non-Filament callers (the two
 * portals, the JSON endpoint) — the three Filament panels don't call it, they
 * use Filament's own built-in `AppAuthentication` challenge/lockout instead
 * (wired into each panel's `Login` page), so there is exactly one TOTP
 * implementation in the app, not two competing ones.
 */
class TenantAuthenticator
{
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 900;

    public function validate(
        string $email,
        string $password,
        ?int $tenantId,
        string $ip,
        string $emailFieldKey = 'email',
    ): User {
        $lockoutKey = $this->lockoutKey($email, $ip);

        $this->ensureIsNotLockedOut($lockoutKey, $emailFieldKey);

        $credentials = [
            'email' => $email,
            'password' => $password,
            'tenant_id' => $tenantId,
        ];

        if (! Auth::validate($credentials)) {
            RateLimiter::hit($lockoutKey, self::LOCKOUT_SECONDS);

            throw ValidationException::withMessages([
                $emailFieldKey => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($lockoutKey);

        /** @var User $user */
        $user = Auth::getProvider()->retrieveByCredentials($credentials);

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                $emailFieldKey => 'This account has been deactivated.',
            ]);
        }

        return $user;
    }

    /**
     * Verifies a TOTP code (or, failing that, a recovery code) against an
     * already-password-validated user, with its own lockout independent of
     * the password lockout above — a wrong code shouldn't burn the same
     * attempt budget as a wrong password. Reuses Filament's own
     * `AppAuthentication` provider for the actual RFC 6238 verification and
     * recovery-code consumption, rather than a second hand-rolled
     * implementation of either.
     */
    public function challengeTwoFactor(User $user, string $code, string $codeFieldKey = 'code'): void
    {
        $lockoutKey = 'two-factor-lockout:'.$user->id;

        if (RateLimiter::tooManyAttempts($lockoutKey, self::MAX_ATTEMPTS)) {
            event(new Lockout(request()));

            $seconds = RateLimiter::availableIn($lockoutKey);

            throw ValidationException::withMessages([
                $codeFieldKey => trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => (int) ceil($seconds / 60),
                ]),
            ]);
        }

        $appAuthentication = app(AppAuthentication::class);

        $verified = $appAuthentication->verifyCode($code, $user->two_factor_secret, shouldPreventCodeReuse: true)
            || $appAuthentication->verifyRecoveryCode($code, $user);

        if (! $verified) {
            RateLimiter::hit($lockoutKey, self::LOCKOUT_SECONDS);

            throw ValidationException::withMessages([
                $codeFieldKey => 'The provided two-factor code is invalid.',
            ]);
        }

        RateLimiter::clear($lockoutKey);
    }

    private function ensureIsNotLockedOut(string $key, string $emailFieldKey): void
    {
        if (! RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            $emailFieldKey => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    private function lockoutKey(string $email, string $ip): string
    {
        return 'login-lockout:'.Str::lower($email).'|'.$ip;
    }
}
