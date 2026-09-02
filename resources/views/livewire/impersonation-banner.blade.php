<div>
    @if ($impersonatedUser)
        <div class="lms-impersonation-bar">
            <span>Viewing as {{ $impersonatedUser->name }} (impersonating).</span>
            <button type="button" class="lms-link" style="margin-top:0;" wire:click="stop">Stop impersonating</button>
        </div>
    @endif
</div>
