<div>
    <h1>Messages</h1>

    @guest
        <p>Log in to see your messages.</p>
    @else
        <h2>Conversations</h2>
        <ul>
            @forelse ($conversationPartners as $partner)
                <li>
                    <button type="button" wire:click="selectConversation({{ $partner->id }})">{{ $partner->name }}</button>
                </li>
            @empty
                <li>No conversations yet.</li>
            @endforelse
        </ul>

        <h2>Start a new conversation</h2>
        <select wire:model.live="recipientId">
            <option value="">Choose someone</option>
            @foreach ($otherUsers as $user)
                <option value="{{ $user->id }}">{{ $user->name }}</option>
            @endforeach
        </select>

        @if ($recipientId)
            <h2>Thread</h2>

            <button type="button" wire:click="toggleBlock({{ $recipientId }})">
                {{ in_array($recipientId, $blockedUserIds, true) ? 'Unblock' : 'Block' }}
            </button>

            <ul>
                @forelse ($messages as $directMessage)
                    <li>
                        <strong>{{ $directMessage->sender_id === auth()->id() ? 'You' : $directMessage->sender->name }}</strong>:
                        {{ $directMessage->body }}

                        @if ($directMessage->sender_id !== auth()->id())
                            <form wire:submit="reportMessage({{ $directMessage->id }})">
                                <input type="text" wire:model="reportReasons.{{ $directMessage->id }}" placeholder="Reason for reporting">
                                @error("reportReasons.{$directMessage->id}")
                                    <p>{{ $message }}</p>
                                @enderror
                                <button type="submit">Report</button>
                            </form>
                        @endif
                    </li>
                @empty
                    <li>No messages yet — say hello.</li>
                @endforelse
            </ul>

            <form wire:submit="send">
                <textarea wire:model="newMessageBody" placeholder="Write a message"></textarea>
                @error('newMessageBody')
                    <p>{{ $message }}</p>
                @enderror
                <button type="submit">Send</button>
            </form>
        @endif

        {{-- S19 N11 pt.2 — real-time push over the user.{id} private channel
             (routes/channels.php, B0). Guarded on window.Echo existing: this
             component can be embedded on a page that hasn't loaded the Vite
             bundle, in which case it degrades to the pre-S19 behaviour
             (thread refreshes on the next normal Livewire interaction). --}}
        @script
            <script>
                if (window.Echo) {
                    let channel = window.Echo.private(`user.{{ auth()->id() }}`);

                    channel.listen('.message.sent', (event) => {
                        $wire.call('receiveMessage', event);
                    });

                    channel.subscribed(() => {
                        $wire.call('catchUp', $wire.lastSeenMessageId);
                    });
                }
            </script>
        @endscript
    @endguest
</div>
