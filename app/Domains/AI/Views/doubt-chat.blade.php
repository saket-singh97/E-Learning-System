<div class="lms-panel" x-data="{ askSecond: null }" x-on:lesson-time-update.window="askSecond = $event.detail.seconds">
    <h3 class="lms-field-label">Ask a doubt</h3>

    @if (! $featureEnabled)
        <p class="lms-meta">Doubt chat isn't available on this plan.</p>
    @else
        <div class="lms-chat-messages">
            @forelse ($messages as $message)
                <div class="lms-chat-message">
                    <p><strong>Q:</strong> {{ $message->question }}</p>
                    <p><strong>A:</strong> {{ $message->answer }}</p>

                    @php($citations = $this->citationsFor($message))
                    @if ($citations->isNotEmpty())
                        <p class="lms-meta">
                            Sources:
                            @foreach ($citations as $chunk)
                                <button
                                    type="button"
                                    class="lms-link"
                                    style="margin-top:0;"
                                    x-data
                                    x-on:click="$dispatch('seek-to', { seconds: {{ $chunk->start_s }} })"
                                >{{ $chunk->start_s }}s</button>
                            @endforeach
                        </p>
                    @endif
                </div>
            @empty
                <p class="lms-meta">No questions yet — ask one below.</p>
            @endforelse
        </div>

        @auth
            {{-- U4 (S22 B5) — askSecond comes from the outer x-data above,
                 fed by the player's existing lesson-time-update event; only
                 sent to ask() when the student opts in via the checkbox. --}}
            <form x-data="{ askAboutMoment: false }" x-on:submit.prevent="$wire.ask(askAboutMoment ? askSecond : null)">
                <div class="lms-chat-input">
                    <textarea class="lms-input" wire:model="question" placeholder="Ask a question about this lesson..."></textarea>
                    <button type="submit" class="lms-button" style="width:auto;">Ask</button>
                </div>
                @error('question') <p class="lms-error">{{ $message }}</p> @enderror
                <label class="lms-meta" style="display:block; margin-top:0.5rem;">
                    <input type="checkbox" x-model="askAboutMoment">
                    Ask about this moment
                    <template x-if="askSecond !== null">
                        <span>(<span x-text="askSecond"></span>s)</span>
                    </template>
                </label>
            </form>
        @else
            <p class="lms-meta">Log in to ask a question.</p>
        @endauth
    @endif
</div>
