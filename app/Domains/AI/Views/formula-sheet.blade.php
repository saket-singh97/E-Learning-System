<div class="lms-panel">
    <h3 class="lms-field-label">Revision card — {{ $section->title }}</h3>

    @if (empty($concepts))
        <p class="lms-meta">No revision content extracted for this chapter yet.</p>
    @else
        <ul>
            @foreach ($concepts as $concept)
                <li class="lms-list-item">{{ $concept }}</li>
            @endforeach
        </ul>
    @endif
</div>
