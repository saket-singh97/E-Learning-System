<div>
    @auth
        <h1>Welcome back, {{ auth()->user()->name }}</h1>

        <a href="{{ route('student.batches', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain]) }}">Browse batches</a>

        <button type="button" wire:click="logout">Log out</button>
    @elseif ($twoFactorUserId)
        <form wire:submit="verifyTwoFactorCode">
            <label>
                Two-factor code
                <input type="text" wire:model="twoFactorCode" autocomplete="one-time-code">
            </label>
            @error('twoFactorCode')
                <p>{{ $message }}</p>
            @enderror

            <button type="submit">Verify</button>
        </form>
    @else
        <form wire:submit="login">
            <label>
                Email
                <input type="email" wire:model="email">
            </label>
            @error('email')
                <p>{{ $message }}</p>
            @enderror

            <label>
                Password
                <input type="password" wire:model="password">
            </label>

            <button type="submit">Log in</button>
        </form>

        <a href="{{ route('auth.google.redirect', ['subdomain' => app(\App\Support\Tenancy\CurrentTenant::class)->get()->subdomain, 'portal' => 'student']) }}">Sign in with Google</a>
    @endauth
</div>
