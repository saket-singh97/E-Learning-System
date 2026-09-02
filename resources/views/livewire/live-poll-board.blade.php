<div class="lms-panel">
    @if ($poll !== null && $poll->status !== 'draft')
        {{-- No broadcasting infrastructure exists yet — wire:poll is the
             pragmatic live-refresh mechanism (design doc decision 11, same
             S9 B8 FeeCheckout precedent). Only polls while results can
             still change; a closed poll's numbers are final. --}}
        <div @if ($poll->status === 'open') wire:poll.2s @endif>
            <p class="lms-field-label">{{ $poll->question }} <span class="lms-badge lms-badge--muted">{{ $poll->status }}</span></p>
            <ul>
                @foreach ($poll->options as $option)
                    @php($count = $option->responses->count())
                    @php($pct = $totalResponses > 0 ? round($count / $totalResponses * 100) : 0)
                    <li class="lms-list-item" wire:key="result-option-{{ $option->id }}">
                        <div style="display:flex;justify-content:space-between;margin-bottom:0.375rem">
                            <span>{{ $option->label }}</span>
                            <span class="lms-meta">{{ $count }} ({{ $pct }}%)</span>
                        </div>
                        <div class="lms-bar"><div class="lms-bar-fill" style="width: {{ $pct }}%"></div></div>
                    </li>
                @endforeach
            </ul>
            <p class="lms-meta">{{ $totalResponses }} response(s)</p>
        </div>

        @if ($isStaff && $poll->status === 'open')
            <button type="button" class="lms-button lms-button-secondary" style="width:auto" wire:click="closePoll({{ $poll->id }})">Close poll</button>
        @endif

        @if (! $isStaff && $poll->status === 'open')
            <livewire:live-poll-response :session="$session" :key="'live-poll-response-'.$poll->id" />
        @endif
    @endif

    @if ($isStaff)
        @if ($poll !== null && $poll->status === 'draft')
            <p class="lms-field-label">{{ $poll->question }} <span class="lms-badge lms-badge--muted">draft</span></p>
            <button type="button" class="lms-button" style="width:auto" wire:click="openPoll({{ $poll->id }})">Open poll</button>
        @elseif ($poll === null || $poll->status === 'closed')
            <form wire:submit.prevent="createPoll">
                <label class="lms-field">
                    <span class="lms-field-label">Poll question</span>
                    <input class="lms-input" type="text" wire:model="question" placeholder="Poll question">
                </label>

                @foreach ($optionInputs as $index => $label)
                    <label class="lms-field">
                        <span class="lms-field-label">Option {{ $index + 1 }}</span>
                        <div style="display:flex;gap:0.5rem">
                            <input class="lms-input" type="text" wire:model="optionInputs.{{ $index }}" placeholder="Option {{ $index + 1 }}">
                            @if (count($optionInputs) > 2)
                                <button type="button" class="lms-button lms-button-secondary" style="width:auto" wire:click="removeOption({{ $index }})">Remove</button>
                            @endif
                        </div>
                    </label>
                @endforeach

                <button type="button" class="lms-button lms-button-secondary" style="width:auto" wire:click="addOption">Add option</button>
                <button type="submit" class="lms-button" style="width:auto">Launch poll</button>
            </form>
        @endif
    @endif
</div>
