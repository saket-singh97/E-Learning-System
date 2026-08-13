<div>
    @if ($impersonatedUser)
        Viewing as {{ $impersonatedUser->name }} (impersonating).
        <button type="button" wire:click="stop">Stop impersonating</button>
    @endif
</div>
