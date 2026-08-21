<div>
    @if ($poll === null)
        <em>No poll open right now</em>
    @elseif ($alreadyResponded)
        <em>Response recorded — waiting for results</em>
    @else
        <p>{{ $poll->question }}</p>
        <ul>
            @foreach ($poll->options as $option)
                <li wire:key="poll-option-{{ $option->id }}">
                    <button type="button" wire:click="submit({{ $option->id }})">{{ $option->label }}</button>
                </li>
            @endforeach
        </ul>
    @endif
</div>
