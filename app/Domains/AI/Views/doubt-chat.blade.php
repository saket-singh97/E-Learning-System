<div class="doubt-chat" x-data="{ askSecond: null }" x-on:lesson-time-update.window="askSecond = $event.detail.seconds">
    <h3>Ask a doubt</h3>

    @if (! $featureEnabled)
        <p>Doubt chat isn't available on this plan.</p>
    @else
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
            {{-- U4 (S22 B5) — askSecond comes from the outer x-data above,
                 fed by the player's existing lesson-time-update event; only
                 sent to ask() when the student opts in via the checkbox. --}}
            <form x-data="{ askAboutMoment: false }" x-on:submit.prevent="$wire.ask(askAboutMoment ? askSecond : null)">
                <textarea wire:model="question" placeholder="Ask a question about this lesson..."></textarea>
                @error('question') <span class="error">{{ $message }}</span> @enderror
                <label>
                    <input type="checkbox" x-model="askAboutMoment">
                    Ask about this moment
                    <template x-if="askSecond !== null">
                        <span>(<span x-text="askSecond"></span>s)</span>
                    </template>
                </label>
                <button type="submit">Ask</button>
            </form>
        @else
            <p>Log in to ask a question.</p>
        @endauth
    @endif
</div>
