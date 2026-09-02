<div>
    <h1 class="lms-heading">{{ $quiz->title }}</h1>
    <p class="lms-subtext">Time limit: {{ $quiz->time_limit_minutes }} minutes &middot; Attempts remaining: {{ $attemptsRemaining }}</p>

    @error('attempt')
        <p class="lms-error">{{ $message }}</p>
    @enderror

    @if ($attempt !== null)
        <div class="lms-quiz-timer">
            <span>Attempt in progress</span>
            <span>Expires {{ $attempt->expires_at->diffForHumans() }}</span>
        </div>

        <script>
            // S16 B10 (I8 pt.2) — quizElapsedSeconds(questionId) reports the
            // delta since the *last* report for that question (not a
            // running total; QuizAttemptService::saveAnswer() itself
            // accumulates), checkpointed from attempt-render time on first
            // call. Guarded the same way as the proctoring listeners below
            // against Livewire re-attaching this on every re-render.
            if (! window.__quizTimingInitialised) {
                window.__quizTimingInitialised = true;
                window.__quizQuestionCheckpoints = {};
                window.__quizAttemptRenderedAt = Date.now();

                window.quizElapsedSeconds = function (questionId) {
                    const now = Date.now();
                    const last = window.__quizQuestionCheckpoints[questionId] ?? window.__quizAttemptRenderedAt;
                    window.__quizQuestionCheckpoints[questionId] = now;

                    return Math.max(0, Math.round((now - last) / 1000));
                };
            }
        </script>

        @if ($quiz->proctoring_enabled)
            {{-- S16 B9 (I8 pt.1) — detection/evidence only, no lockdown.
            Page Visibility API for tab-switch, Fullscreen API for
            fullscreen-exit, plain copy/paste DOM events — each calls the
            component's own logProctoringEvent(), which re-verifies
            proctoring_enabled and an active attempt server-side before
            writing anything. --}}
            <script>
                // Guarded against Livewire re-rendering this block on every
                // saveAnswer/submit round-trip — without this, each
                // re-render would attach a second, third... copy of every
                // listener, multiplying one real tab-switch into several
                // logged events.
                if (! window.__quizProctoringListenersAttached) {
                    window.__quizProctoringListenersAttached = true;

                    document.addEventListener('visibilitychange', () => {
                        if (document.hidden) {
                            @this.call('logProctoringEvent', 'tab_switch');
                        }
                    });

                    document.addEventListener('fullscreenchange', () => {
                        if (! document.fullscreenElement) {
                            @this.call('logProctoringEvent', 'fullscreen_exit');
                        }
                    });

                    document.addEventListener('copy', () => {
                        @this.call('logProctoringEvent', 'copy');
                    });

                    document.addEventListener('paste', () => {
                        @this.call('logProctoringEvent', 'paste');
                    });
                }
            </script>
        @endif

        {{-- Question navigator — jump links to each question's own anchor.
        No "current question" modifier: this component renders every
        question on one page (not paginated/wizard-stepped), so there is no
        current-question state property to key it off — only --answered,
        derived from the actual responses array, is applied here. --}}
        <nav class="lms-quiz-nav">
            @foreach ($questions as $index => $question)
                <a
                    href="#question-{{ $question->id }}"
                    class="lms-quiz-nav-item @if (! empty($responses[$question->id] ?? null)) lms-quiz-nav-item--answered @endif"
                >{{ $index + 1 }}</a>
            @endforeach
        </nav>

        <ol style="list-style:none;padding:0;margin:0;">
            @foreach ($questions as $question)
                <li wire:key="question-{{ $question->id }}" id="question-{{ $question->id }}" class="lms-panel">
                    <p>{{ $question->stem }}</p>

                    @if ($question->type === 'mcq' || $question->type === 'true_false')
                        @foreach ($question->options as $option)
                            <div class="lms-list-item">
                                <label>
                                    <input
                                        type="radio"
                                        wire:model="responses.{{ $question->id }}"
                                        wire:change="saveAnswer({{ $question->id }}, quizElapsedSeconds({{ $question->id }}))"
                                        value="{{ $option }}"
                                    >
                                    {{ $option }}
                                </label>
                            </div>
                        @endforeach
                    @elseif ($question->type === 'short_answer')
                        <textarea
                            class="lms-input"
                            wire:model="responses.{{ $question->id }}"
                            wire:blur="saveAnswer({{ $question->id }}, quizElapsedSeconds({{ $question->id }}))"
                        ></textarea>
                    @elseif ($question->type === 'numerical')
                        <input
                            class="lms-input"
                            type="number"
                            step="any"
                            wire:model="responses.{{ $question->id }}"
                            wire:blur="saveAnswer({{ $question->id }}, quizElapsedSeconds({{ $question->id }}))"
                        >
                    @elseif ($question->type === 'multi_select')
                        @foreach ($question->options as $option)
                            <div class="lms-list-item">
                                <label>
                                    <input
                                        type="checkbox"
                                        wire:model="responses.{{ $question->id }}"
                                        wire:change="saveAnswer({{ $question->id }}, quizElapsedSeconds({{ $question->id }}))"
                                        value="{{ $option }}"
                                    >
                                    {{ $option }}
                                </label>
                            </div>
                        @endforeach
                    @elseif ($question->type === 'fill_in_blank')
                        <input
                            class="lms-input"
                            type="text"
                            wire:model="responses.{{ $question->id }}"
                            wire:blur="saveAnswer({{ $question->id }}, quizElapsedSeconds({{ $question->id }}))"
                        >
                    @elseif ($question->type === 'matching')
                        @foreach ($question->options['left'] ?? [] as $leftIndex => $leftLabel)
                            <div class="lms-list-item" wire:key="match-{{ $question->id }}-{{ $leftIndex }}">
                                <span>{{ $leftLabel }}</span>
                                <select
                                    class="lms-input"
                                    wire:model="responses.{{ $question->id }}.{{ $leftIndex }}"
                                    wire:change="saveAnswer({{ $question->id }}, quizElapsedSeconds({{ $question->id }}))"
                                >
                                    <option value="">— match —</option>
                                    @foreach ($question->options['right'] ?? [] as $rightIndex => $rightLabel)
                                        <option value="{{ $rightIndex }}">{{ $rightLabel }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    @elseif ($question->type === 'ordering')
                        @for ($position = 0; $position < count($question->options ?? []); $position++)
                            <div class="lms-list-item" wire:key="order-{{ $question->id }}-{{ $position }}">
                                <label class="lms-field-label">Position {{ $position + 1 }}</label>
                                <select
                                    class="lms-input"
                                    wire:model="responses.{{ $question->id }}.{{ $position }}"
                                    wire:change="saveAnswer({{ $question->id }}, quizElapsedSeconds({{ $question->id }}))"
                                >
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

        <button type="button" class="lms-button" style="width:auto;" wire:click="submitAttempt">Submit</button>
    @else
        @if ($results !== null)
            <h2 class="lms-heading">Your last result</h2>
            <div class="lms-panel">
                <p>
                    Score: {{ (float) ($lastCompletedAttempt->score ?? 0) }} / {{ (float) $lastCompletedAttempt->max_score }}
                    @if ($results->contains(fn ($row) => $row['answer']->status === 'pending_review'))
                        &mdash; some short answers are still pending review
                    @endif
                </p>
            </div>

            <ol style="list-style:none;padding:0;margin:0;">
                @foreach ($results as $row)
                    <li class="lms-list-item" wire:key="result-{{ $row['answer']->id }}">
                        <p>{{ $row['question']?->stem }}</p>
                        <p class="lms-meta">Your answer: {{ is_array($row['answer']->response) ? implode(', ', $row['answer']->response) : ($row['answer']->response ?? '(no answer)') }}</p>

                        @if ($row['answer']->status === 'pending_review')
                            <p class="lms-meta"><em>Pending review</em></p>
                        @else
                            {{-- Score shown directly rather than a
                            Correct/Incorrect label — a reviewer-assigned
                            partial score (e.g. 0.5) would otherwise render
                            as a flat "Correct", overstating a half-credit
                            short answer the same as a fully-correct MCQ. --}}
                            <p>Score: {{ (float) $row['answer']->score }} / 1</p>

                            @if ($row['question']?->explanation)
                                <p class="lms-meta">{{ $row['question']->explanation }}</p>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($attemptsRemaining > 0)
            <button type="button" class="lms-button" style="width:auto;" wire:click="startAttempt">Start attempt</button>
        @else
            <p class="lms-meta">No attempts remaining for this quiz.</p>
        @endif

        @if ($attemptHistory->isNotEmpty())
            <h2 class="lms-heading">Your attempts</h2>
            <ol style="list-style:none;padding:0;margin:0;">
                @foreach ($attemptHistory as $index => $historyAttempt)
                    <li class="lms-list-item" wire:key="history-{{ $historyAttempt->id }}">
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
