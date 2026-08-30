<div>
    <h2>Previous Year Questions — {{ $course->title }}</h2>

    <form>
        <input type="text" wire:model.live="concept" placeholder="Topic">
        <input type="text" wire:model.live="examName" placeholder="Exam name">
        <input type="number" wire:model.live="examYear" placeholder="Exam year">
    </form>

    @if ($questions->isEmpty())
        <p>No previous-year questions match these filters yet.</p>
    @else
        <ol>
            @foreach ($questions as $question)
                <li wire:key="pyq-{{ $question->id }}">
                    <p>{{ $question->stem }}</p>
                    <small>
                        {{ $question->concept }}
                        @if ($question->pyq_exam_name || $question->pyq_exam_year)
                            — {{ $question->pyq_exam_name }} {{ $question->pyq_exam_year }}
                        @endif
                    </small>
                </li>
            @endforeach
        </ol>
    @endif
</div>
