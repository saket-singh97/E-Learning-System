<div>
    @if ($submitted)
        @if (! $isNewRegistration)
            <p>You're already registered for this scholarship test.</p>
        @else
            <p>Thanks — you're registered for the scholarship test.</p>
            @if ($hallTicketDownloadUrl !== null)
                <p><a href="{{ $hallTicketDownloadUrl }}">Download your hall ticket</a></p>
            @endif

            @if ($answersSubmitted)
                <p>Your answers have been submitted. Thank you.</p>
            @elseif ($questions->isNotEmpty())
                <form wire:submit="submitAnswers">
                    <ol>
                        @foreach ($questions as $question)
                            <li wire:key="question-{{ $question->id }}">
                                <p>{{ $question->stem }}</p>

                                @if ($question->type === 'mcq' || $question->type === 'true_false')
                                    @foreach ($question->options as $option)
                                        <label>
                                            <input type="radio" wire:model="responses.{{ $question->id }}" value="{{ $option }}">
                                            {{ $option }}
                                        </label>
                                    @endforeach
                                @elseif ($question->type === 'numerical')
                                    <input type="number" step="any" wire:model="responses.{{ $question->id }}">
                                @elseif ($question->type === 'multi_select')
                                    @foreach ($question->options as $option)
                                        <label>
                                            <input type="checkbox" wire:model="responses.{{ $question->id }}" value="{{ $option }}">
                                            {{ $option }}
                                        </label>
                                    @endforeach
                                @elseif ($question->type === 'fill_in_blank')
                                    <input type="text" wire:model="responses.{{ $question->id }}">
                                @elseif ($question->type === 'matching')
                                    @foreach ($question->options['left'] ?? [] as $leftIndex => $leftLabel)
                                        <div wire:key="match-{{ $question->id }}-{{ $leftIndex }}">
                                            <span>{{ $leftLabel }}</span>
                                            <select wire:model="responses.{{ $question->id }}.{{ $leftIndex }}">
                                                <option value="">— match —</option>
                                                @foreach ($question->options['right'] ?? [] as $rightIndex => $rightLabel)
                                                    <option value="{{ $rightIndex }}">{{ $rightLabel }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endforeach
                                @elseif ($question->type === 'ordering')
                                    @for ($position = 0; $position < count($question->options ?? []); $position++)
                                        <div wire:key="order-{{ $question->id }}-{{ $position }}">
                                            <label>Position {{ $position + 1 }}</label>
                                            <select wire:model="responses.{{ $question->id }}.{{ $position }}">
                                                <option value="">— pick —</option>
                                                @foreach ($question->options as $itemIndex => $itemLabel)
                                                    <option value="{{ $itemIndex }}">{{ $itemLabel }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endfor
                                @endif
                            </li>
                        @endforeach
                    </ol>
                    <button type="submit">Submit answers</button>
                </form>
            @elseif (! $withinWindow)
                <p>This test is not currently open for answers.</p>
            @else
                <p>No questions are configured for this test yet.</p>
            @endif
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
