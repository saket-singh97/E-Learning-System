<div class="semantic-search">
    <h3>Search course content</h3>

    @auth
        <input type="search" wire:model.live.debounce.400ms="query" placeholder="Search your courses...">

        @if (trim($query) !== '')
            <ul>
                @forelse ($results as $chunk)
                    <li>
                        <a href="{{ $this->watchUrlFor($chunk) }}">
                            {{ $chunk->lessonArtifact->lesson->title }} ({{ $chunk->start_s }}s)
                        </a>
                        <p>{{ $chunk->text }}</p>
                    </li>
                @empty
                    <li>No matching lessons found.</li>
                @endforelse
            </ul>
        @endif
    @else
        <p>Log in to search your courses.</p>
    @endauth
</div>
