<div>
    <h1>{{ $quiz->title }}</h1>
    <p>Time limit: {{ $quiz->time_limit_minutes }} minutes &middot; Attempts remaining: {{ $attemptsRemaining }}</p>

    @error('attempt')
        <p><strong>{{ $message }}</strong></p>
    @enderror

    @if ($attempt !== null)
        <p>Attempt in progress &mdash; expires {{ $attempt->expires_at->diffForHumans() }}.</p>

        <ol>
            @foreach ($questions as $question)
                <li wire:key="question-{{ $question->id }}">
                    <p>{{ $question->stem }}</p>

                    @if ($question->type === 'mcq' || $question->type === 'true_false')
                        @foreach ($question->options as $option)
                            <label>
                                <input
                                    type="radio"
                                    wire:model="responses.{{ $question->id }}"
                                    wire:change="saveAnswer({{ $question->id }})"
                                    value="{{ $option }}"
                                >
                                {{ $option }}
                            </label>
                        @endforeach
                    @elseif ($question->type === 'short_answer')
                        <textarea
                            wire:model="responses.{{ $question->id }}"
                            wire:blur="saveAnswer({{ $question->id }})"
                        ></textarea>
                    @elseif ($question->type === 'numerical')
                        <input
                            type="number"
                            step="any"
                            wire:model="responses.{{ $question->id }}"
                            wire:blur="saveAnswer({{ $question->id }})"
                        >
                    @endif
                </li>
            @endforeach
        </ol>

        <button type="button" wire:click="submitAttempt">Submit</button>
    @else
        @if ($results !== null)
            <h2>Your last result</h2>
            <p>
                Score: {{ (float) ($lastCompletedAttempt->score ?? 0) }} / {{ (float) $lastCompletedAttempt->max_score }}
                @if ($results->contains(fn ($row) => $row['answer']->status === 'pending_review'))
                    &mdash; some short answers are still pending review
                @endif
            </p>

            <ol>
                @foreach ($results as $row)
                    <li wire:key="result-{{ $row['answer']->id }}">
                        <p>{{ $row['question']?->stem }}</p>
                        <p>Your answer: {{ $row['answer']->response ?? '(no answer)' }}</p>

                        @if ($row['answer']->status === 'pending_review')
                            <p><em>Pending review</em></p>
                        @else
                            {{-- Score shown directly rather than a
                            Correct/Incorrect label — a reviewer-assigned
                            partial score (e.g. 0.5) would otherwise render
                            as a flat "Correct", overstating a half-credit
                            short answer the same as a fully-correct MCQ. --}}
                            <p>Score: {{ (float) $row['answer']->score }} / 1</p>

                            @if ($row['question']?->explanation)
                                <p>{{ $row['question']->explanation }}</p>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($attemptsRemaining > 0)
            <button type="button" wire:click="startAttempt">Start attempt</button>
        @else
            <em>No attempts remaining for this quiz.</em>
        @endif

        @if ($attemptHistory->isNotEmpty())
            <h2>Your attempts</h2>
            <ol>
                @foreach ($attemptHistory as $index => $historyAttempt)
                    <li wire:key="history-{{ $historyAttempt->id }}">
                        Attempt {{ $index + 1 }} ({{ $historyAttempt->started_at->format('Y-m-d H:i') }}):
                        {{ (float) ($historyAttempt->score ?? 0) }} / {{ (float) $historyAttempt->max_score }}
                        @if ($historyAttempt->status === 'auto_submitted')
                            &mdash; auto-submitted (time expired)
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    @endif
</div>
