<div class="lms-panel">
    <h3 class="lms-field-label">Chapters</h3>

    @if (empty($markers))
        <p class="lms-meta">No chapter markers yet for this lesson.</p>
    @else
        <ul>
            @foreach ($markers as $marker)
                <li class="lms-list-item">
                    <button
                        type="button"
                        class="lms-link"
                        style="margin-top:0;"
                        x-data
                        x-on:click="$dispatch('seek-to', { seconds: {{ $marker['start_s'] }} })"
                    >{{ $marker['start_s'] }}s — {{ $marker['label'] }}</button>
                </li>
            @endforeach
        </ul>
    @endif
</div>
