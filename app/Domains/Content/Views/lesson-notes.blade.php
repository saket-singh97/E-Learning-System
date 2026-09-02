<div class="lms-panel" x-data="{ currentSeconds: 0 }" x-on:lesson-time-update.window="currentSeconds = $event.detail.seconds">
    <h2 class="lms-field-label">My notes &amp; bookmarks</h2>

    @auth
        <button type="button" class="lms-button lms-button-secondary" style="width:auto;" x-on:click="$wire.bookmark(currentSeconds)">Bookmark this moment</button>

        <form x-on:submit.prevent="$wire.addNote(currentSeconds)" style="margin-top:0.75rem;">
            <label class="lms-field">
                <span class="lms-field-label">Add a note at this moment</span>
                <textarea class="lms-input" wire:model="newNoteBody" placeholder="Add a note at this moment"></textarea>
            </label>
            @error('newNoteBody')
                <p class="lms-error">{{ $message }}</p>
            @enderror
            <button type="submit" class="lms-button" style="width:auto;">Add note</button>
        </form>
    @endauth

    <ul>
        @forelse ($notes as $note)
            <li class="lms-list-item">
                <button type="button" class="lms-link" style="margin-top:0;" x-data x-on:click="$dispatch('seek-to', { seconds: {{ $note->timestamp_s }} })">
                    {{ sprintf('%02d:%02d', intdiv($note->timestamp_s, 60), $note->timestamp_s % 60) }}
                </button>
                @if ($note->body)
                    {{ $note->body }}
                @else
                    <em>Bookmark</em>
                @endif
            </li>
        @empty
            <li class="lms-meta">No notes or bookmarks yet.</li>
        @endforelse
    </ul>
</div>
