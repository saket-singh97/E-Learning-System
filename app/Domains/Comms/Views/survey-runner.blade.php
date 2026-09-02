<div class="lms-panel">
    <h2 class="lms-field-label">Surveys</h2>

    @if (! $canRespond)
        <p class="lms-meta">Log in to see available surveys.</p>
    @elseif ($surveyId === null)
        <ul>
            @forelse ($surveys as $survey)
                <li class="lms-list-item">
                    <button type="button" class="lms-link" style="margin-top:0;" wire:click="openSurvey({{ $survey->id }})">{{ $survey->title }}</button>
                </li>
            @empty
                <li class="lms-meta">No surveys to answer right now.</li>
            @endforelse
        </ul>
    @else
        @php
            $totalQuestions = $questions->count();
            $answeredCount = $questions->filter(fn ($question) => filled($answers[$question->id] ?? null))->count();
            $completionPct = $totalQuestions > 0 ? (int) round($answeredCount / $totalQuestions * 100) : 0;
        @endphp

        <button type="button" class="lms-link" style="margin-top:0;" wire:click="closeSurvey">Back to surveys</button>

        <div style="margin:0.75rem 0 1.25rem;">
            <div class="lms-meta" style="display:flex;justify-content:space-between;">
                <span>{{ $answeredCount }} of {{ $totalQuestions }} answered</span>
                <span>{{ $completionPct }}%</span>
            </div>
            <div class="lms-bar" style="margin-top:0.375rem;">
                <div class="lms-bar-fill" style="width: {{ $completionPct }}%"></div>
            </div>
        </div>

        <form wire:submit="submit">
            @foreach ($questions as $question)
                <div class="lms-field">
                    <span class="lms-field-label">{{ $question->prompt }}</span>

                    @if ($question->type === 'text')
                        <textarea class="lms-input" wire:model="answers.{{ $question->id }}" rows="3"></textarea>
                    @elseif ($question->type === 'single_choice')
                        @foreach ($question->options['choices'] ?? [] as $choice)
                            <label style="display:block;font-size:0.9375rem;margin-top:0.375rem;">
                                <input type="radio" wire:model="answers.{{ $question->id }}" value="{{ $choice }}">
                                {{ $choice }}
                            </label>
                        @endforeach
                    @elseif ($question->type === 'multi_choice')
                        @foreach ($question->options['choices'] ?? [] as $choice)
                            <label style="display:block;font-size:0.9375rem;margin-top:0.375rem;">
                                <input type="checkbox" wire:model="answers.{{ $question->id }}" value="{{ $choice }}">
                                {{ $choice }}
                            </label>
                        @endforeach
                    @elseif ($question->type === 'rating')
                        <input class="lms-input" style="width:6rem;" type="number" wire:model="answers.{{ $question->id }}" min="1" max="{{ $question->options['max'] ?? 5 }}">
                    @endif

                    @error("answers.{$question->id}")
                        <p class="lms-error">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach

            <button type="submit" class="lms-button" style="width:auto;">Submit</button>
        </form>
    @endif
</div>
