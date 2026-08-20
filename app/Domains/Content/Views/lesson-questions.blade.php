<div>
    <h2>Questions</h2>

    @auth
        <form wire:submit="askQuestion">
            <textarea wire:model="newQuestionBody" placeholder="Ask a question about this lesson"></textarea>
            @error('newQuestionBody')
                <p>{{ $message }}</p>
            @enderror
            <button type="submit">Post question</button>
        </form>
    @endauth

    <ul>
        @forelse ($questions as $question)
            <li>
                <p>
                    <strong>{{ $question->user->name }}</strong>: {{ $question->body }}
                    @if ($question->resolved_at)
                        <span>Resolved</span>
                    @endif
                </p>

                @auth
                    <button type="button" wire:click="toggleUpvote({{ $question->id }})">
                        {{ in_array($question->id, $upvotedQuestionIds, true) ? 'Remove upvote' : 'Upvote' }}
                        ({{ $question->votes_count }})
                    </button>
                @else
                    <span>{{ $question->votes_count }} upvotes</span>
                @endauth

                @if ($canResolve && ! $question->resolved_at)
                    <button type="button" wire:click="markResolved({{ $question->id }})">Mark resolved</button>
                @endif

                <ul>
                    @foreach ($question->replies as $reply)
                        <li><strong>{{ $reply->user->name }}</strong>: {{ $reply->body }}</li>
                    @endforeach
                </ul>

                @auth
                    <form wire:submit="reply({{ $question->id }})">
                        <textarea wire:model="replyBodies.{{ $question->id }}" placeholder="Reply"></textarea>
                        @error("replyBodies.{$question->id}")
                            <p>{{ $message }}</p>
                        @enderror
                        <button type="submit">Reply</button>
                    </form>
                @endauth
            </li>
        @empty
            <li>No questions yet.</li>
        @endforelse
    </ul>
</div>
