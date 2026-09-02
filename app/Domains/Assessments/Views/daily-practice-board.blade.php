<div class="daily-practice-board">
    <h2 class="lms-heading">Today's practice — {{ $batch->title }}</h2>

    @if ($questions->isEmpty())
        <p class="lms-subtext">No practice questions yet — check back after your next class.</p>
    @else
        <div class="lms-panel">
            @foreach ($questions as $question)
                <div class="lms-list-item" x-data="{ show: false }">
                    <p>{{ $question->stem }}</p>

                    @if ($question->options)
                        <ul>
                            @foreach ($question->options as $option)
                                <li class="lms-meta">{{ $option }}</li>
                            @endforeach
                        </ul>
                    @endif

                    <button type="button" class="lms-button lms-button-secondary" style="width:auto;" x-on:click="show = !show" x-text="show ? 'Hide answer' : 'Show answer'"></button>

                    <div x-show="show" style="display: none; margin-top:0.75rem;">
                        <p>Answer: {{ is_array($question->correct_answer) ? json_encode($question->correct_answer) : $question->correct_answer }}</p>
                        @if ($question->explanation)
                            <p class="lms-meta">{{ $question->explanation }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
