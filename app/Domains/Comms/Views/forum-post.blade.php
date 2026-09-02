<li class="lms-thread-item">
    <p style="margin:0 0 0.25rem;"><strong>{{ $post->author->name }}</strong></p>
    <p style="margin:0;">{{ $post->body }}</p>

    @if ($canParticipate && $post->depth < 2)
        <form wire:submit="reply({{ $post->id }})" class="lms-field" style="margin-top:0.625rem;margin-bottom:0;">
            <textarea class="lms-input" wire:model="replyBodies.{{ $post->id }}" placeholder="Reply" rows="2"></textarea>
            @error("replyBodies.{$post->id}")
                <p class="lms-error">{{ $message }}</p>
            @enderror
            <button type="submit" class="lms-button lms-button-secondary" style="width:auto;margin-top:0.5rem;">Reply</button>
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
