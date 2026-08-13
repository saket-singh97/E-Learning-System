<?php

namespace App\Livewire;

use App\Domains\Tenancy\Services\TenantAuthenticator;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class StudentPortal extends Component
{
    public string $email = '';

    public string $password = '';

    /**
     * Set once the password step passes for a user with 2FA enabled — the
     * component swaps to the code-entry form instead of redirecting
     * anywhere (Livewire keeps this public property between the two
     * requests for free), mirroring Filament's own login page's two-schema
     * toggle rather than round-tripping through a separate page/route.
     */
    public ?int $twoFactorUserId = null;

    public string $twoFactorCode = '';

    /**
     * SESSION_DOMAIN is a shared parent domain (CLAUDE.md), so a session
     * cookie from tenant A's student login stays valid on tenant B's
     * subdomain — the same gap EnsureRole/DownloadInvoiceController close
     * by hand for their own surfaces, and ParentPortal::mount() already
     * closes for the parent portal (S8 B1). This portal never had the
     * check at all (S9 B0).
     */
    public function mount(): void
    {
        if (Auth::check() && Auth::user()->tenant_id !== app(CurrentTenant::class)->id()) {
            Auth::logout();
            session()->invalidate();
            session()->regenerateToken();
        }

        $this->resumePendingTwoFactorChallenge();
    }

    /**
     * GoogleAuthController::callback() (S9 B2) hands off here rather than
     * logging the user in itself once TOTP is enabled — this component
     * already has the code-entry step built (verifyTwoFactorCode() below),
     * found missing entirely from the Google path by the S9 B14 review
     * pass. pull(), not get(): a stale flag from an abandoned OAuth
     * attempt must not silently seed a challenge for a later, unrelated
     * page load.
     */
    protected function resumePendingTwoFactorChallenge(): void
    {
        $pendingUserId = session()->pull('oauth.pending_two_factor_user_id');

        if ($pendingUserId === null) {
            return;
        }

        $user = User::find($pendingUserId);

        if ($user !== null && $user->tenant_id === app(CurrentTenant::class)->id()) {
            $this->twoFactorUserId = $user->id;
        }
    }

    /**
     * Was a raw Auth::attempt() with no lockout — the only login surface in
     * the app without TenantAuthenticator's rate limiting, leaving student
     * accounts open to unlimited password guessing. Routed through the same
     * service every other login path uses instead of re-implementing it.
     */
    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        try {
            $user = app(TenantAuthenticator::class)->validate(
                email: $this->email,
                password: $this->password,
                tenantId: app(CurrentTenant::class)->id(),
                ip: request()->ip(),
                emailFieldKey: 'email',
            );
        } catch (ValidationException $e) {
            $this->addError('email', $e->validator->errors()->first('email'));

            return;
        }

        if ($user->hasTwoFactorEnabled()) {
            $this->twoFactorUserId = $user->id;
            $this->reset('password');

            return;
        }

        Auth::login($user);

        session()->regenerate();

        $this->reset(['email', 'password']);
    }

    public function verifyTwoFactorCode(): void
    {
        $this->validate(['twoFactorCode' => ['required', 'string']]);

        $user = User::findOrFail($this->twoFactorUserId);

        try {
            app(TenantAuthenticator::class)->challengeTwoFactor($user, $this->twoFactorCode, 'twoFactorCode');
        } catch (ValidationException $e) {
            $this->addError('twoFactorCode', $e->validator->errors()->first('twoFactorCode'));

            return;
        }

        Auth::login($user);

        session()->regenerate();

        $this->reset(['email', 'password', 'twoFactorUserId', 'twoFactorCode']);
    }

    public function logout(): void
    {
        Auth::logout();

        session()->invalidate();
        session()->regenerateToken();
    }

    public function render(): View
    {
        return view('livewire.student-portal');
    }
}
