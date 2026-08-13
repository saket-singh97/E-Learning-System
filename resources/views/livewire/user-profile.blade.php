<div>
    @auth
        <h2>My profile</h2>

        @if ($avatarUrl)
            <img src="{{ $avatarUrl }}" alt="{{ auth()->user()->name }}" width="48" height="48">
        @endif

        <form wire:submit="updateProfile">
            <label>
                Avatar
                <input type="file" wire:model="avatarUpload">
            </label>
            @error('avatarUpload')
                <p>{{ $message }}</p>
            @enderror

            <label>
                Name
                <input type="text" wire:model="name">
            </label>
            @error('name')
                <p>{{ $message }}</p>
            @enderror

            <label>
                Timezone
                <input type="text" wire:model="timezone" placeholder="Asia/Kolkata">
            </label>
            @error('timezone')
                <p>{{ $message }}</p>
            @enderror

            <label>
                Locale
                <input type="text" wire:model="locale" placeholder="en">
            </label>
            @error('locale')
                <p>{{ $message }}</p>
            @enderror

            <button type="submit">Save profile</button>
        </form>

        <h3>Change password</h3>
        <form wire:submit="changePassword">
            <label>
                Current password
                <input type="password" wire:model="currentPassword">
            </label>
            @error('currentPassword')
                <p>{{ $message }}</p>
            @enderror

            <label>
                New password
                <input type="password" wire:model="newPassword">
            </label>
            @error('newPassword')
                <p>{{ $message }}</p>
            @enderror

            <label>
                Confirm new password
                <input type="password" wire:model="newPassword_confirmation">
            </label>

            <button type="submit">Change password</button>
        </form>

        @if ($showTwoFactor)
            <livewire:two-factor-settings />
        @endif
    @endauth
</div>
