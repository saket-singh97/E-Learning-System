<div>
    @if ($poll !== null && $poll->status !== 'draft')
        {{-- No broadcasting infrastructure exists yet — wire:poll is the
             pragmatic live-refresh mechanism (design doc decision 11, same
             S9 B8 FeeCheckout precedent). Only polls while results can
             still change; a closed poll's numbers are final. --}}
        <div @if ($poll->status === 'open') wire:poll.2s @endif>
            <p>{{ $poll->question }} ({{ $poll->status }})</p>
            <ul>
                @foreach ($poll->options as $option)
                    @php($count = $option->responses->count())
                    <li wire:key="result-option-{{ $option->id }}">
                        {{ $option->label }} &mdash; {{ $count }}
                        ({{ $totalResponses > 0 ? round($count / $totalResponses * 100) : 0 }}%)
                    </li>
                @endforeach
            </ul>
            <p>{{ $totalResponses }} response(s)</p>
        </div>

        @if ($isStaff && $poll->status === 'open')
            <button type="button" wire:click="closePoll({{ $poll->id }})">Close poll</button>
        @endif

        @if (! $isStaff && $poll->status === 'open')
            <livewire:live-poll-response :session="$session" :key="'live-poll-response-'.$poll->id" />
        @endif
    @endif

    @if ($isStaff)
        @if ($poll !== null && $poll->status === 'draft')
            <p>{{ $poll->question }} (draft)</p>
            <button type="button" wire:click="openPoll({{ $poll->id }})">Open poll</button>
        @elseif ($poll === null || $poll->status === 'closed')
            <form wire:submit.prevent="createPoll">
                <input type="text" wire:model="question" placeholder="Poll question">

                @foreach ($optionInputs as $index => $label)
                    <input type="text" wire:model="optionInputs.{{ $index }}" placeholder="Option {{ $index + 1 }}">
                    @if (count($optionInputs) > 2)
                        <button type="button" wire:click="removeOption({{ $index }})">Remove</button>
                    @endif
                @endforeach

                <button type="button" wire:click="addOption">Add option</button>
                <button type="submit">Launch poll</button>
            </form>
        @endif
    @endif
</div>
