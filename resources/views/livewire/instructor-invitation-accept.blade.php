<div>
    @if ($accepted)
        <p>Your account is ready — redirecting you now.</p>
    @else
        <h1>Accept your instructor invitation</h1>

        <form wire:submit="accept">
            @error('token')
                <p>{{ $message }}</p>
            @enderror

            @if ($userAlreadyExists)
                <p>An account already exists for this email — accept below to link it.</p>
            @else
                <label>
                    Your name
                    <input type="text" wire:model="name">
                </label>
                @error('name')
                    <p>{{ $message }}</p>
                @enderror

                <label>
                    Password
                    <input type="password" wire:model="password">
                </label>
                @error('password')
                    <p>{{ $message }}</p>
                @enderror
            @endif

            <button type="submit">Accept invitation</button>
        </form>
    @endif
</div>
