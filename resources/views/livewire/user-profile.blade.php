<div>
    @auth
        <div class="lms-panel">
            <h2 class="lms-field-label">My profile</h2>

            @if ($avatarUrl)
                <img src="{{ $avatarUrl }}" alt="{{ auth()->user()->name }}" width="48" height="48" style="border-radius:9999px; margin-bottom:0.75rem;">
            @endif

            <form wire:submit="updateProfile">
                <label class="lms-field">
                    <span class="lms-field-label">Avatar</span>
                    <input class="lms-input" type="file" wire:model="avatarUpload">
                </label>
                @error('avatarUpload')
                    <p class="lms-error">{{ $message }}</p>
                @enderror

                <label class="lms-field">
                    <span class="lms-field-label">Name</span>
                    <input class="lms-input" type="text" wire:model="name">
                </label>
                @error('name')
                    <p class="lms-error">{{ $message }}</p>
                @enderror

                <label class="lms-field">
                    <span class="lms-field-label">Timezone</span>
                    <input class="lms-input" type="text" wire:model="timezone" placeholder="Asia/Kolkata">
                </label>
                @error('timezone')
                    <p class="lms-error">{{ $message }}</p>
                @enderror

                <label class="lms-field">
                    <span class="lms-field-label">Locale</span>
                    <input class="lms-input" type="text" wire:model="locale" placeholder="en">
                </label>
                @error('locale')
                    <p class="lms-error">{{ $message }}</p>
                @enderror

                <button type="submit" class="lms-button lms-button-secondary" style="width:auto;">Save profile</button>
            </form>
        </div>

        <div class="lms-panel">
            <h3 class="lms-field-label">Change password</h3>
            <form wire:submit="changePassword">
                <label class="lms-field">
                    <span class="lms-field-label">Current password</span>
                    <input class="lms-input" type="password" wire:model="currentPassword">
                </label>
                @error('currentPassword')
                    <p class="lms-error">{{ $message }}</p>
                @enderror

                <label class="lms-field">
                    <span class="lms-field-label">New password</span>
                    <input class="lms-input" type="password" wire:model="newPassword">
                </label>
                @error('newPassword')
                    <p class="lms-error">{{ $message }}</p>
                @enderror

                <label class="lms-field">
                    <span class="lms-field-label">Confirm new password</span>
                    <input class="lms-input" type="password" wire:model="newPassword_confirmation">
                </label>

                <button type="submit" class="lms-button lms-button-secondary" style="width:auto;">Change password</button>
            </form>
        </div>

        @if ($showTwoFactor)
            <livewire:two-factor-settings />
        @endif
    @endauth
</div>
