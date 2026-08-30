@if (empty($tracker))
    <p>Syllabus not tracked yet.</p>
@else
    <dl>
        @foreach ($tracker as $boardOrExam => $percent)
            <dt>{{ $boardOrExam }}</dt>
            <dd>{{ number_format($percent, 1) }}% complete</dd>
        @endforeach
    </dl>
@endif
