<div class="lms-panel">
    <h1 class="lms-field-label">Two-factor authentication</h1>

    @if ($justEnabled)
        <p>Two-factor authentication is now enabled. Save these recovery codes — each one can be used once if you lose access to your authenticator app.</p>
        <ul>
            @foreach ($recoveryCodes as $code)
                <li class="lms-list-item">{{ $code }}</li>
            @endforeach
        </ul>
    @endif

    @if (auth()->user()->hasTwoFactorEnabled())
        <p class="lms-meta">Two-factor authentication is enabled on your account.</p>

        <form wire:submit="disable">
            <label class="lms-field">
                <span class="lms-field-label">Enter a code to disable two-factor authentication</span>
                <input class="lms-input" type="text" wire:model="disableCode" autocomplete="one-time-code">
            </label>
            @error('disableCode')
                <p class="lms-error">{{ $message }}</p>
            @enderror

            <button type="submit" class="lms-button lms-button-secondary" style="width:auto;">Disable</button>
        </form>
    @else
        <p class="lms-subtext">Scan this QR code with your authenticator app, then enter the 6-digit code it shows to finish enrolling.</p>

        <img src="{{ $qrCodeDataUri }}" alt="Two-factor authentication QR code" style="border:1px solid var(--color-line); border-radius:0.25rem; margin-bottom:0.75rem;">

        <p class="lms-meta">Or enter this code manually: <code>{{ $secret }}</code></p>

        <form wire:submit="confirm">
            <label class="lms-field">
                <span class="lms-field-label">Confirmation code</span>
                <input class="lms-input" type="text" wire:model="confirmationCode" autocomplete="one-time-code">
            </label>
            @error('confirmationCode')
                <p class="lms-error">{{ $message }}</p>
            @enderror

            <button type="submit" class="lms-button" style="width:auto;">Enable</button>
        </form>
    @endif
</div>
