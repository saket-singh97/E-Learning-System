<div class="doubt-chat">
    <h3>Ask a doubt</h3>

    <div class="doubt-chat-messages">
        @forelse ($messages as $message)
            <div class="doubt-chat-message">
                <p><strong>Q:</strong> {{ $message->question }}</p>
                <p><strong>A:</strong> {{ $message->answer }}</p>

                @php($citations = $this->citationsFor($message))
                @if ($citations->isNotEmpty())
                    <p class="doubt-chat-citations">
                        Sources:
                        @foreach ($citations as $chunk)
                            <button
                                type="button"
                                x-data
                                x-on:click="$dispatch('seek-to', { seconds: {{ $chunk->start_s }} })"
                            >{{ $chunk->start_s }}s</button>
                        @endforeach
                    </p>
                @endif
            </div>
        @empty
            <p>No questions yet — ask one below.</p>
        @endforelse
    </div>

    @auth
        <form wire:submit="ask">
            <textarea wire:model="question" placeholder="Ask a question about this lesson..."></textarea>
            @error('question') <span class="error">{{ $message }}</span> @enderror
            <button type="submit">Ask</button>
        </form>
    @else
        <p>Log in to ask a question.</p>
    @endauth
</div>
