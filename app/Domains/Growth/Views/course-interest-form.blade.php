<div>
    @if ($submitted)
        <p>Thanks — we'll let you know when this course opens.</p>
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
            <button type="submit">Notify me</button>
        </form>
    @endif
</div>
