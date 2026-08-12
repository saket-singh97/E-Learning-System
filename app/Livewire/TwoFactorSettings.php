<?php

namespace App\Livewire;

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Writer;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use PragmaRX\Google2FAQRCode\Google2FA;

/**
 * Stub settings surface for the two portal users (student, parent) — neither
 * is a Filament panel user, so neither gets the enrollment UI the three
 * staff panels now have for free via ->multiFactorAuthentication() +
 * ->profile() (Filament's own EditProfile page). B9 (a real shared profile
 * page, not yet built) absorbs this into itself later (S9 B1's own plan
 * note); until then this is the one place a student/parent can turn 2FA on
 * or off, reachable at /settings/two-factor.
 *
 * Reuses Filament's own AppAuthentication for every actual security
 * operation (secret generation, code verification, recovery-code
 * generation/hashing/consumption) — this component only adds the Blade UI
 * and the QR code image, so there is exactly one TOTP implementation in the
 * app, not a second one duplicating RFC 6238.
 */
class TwoFactorSettings extends Component
{
    public ?string $secret = null;

    public ?string $qrCodeDataUri = null;

    public string $confirmationCode = '';

    /** @var array<int, string> */
    public array $recoveryCodes = [];

    public bool $justEnabled = false;

    public string $disableCode = '';

    public function mount(): void
    {
        if (! Auth::user()->hasTwoFactorEnabled()) {
            $this->startEnrollment();
        }
    }

    protected function startEnrollment(): void
    {
        $this->secret = app(AppAuthentication::class)->generateSecret();
        $this->qrCodeDataUri = $this->buildQrCodeDataUri($this->secret);
    }

    protected function buildQrCodeDataUri(string $secret): string
    {
        $qrCode = app(Google2FA::class)->getQRCodeInline(
            config('app.name'),
            Auth::user()->email,
            $secret,
        );

        // Same imagick fallback Filament's own AppAuthentication::generateQrCodeDataUri()
        // applies — getQRCodeInline() returns a raw SVG string, not a data
        // URI, whenever imagick isn't loaded (the common case on this dev
        // box), so it needs wrapping before it's usable as an <img src>.
        if (class_exists(Writer::class) && class_exists(ImageRenderer::class) && (! extension_loaded('imagick'))) {
            $qrCode = 'data:image/svg+xml;base64,'.base64_encode($qrCode);
        }

        return $qrCode;
    }

    public function confirm(): void
    {
        $this->validate(['confirmationCode' => ['required', 'string']]);

        $appAuthentication = app(AppAuthentication::class);

        if (! $appAuthentication->verifyCode($this->confirmationCode, $this->secret)) {
            $this->addError('confirmationCode', 'That code is incorrect.');

            return;
        }

        $recoveryCodes = $appAuthentication->generateRecoveryCodes();

        $user = Auth::user();
        $appAuthentication->saveSecret($user, $this->secret);
        $appAuthentication->saveRecoveryCodes($user, $recoveryCodes);

        $this->recoveryCodes = $recoveryCodes;
        $this->justEnabled = true;
        $this->reset(['secret', 'qrCodeDataUri', 'confirmationCode']);
    }

    public function disable(): void
    {
        $this->validate(['disableCode' => ['required', 'string']]);

        $user = Auth::user();
        $appAuthentication = app(AppAuthentication::class);

        $verified = $appAuthentication->verifyCode($this->disableCode, $user->two_factor_secret)
            || $appAuthentication->verifyRecoveryCode($this->disableCode, $user);

        if (! $verified) {
            $this->addError('disableCode', 'That code is incorrect.');

            return;
        }

        $appAuthentication->saveSecret($user, null);
        $appAuthentication->saveRecoveryCodes($user, null);

        $this->reset(['disableCode', 'justEnabled', 'recoveryCodes']);
        $this->startEnrollment();
    }

    public function render(): View
    {
        return view('livewire.two-factor-settings');
    }
}
