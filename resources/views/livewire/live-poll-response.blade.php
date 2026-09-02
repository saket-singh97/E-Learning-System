<div>
    @if ($poll === null)
        <p class="lms-meta"><em>No poll open right now</em></p>
    @elseif ($alreadyResponded)
        <p class="lms-meta"><em>Response recorded — waiting for results</em></p>
    @else
        <p class="lms-field-label">{{ $poll->question }}</p>
        <ul>
            @foreach ($poll->options as $option)
                <li class="lms-list-item" wire:key="poll-option-{{ $option->id }}">
                    <button type="button" class="lms-button lms-button-secondary" style="width:auto" wire:click="submit({{ $option->id }})">{{ $option->label }}</button>
                </li>
            @endforeach
        </ul>
    @endif
</div>
