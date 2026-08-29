<div class="formula-sheet">
    <h3>Revision card — {{ $section->title }}</h3>

    @if (empty($concepts))
        <p>No revision content extracted for this chapter yet.</p>
    @else
        <ul>
            @foreach ($concepts as $concept)
                <li>{{ $concept }}</li>
            @endforeach
        </ul>
    @endif
</div>
