<li>
    <p><strong>{{ $post->author->name }}</strong>: {{ $post->body }}</p>

    @if ($canParticipate && $post->depth < 2)
        <form wire:submit="reply({{ $post->id }})">
            <textarea wire:model="replyBodies.{{ $post->id }}" placeholder="Reply"></textarea>
            @error("replyBodies.{$post->id}")
                <p>{{ $message }}</p>
            @enderror
            <button type="submit">Reply</button>
        </form>
    @endif

    @if ($post->childReplies->isNotEmpty())
        <ul>
            @foreach ($post->childReplies as $reply)
                @include('comms::forum-post', ['post' => $reply, 'canParticipate' => $canParticipate])
            @endforeach
        </ul>
    @endif
</li>
