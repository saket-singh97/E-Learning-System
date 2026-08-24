<div>
    <h2>Discussion</h2>

    @if (! $canParticipate)
        <p>Log in with an account enrolled in this batch to view the discussion.</p>
    @elseif ($openThreadId === null)
        @if ($canParticipate)
            <form wire:submit="createThread">
                <input type="text" wire:model="newThreadTitle" placeholder="Thread title">
                @error('newThreadTitle')
                    <p>{{ $message }}</p>
                @enderror
                <textarea wire:model="newThreadBody" placeholder="Start the discussion"></textarea>
                @error('newThreadBody')
                    <p>{{ $message }}</p>
                @enderror
                <button type="submit">New thread</button>
            </form>
        @endif

        <ul>
            @forelse ($threads as $thread)
                <li>
                    <button type="button" wire:click="openThread({{ $thread->id }})">{{ $thread->title }}</button>
                    ({{ $thread->posts_count }} posts)
                </li>
            @empty
                <li>No discussion threads yet.</li>
            @endforelse
        </ul>
    @else
        <button type="button" wire:click="closeThread">Back to threads</button>

        <ul>
            @foreach ($posts as $post)
                @include('comms::forum-post', ['post' => $post, 'canParticipate' => $canParticipate])
            @endforeach
        </ul>
    @endif
</div>
