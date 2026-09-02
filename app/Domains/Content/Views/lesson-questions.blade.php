<div class="lms-panel">
    <h2 class="lms-field-label">Questions</h2>

    @auth
        <form wire:submit="askQuestion">
            <label class="lms-field">
                <span class="lms-field-label">Ask a question about this lesson</span>
                <textarea class="lms-input" wire:model="newQuestionBody" placeholder="Ask a question about this lesson"></textarea>
            </label>
            @error('newQuestionBody')
                <p class="lms-error">{{ $message }}</p>
            @enderror
            <button type="submit" class="lms-button" style="width:auto;">Post question</button>
        </form>
    @endauth

    <ul>
        @forelse ($questions as $question)
            <li class="lms-list-item">
                <p>
                    <strong>{{ $question->user->name }}</strong>: {{ $question->body }}
                    @if ($question->resolved_at)
                        <span class="lms-badge lms-badge--muted">Resolved</span>
                    @endif
                </p>

                @auth
                    <button type="button" class="lms-link" style="margin-top:0;" wire:click="toggleUpvote({{ $question->id }})">
                        {{ in_array($question->id, $upvotedQuestionIds, true) ? 'Remove upvote' : 'Upvote' }}
                        ({{ $question->votes_count }})
                    </button>
                @else
                    <span class="lms-meta">{{ $question->votes_count }} upvotes</span>
                @endauth

                @if ($canResolve && ! $question->resolved_at)
                    <button type="button" class="lms-link" style="margin-top:0;" wire:click="markResolved({{ $question->id }})">Mark resolved</button>
                @endif

                <ul>
                    @foreach ($question->replies as $reply)
                        <li class="lms-list-item"><strong>{{ $reply->user->name }}</strong>: {{ $reply->body }}</li>
                    @endforeach
                </ul>

                @auth
                    <form wire:submit="reply({{ $question->id }})" style="margin-top:0.75rem;">
                        <label class="lms-field">
                            <span class="lms-field-label">Reply</span>
                            <textarea class="lms-input" wire:model="replyBodies.{{ $question->id }}" placeholder="Reply"></textarea>
                        </label>
                        @error("replyBodies.{$question->id}")
                            <p class="lms-error">{{ $message }}</p>
                        @enderror
                        <button type="submit" class="lms-button" style="width:auto;">Reply</button>
                    </form>
                @endauth
            </li>
        @empty
            <li class="lms-meta">No questions yet.</li>
        @endforelse
    </ul>
</div>
