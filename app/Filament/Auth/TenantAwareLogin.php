<?php

namespace App\Filament\Auth;

use App\Domains\Tenancy\Services\TenantAuthenticator;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\MultiFactor\Contracts\HasBeforeChallengeHook;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Shared by all three panel Login pages (Admin, Teach, Super) — the only
 * difference between them is which tenant id credentials get checked
 * against, via resolveTenantId().
 *
 * Filament's own base Login::authenticate() does its own credential check
 * (Auth::validate-equivalent, wrapped in a Timebox) and its own multi-factor
 * challenge bookkeeping in one method. This override keeps Filament's own
 * MFA bookkeeping (the $userUndertakingMultiFactorAuthentication property,
 * the two-schema toggle, isMultiFactorChallengeRateLimited()) — that part is
 * unrelated to tenancy and there is no reason to reimplement well-tested
 * framework code — but replaces the credential check itself with
 * TenantAuthenticator::validate(), the same tenant-scoped, rate-limited
 * lookup every other login surface in this app uses (S2 B0's own reasoning,
 * now extended to cover the two-step MFA case S9 B1 added).
 *
 * Deliberately does NOT log the user in until any enabled multi-factor
 * provider has also passed — TenantAuthenticator::validate() only checks the
 * password, so a still-pending MFA challenge never leaves a session behind.
 */
abstract class TenantAwareLogin extends BaseLogin
{
    abstract protected function resolveTenantId(): ?int;

    public function authenticate(): ?LoginResponse
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return $this->completeMultiFactorChallenge();
        }

        $data = $this->form->getState();

        $user = app(TenantAuthenticator::class)->validate(
            email: $data['email'],
            password: $data['password'],
            tenantId: $this->resolveTenantId(),
            ip: request()->ip(),
            emailFieldKey: 'data.email',
        );

        if (! $this->isUserAllowedToAccessPanel($user)) {
            $this->throwFailureValidationException();
        }

        foreach (Filament::getMultiFactorAuthenticationProviders() as $multiFactorAuthenticationProvider) {
            if (! $multiFactorAuthenticationProvider->isEnabled($user)) {
                continue;
            }

            $this->userUndertakingMultiFactorAuthentication = encrypt($user->getAuthIdentifier());

            if ($multiFactorAuthenticationProvider instanceof HasBeforeChallengeHook) {
                $multiFactorAuthenticationProvider->beforeChallenge($user);
            }

            break;
        }

        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            $this->multiFactorChallengeForm->fill();

            return null;
        }

        return $this->completeLogin($user, (bool) ($data['remember'] ?? false));
    }

    protected function completeMultiFactorChallenge(): ?LoginResponse
    {
        $authProvider = Filament::auth()->getProvider(); /** @phpstan-ignore-line */
        $user = $authProvider->retrieveById(decrypt($this->userUndertakingMultiFactorAuthentication));

        if ($this->isMultiFactorChallengeRateLimited($user)) {
            return null;
        }

        $this->multiFactorChallengeForm->validate();

        return $this->completeLogin($user, (bool) ($this->data['remember'] ?? false));
    }

    protected function completeLogin(Authenticatable $user, bool $remember): LoginResponse
    {
        Filament::auth()->login($user, $remember);

        session()->regenerate();

        return app(LoginResponse::class);
    }
}
