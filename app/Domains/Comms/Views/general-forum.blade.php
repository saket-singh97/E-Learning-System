<div class="lms-panel">
    <h2 class="lms-field-label">General Discussion</h2>

    @if (! $canParticipate)
        <p class="lms-meta">Log in to view the general discussion.</p>
    @elseif ($openThreadId === null)
        <form wire:submit="createThread">
            <label class="lms-field">
                <span class="lms-field-label">Thread title</span>
                <input class="lms-input" type="text" wire:model="newThreadTitle" placeholder="Thread title">
            </label>
            @error('newThreadTitle')
                <p class="lms-error">{{ $message }}</p>
            @enderror

            <label class="lms-field">
                <span class="lms-field-label">Message</span>
                <textarea class="lms-input" wire:model="newThreadBody" placeholder="Start the discussion" rows="4"></textarea>
            </label>
            @error('newThreadBody')
                <p class="lms-error">{{ $message }}</p>
            @enderror

            <button type="submit" class="lms-button" style="width:auto;">New thread</button>
        </form>

        <hr class="lms-divider">

        <ul>
            @forelse ($threads as $thread)
                <li class="lms-list-item">
                    <button type="button" class="lms-link" style="margin-top:0;" wire:click="openThread({{ $thread->id }})">{{ $thread->title }}</button>
                    <span class="lms-meta">({{ $thread->posts_count }} posts)</span>
                </li>
            @empty
                <li class="lms-meta">No discussion threads yet.</li>
            @endforelse
        </ul>
    @else
        <button type="button" class="lms-link" style="margin-top:0;" wire:click="closeThread">Back to threads</button>

        <ul style="margin-top:0.75rem;">
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
