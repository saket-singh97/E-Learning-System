<div>
    @if ($shouldShow)
        <div>
            This site uses cookies for necessary site functionality, and optionally for analytics and marketing.
            <button type="button" wire:click="acceptAll">Accept all</button>
            <button type="button" wire:click="acceptNecessaryOnly">Necessary only</button>
        </div>
    @endif
</div>
