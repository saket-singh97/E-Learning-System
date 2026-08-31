<div>
    @if ($submitted)
        <p>Thanks — you're registered for the scholarship test.</p>
        @if ($hallTicketDownloadUrl !== null)
            <p><a href="{{ $hallTicketDownloadUrl }}">Download your hall ticket</a></p>
        @endif
    @else
        <form wire:submit="submit">
            <input type="text" wire:model="name" placeholder="Your name">
            @error('name')
                <p>{{ $message }}</p>
            @enderror
            <input type="email" wire:model="email" placeholder="Email">
            @error('email')
                <p>{{ $message }}</p>
            @enderror
            <input type="text" wire:model="phone" placeholder="Phone (optional)">
            @error('phone')
                <p>{{ $message }}</p>
            @enderror
            <button type="submit">Register</button>
        </form>
    @endif
</div>
