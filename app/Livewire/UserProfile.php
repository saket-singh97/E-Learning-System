<?php

namespace App\Livewire;

use App\Exceptions\InvalidFileTypeException;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Shared across all five auth surfaces (S9 B9), same mounting shape
 * NotificationCentre (S8 B7) already established: the Filament render hook
 * in AppServiceProvider::boot() reaches all three panels with the one
 * existing registration (no second one added), and this component is
 * embedded directly in both portal dashboards, same as NotificationCentre/
 * ImpersonationBanner are.
 *
 * The 2FA enrollment entry point B1's own plan note named as a placeholder
 * for this block only renders outside a Filament panel — Filament panel
 * users already have a real one (its own built-in EditProfile page,
 * ->multiFactorAuthentication(), wired in S9 B1); rendering a second,
 * competing 2FA control in the topbar there would just be confusing.
 * Nests the existing TwoFactorSettings component rather than
 * re-implementing anything it already does.
 */
class UserProfile extends Component
{
    use WithFileUploads;

    /** @var array<int, string> */
    public const ALLOWED_AVATAR_EXTENSIONS = ['png', 'jpg', 'webp'];

    public string $name = '';

    public string $timezone = '';

    public string $locale = '';

    public $avatarUpload = null;

    public string $currentPassword = '';

    public string $newPassword = '';

    // Laravel's 'confirmed' rule looks for a literal "{field}_confirmation"
    // key, not a camelCase equivalent — matches this app's own existing
    // convention (RegisterUserRequest/ResetPasswordRequest both pair
    // 'password' => ['confirmed'] with a plain "password_confirmation" field).
    public string $newPassword_confirmation = '';

    /**
     * Mounted on both portal dashboards *before* login too (StudentPortal/
     * ParentPortal render their own login form on the same page) — guards
     * against a guest the same null-safe way NotificationCentre's own
     * render() does, rather than assuming an authenticated user the way a
     * Filament-only component safely could.
     *
     * timezone/locale fall back to the columns' own DB defaults rather
     * than trusting $user->timezone directly: a just-created, not-yet-
     * refreshed User instance (any "create a row, then Auth::login() that
     * same in-memory object" path — registration, Google OAuth, an invite
     * redemption) never had those columns explicitly set, and Eloquent
     * doesn't pull server-side column defaults back into the model after
     * an insert. Found by this component's own test using a plain
     * factory-created user, not a hypothetical.
     */
    public function mount(): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $this->name = $user->name;
        $this->timezone = $user->timezone ?? 'Asia/Kolkata';
        $this->locale = $user->locale ?? 'en';
    }

    public function updateProfile(): void
    {
        // A Livewire action is directly callable via /livewire/update
        // regardless of what the @auth-gated view shows (S4's own
        // established lesson: hiding a button in Blade is not
        // authorization) — guard the mutation itself, not just its UI.
        if (Auth::user() === null) {
            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'timezone'],
            // Only 'en' has any translations behind it today
            // (lang/en is the only locale directory in this app) — stored
            // as forward-looking infrastructure, not gated to an
            // allow-list of one, so this field isn't a dropdown with a
            // single meaningless option.
            'locale' => ['required', 'string', 'max:10'],
            'avatarUpload' => ['nullable', 'image', 'max:2048'],
        ]);

        $user = Auth::user();

        $user->update([
            'name' => $this->name,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
        ]);

        if ($this->avatarUpload !== null) {
            $this->storeAvatar($user);
        }

        $this->reset('avatarUpload');
    }

    public function changePassword(): void
    {
        if (Auth::user() === null) {
            return;
        }

        $this->validate([
            'currentPassword' => ['required', 'current_password'],
            'newPassword' => ['required', 'string', 'min:8', 'confirmed'],
        ], [], [
            'newPassword' => 'new password',
        ]);

        Auth::user()->update(['password' => $this->newPassword]);

        $this->reset(['currentPassword', 'newPassword', 'newPassword_confirmation']);
    }

    /**
     * Same disk/allow-list/exception shape BrandingService::storeUpload()
     * already established for tenant logos/favicons (S2) — 'public' disk,
     * since an avatar (like a logo) needs a directly viewable URL, not a
     * gated/signed one the way video/pdf lesson files do.
     */
    protected function storeAvatar(User $user): void
    {
        $extension = strtolower((string) $this->avatarUpload->extension());

        if (! in_array($extension, self::ALLOWED_AVATAR_EXTENSIONS, true)) {
            throw InvalidFileTypeException::forField('avatar', $extension, self::ALLOWED_AVATAR_EXTENSIONS);
        }

        $previousPath = $user->avatar_path;

        $path = $this->avatarUpload->storeAs("avatars/{$user->id}", "avatar.{$extension}", 'public');

        if ($previousPath !== null && $previousPath !== $path) {
            Storage::disk('public')->delete($previousPath);
        }

        $user->update(['avatar_path' => $path]);
    }

    public function avatarUrl(): ?string
    {
        $path = Auth::user()?->avatar_path;

        return $path ? Storage::disk('public')->url($path) : null;
    }

    public function render(): View
    {
        return view('livewire.user-profile', [
            'avatarUrl' => $this->avatarUrl(),
            // Filament's own EditProfile page already carries 2FA for a
            // panel user (S9 B1) — only the two non-Filament portals need
            // this component to also surface it.
            'showTwoFactor' => Filament::getCurrentPanel() === null,
        ]);
    }
}
