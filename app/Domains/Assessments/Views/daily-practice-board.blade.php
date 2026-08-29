<div class="daily-practice-board">
    <h2>Today's practice — {{ $batch->title }}</h2>

    @if ($questions->isEmpty())
        <p>No practice questions yet — check back after your next class.</p>
    @else
        <ol>
            @foreach ($questions as $question)
                <li x-data="{ show: false }">
                    <p>{{ $question->stem }}</p>

                    @if ($question->options)
                        <ul>
                            @foreach ($question->options as $option)
                                <li>{{ $option }}</li>
                            @endforeach
                        </ul>
                    @endif

                    <button type="button" x-on:click="show = !show" x-text="show ? 'Hide answer' : 'Show answer'"></button>

                    <div x-show="show" style="display: none;">
                        <p>Answer: {{ is_array($question->correct_answer) ? json_encode($question->correct_answer) : $question->correct_answer }}</p>
                        @if ($question->explanation)
                            <p>{{ $question->explanation }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>
