<div>
    @if ($redeemed)
        <p>Your account is ready — redirecting you now.</p>
    @else
        <h1>Redeem your parent invite code</h1>

        <form wire:submit="redeem">
            <label>
                Invite code
                <input type="text" wire:model="code">
            </label>
            @error('code')
                <p>{{ $message }}</p>
            @enderror

            <label>
                Your name
                <input type="text" wire:model="name">
            </label>
            @error('name')
                <p>{{ $message }}</p>
            @enderror

            <label>
                Email
                <input type="email" wire:model="email">
            </label>
            @error('email')
                <p>{{ $message }}</p>
            @enderror

            <label>
                Phone (E.164, e.g. +919876543210)
                <input type="text" wire:model="phone">
            </label>
            @error('phone')
                <p>{{ $message }}</p>
            @enderror

            <label>
                Password
                <input type="password" wire:model="password">
            </label>
            @error('password')
                <p>{{ $message }}</p>
            @enderror

            <label>
                Relationship to student (optional)
                <input type="text" wire:model="relationship" placeholder="e.g. father, mother, guardian">
            </label>

            <button type="submit">Create account</button>
        </form>
    @endif
</div>
