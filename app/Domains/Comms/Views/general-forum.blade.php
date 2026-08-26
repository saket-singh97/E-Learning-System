<div>
    <h2>General Discussion</h2>

    @if (! $canParticipate)
        <p>Log in to view the general discussion.</p>
    @elseif ($openThreadId === null)
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

    {{-- S19 N14 pt.2 — same real-time wiring as batch-forum.blade.php, over
         the same shared forum-thread.{id} channel. --}}
    @script
        <script>
            let currentForumChannel = null;

            function subscribeToForumThread(threadId) {
                if (currentForumChannel) {
                    window.Echo.leave(currentForumChannel);
                    currentForumChannel = null;
                }

                if (!threadId || !window.Echo) {
                    return;
                }

                currentForumChannel = `forum-thread.${threadId}`;
                window.Echo.private(currentForumChannel).listen('.post.created', (event) => {
                    $wire.call('receivePost', event);
                });
            }

            if (window.Echo) {
                subscribeToForumThread($wire.openThreadId);
            }

            $wire.$watch('openThreadId', (value) => subscribeToForumThread(value));
        </script>
    @endscript
</div>
