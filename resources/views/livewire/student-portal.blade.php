<div style="max-width:24rem;">
    @auth
        <h1 class="lms-heading">Welcome back, {{ auth()->user()->name }}</h1>
        <p class="lms-subtext">Pick up where you left off.</p>

        <a class="lms-button lms-button-secondary" style="width:auto;" href="{{ route('student.batches', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain]) }}">Browse batches</a>

        <button type="button" class="lms-link" wire:click="logout">Log out</button>
    @elseif ($twoFactorUserId)
        <h1 class="lms-heading">Enter your code</h1>
        <p class="lms-subtext">Check your authenticator app for the 6-digit code.</p>

        <form wire:submit="verifyTwoFactorCode">
            <label class="lms-field">
                <span class="lms-field-label">Two-factor code</span>
                <input class="lms-input" type="text" wire:model="twoFactorCode" autocomplete="one-time-code">
            </label>
            @error('twoFactorCode')
                <p class="lms-error">{{ $message }}</p>
            @enderror

            <button type="submit" class="lms-button">Verify</button>
        </form>
    @else
        <h1 class="lms-heading">Log in</h1>
        <p class="lms-subtext">Use your student account to see your courses.</p>

        <form wire:submit="login">
            <label class="lms-field">
                <span class="lms-field-label">Email</span>
                <input class="lms-input" type="email" wire:model="email">
            </label>
            @error('email')
                <p class="lms-error">{{ $message }}</p>
            @enderror

            <label class="lms-field">
                <span class="lms-field-label">Password</span>
                <input class="lms-input" type="password" wire:model="password">
            </label>

            <button type="submit" class="lms-button">Log in</button>
        </form>

        <hr class="lms-divider">

        <a class="lms-button lms-button-secondary" href="{{ route('auth.google.redirect', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'portal' => 'student']) }}">Sign in with Google</a>
    @endauth
</div>
