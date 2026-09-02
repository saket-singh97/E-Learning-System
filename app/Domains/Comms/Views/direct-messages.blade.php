<div class="lms-panel">
    <h2 class="lms-field-label">Messages</h2>

    @guest
        <p class="lms-meta">Log in to see your messages.</p>
    @else
        <h3 class="lms-field-label">Conversations</h3>
        <ul>
            @forelse ($conversationPartners as $partner)
                <li class="lms-list-item">
                    <button type="button" class="lms-link" style="margin-top:0;" wire:click="selectConversation({{ $partner->id }})">{{ $partner->name }}</button>
                </li>
            @empty
                <li class="lms-meta">No conversations yet.</li>
            @endforelse
        </ul>

        <label class="lms-field">
            <span class="lms-field-label">Start a new conversation</span>
            <select class="lms-input" wire:model.live="recipientId">
                <option value="">Choose someone</option>
                @foreach ($otherUsers as $user)
                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                @endforeach
            </select>
        </label>

        @if ($recipientId)
            <hr class="lms-divider">

            <div style="display:flex;align-items:center;justify-content:space-between;">
                <h3 class="lms-field-label" style="margin-bottom:0;">Thread</h3>
                <button type="button" class="lms-button lms-button-secondary" style="width:auto;" wire:click="toggleBlock({{ $recipientId }})">
                    {{ in_array($recipientId, $blockedUserIds, true) ? 'Unblock' : 'Block' }}
                </button>
            </div>

            <div class="lms-chat-messages" style="margin-top:0.75rem;">
                @forelse ($messages as $directMessage)
                    <div class="lms-chat-message @if ($directMessage->sender_id === auth()->id()) lms-chat-message--mine @endif">
                        <p class="lms-meta" style="margin:0 0 0.25rem;">{{ $directMessage->sender_id === auth()->id() ? 'You' : $directMessage->sender->name }}</p>
                        <p style="margin:0;">{{ $directMessage->body }}</p>

                        @if ($directMessage->sender_id !== auth()->id())
                            <form wire:submit="reportMessage({{ $directMessage->id }})" style="margin-top:0.5rem;">
                                <input class="lms-input" type="text" wire:model="reportReasons.{{ $directMessage->id }}" placeholder="Reason for reporting">
                                @error("reportReasons.{$directMessage->id}")
                                    <p class="lms-error">{{ $message }}</p>
                                @enderror
                                <button type="submit" class="lms-link" style="margin-top:0.375rem;">Report</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="lms-meta">No messages yet — say hello.</p>
                @endforelse
            </div>

            <form wire:submit="send" class="lms-chat-input">
                <textarea class="lms-input" wire:model="newMessageBody" placeholder="Write a message" rows="1"></textarea>
                <button type="submit" class="lms-button" style="width:auto;">Send</button>
            </form>
            @error('newMessageBody')
                <p class="lms-error">{{ $message }}</p>
            @enderror
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
