<div class="lms-panel">
    <h3 class="lms-field-label">Search course content</h3>

    @auth
        <input class="lms-input" type="search" wire:model.live.debounce.400ms="query" placeholder="Search your courses...">

        @if (trim($query) !== '')
            <ul>
                @forelse ($results as $chunk)
                    <li class="lms-list-item">
                        <a class="lms-link" style="margin-top:0" href="{{ $this->watchUrlFor($chunk) }}">
                            {{ $chunk->lessonArtifact->lesson->title }} ({{ $chunk->start_s }}s)
                        </a>
                        <p class="lms-meta">{{ $chunk->text }}</p>
                    </li>
                @empty
                    <li class="lms-meta">No matching lessons found.</li>
                @endforelse
            </ul>
        @endif
    @else
        <p class="lms-meta">Log in to search your courses.</p>
    @endauth
</div>
