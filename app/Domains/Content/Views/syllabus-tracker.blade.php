@if (empty($tracker))
    <p class="lms-meta">Syllabus not tracked yet.</p>
@else
    <div style="margin-bottom:1.5rem">
        @foreach ($tracker as $boardOrExam => $percent)
            <div style="margin-bottom:0.75rem">
                <p class="lms-field-label">{{ $boardOrExam }} &mdash; {{ number_format($percent, 1) }}% complete</p>
                <div class="lms-bar"><div class="lms-bar-fill" style="width: {{ $percent }}%"></div></div>
            </div>
        @endforeach
    </div>
@endif
