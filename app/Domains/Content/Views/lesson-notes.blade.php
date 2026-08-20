<div x-data="{ currentSeconds: 0 }" x-on:lesson-time-update.window="currentSeconds = $event.detail.seconds">
    <h2>My notes &amp; bookmarks</h2>

    @auth
        <button type="button" x-on:click="$wire.bookmark(currentSeconds)">Bookmark this moment</button>

        <form x-on:submit.prevent="$wire.addNote(currentSeconds)">
            <textarea wire:model="newNoteBody" placeholder="Add a note at this moment"></textarea>
            @error('newNoteBody')
                <p>{{ $message }}</p>
            @enderror
            <button type="submit">Add note</button>
        </form>
    @endauth

    <ul>
        @forelse ($notes as $note)
            <li>
                <button type="button" x-data x-on:click="$dispatch('seek-to', { seconds: {{ $note->timestamp_s }} })">
                    {{ sprintf('%02d:%02d', intdiv($note->timestamp_s, 60), $note->timestamp_s % 60) }}
                </button>
                @if ($note->body)
                    {{ $note->body }}
                @else
                    <em>Bookmark</em>
                @endif
            </li>
        @empty
            <li>No notes or bookmarks yet.</li>
        @endforelse
    </ul>
</div>
