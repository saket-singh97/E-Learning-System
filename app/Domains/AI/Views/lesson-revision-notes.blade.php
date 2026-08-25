<div class="lesson-revision-notes">
    <h3>Revision notes</h3>

    @if ($note)
        <ul>
            @foreach ($note->notes as $point)
                <li>{{ $point }}</li>
            @endforeach
        </ul>
    @else
        <p>No revision notes yet for this lesson.</p>
    @endif
</div>
