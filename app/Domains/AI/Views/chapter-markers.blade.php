<div class="chapter-markers">
    <h3>Chapters</h3>

    @if (empty($markers))
        <p>No chapter markers yet for this lesson.</p>
    @else
        <ul>
            @foreach ($markers as $marker)
                <li>
                    <button
                        type="button"
                        x-data
                        x-on:click="$dispatch('seek-to', { seconds: {{ $marker['start_s'] }} })"
                    >{{ $marker['start_s'] }}s — {{ $marker['label'] }}</button>
                </li>
            @endforeach
        </ul>
    @endif
</div>
