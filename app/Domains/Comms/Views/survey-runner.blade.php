<div>
    <h2>Surveys</h2>

    @if (! $canRespond)
        <p>Log in to see available surveys.</p>
    @elseif ($surveyId === null)
        <ul>
            @forelse ($surveys as $survey)
                <li>
                    <button type="button" wire:click="openSurvey({{ $survey->id }})">{{ $survey->title }}</button>
                </li>
            @empty
                <li>No surveys to answer right now.</li>
            @endforelse
        </ul>
    @else
        <button type="button" wire:click="closeSurvey">Back to surveys</button>

        <form wire:submit="submit">
            @foreach ($questions as $question)
                <div>
                    <label>{{ $question->prompt }}</label>

                    @if ($question->type === 'text')
                        <textarea wire:model="answers.{{ $question->id }}"></textarea>
                    @elseif ($question->type === 'single_choice')
                        @foreach ($question->options['choices'] ?? [] as $choice)
                            <label>
                                <input type="radio" wire:model="answers.{{ $question->id }}" value="{{ $choice }}">
                                {{ $choice }}
                            </label>
                        @endforeach
                    @elseif ($question->type === 'multi_choice')
                        @foreach ($question->options['choices'] ?? [] as $choice)
                            <label>
                                <input type="checkbox" wire:model="answers.{{ $question->id }}" value="{{ $choice }}">
                                {{ $choice }}
                            </label>
                        @endforeach
                    @elseif ($question->type === 'rating')
                        <input type="number" wire:model="answers.{{ $question->id }}" min="1" max="{{ $question->options['max'] ?? 5 }}">
                    @endif

                    @error("answers.{$question->id}")
                        <p>{{ $message }}</p>
                    @enderror
                </div>
            @endforeach

            <button type="submit">Submit</button>
        </form>
    @endif
</div>
