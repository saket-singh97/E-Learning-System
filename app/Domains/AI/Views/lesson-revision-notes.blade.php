<div class="lms-panel">
    <h3 class="lms-field-label">Revision notes</h3>

    @if ($note)
        <ul>
            @foreach ($note->notes as $point)
                <li class="lms-list-item">{{ $point }}</li>
            @endforeach
        </ul>
    @else
        <p class="lms-meta">No revision notes yet for this lesson.</p>
    @endif
</div>
