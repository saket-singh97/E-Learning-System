<div>
    @if ($shouldShow)
        <div class="lms-cookie-bar">
            <span>This site uses cookies for necessary site functionality, and optionally for analytics and marketing.</span>
            <button type="button" class="lms-button" wire:click="acceptAll">Accept all</button>
            <button type="button" class="lms-button lms-button-secondary" wire:click="acceptNecessaryOnly">Necessary only</button>
        </div>
    @endif
</div>
