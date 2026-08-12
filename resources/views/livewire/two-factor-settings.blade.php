<div>
    <h1>Two-factor authentication</h1>

    @if ($justEnabled)
        <p>Two-factor authentication is now enabled. Save these recovery codes — each one can be used once if you lose access to your authenticator app.</p>
        <ul>
            @foreach ($recoveryCodes as $code)
                <li>{{ $code }}</li>
            @endforeach
        </ul>
    @endif

    @if (auth()->user()->hasTwoFactorEnabled())
        <p>Two-factor authentication is enabled on your account.</p>

        <form wire:submit="disable">
            <label>
                Enter a code to disable two-factor authentication
                <input type="text" wire:model="disableCode" autocomplete="one-time-code">
            </label>
            @error('disableCode')
                <p>{{ $message }}</p>
            @enderror

            <button type="submit">Disable</button>
        </form>
    @else
        <p>Scan this QR code with your authenticator app, then enter the 6-digit code it shows to finish enrolling.</p>

        <img src="{{ $qrCodeDataUri }}" alt="Two-factor authentication QR code">

        <p>Or enter this code manually: <code>{{ $secret }}</code></p>

        <form wire:submit="confirm">
            <label>
                Confirmation code
                <input type="text" wire:model="confirmationCode" autocomplete="one-time-code">
            </label>
            @error('confirmationCode')
                <p>{{ $message }}</p>
            @enderror

            <button type="submit">Enable</button>
        </form>
    @endif
</div>
