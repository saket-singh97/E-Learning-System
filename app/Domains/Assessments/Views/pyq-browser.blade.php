<div>
    <h2 class="lms-heading">Previous Year Questions — {{ $course->title }}</h2>

    <form>
        <div class="lms-field">
            <label class="lms-field-label" for="pyq-concept">Topic</label>
            <input class="lms-input" id="pyq-concept" type="text" wire:model.live="concept" placeholder="Topic">
        </div>
        <div class="lms-field">
            <label class="lms-field-label" for="pyq-exam-name">Exam name</label>
            <input class="lms-input" id="pyq-exam-name" type="text" wire:model.live="examName" placeholder="Exam name">
        </div>
        <div class="lms-field">
            <label class="lms-field-label" for="pyq-exam-year">Exam year</label>
            <input class="lms-input" id="pyq-exam-year" type="number" wire:model.live="examYear" placeholder="Exam year">
        </div>
    </form>

    @if ($questions->isEmpty())
        <p class="lms-meta">No previous-year questions match these filters yet.</p>
    @else
        <div class="lms-panel">
            @foreach ($questions as $question)
                <div class="lms-list-item" wire:key="pyq-{{ $question->id }}">
                    <p>{{ $question->stem }}</p>
                    <p class="lms-meta">
                        {{ $question->concept }}
                        @if ($question->pyq_exam_name || $question->pyq_exam_year)
                            — {{ $question->pyq_exam_name }} {{ $question->pyq_exam_year }}
                        @endif
                    </p>
                </div>
            @endforeach
        </div>
    @endif
</div>
