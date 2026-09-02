<div class="lms-panel">
    <h2 class="lms-field-label">Help desk</h2>

    @if (! $canRespond)
        <p class="lms-meta">Log in to raise or view a ticket.</p>
    @elseif ($openTicketId === null)
        <form wire:submit="raiseTicket">
            <label class="lms-field">
                <span class="lms-field-label">Subject</span>
                <input class="lms-input" type="text" wire:model="subject" placeholder="Subject">
            </label>
            @error('subject')
                <p class="lms-error">{{ $message }}</p>
            @enderror

            <label class="lms-field">
                <span class="lms-field-label">Describe the issue</span>
                <textarea class="lms-input" wire:model="body" placeholder="Describe the issue" rows="4"></textarea>
            </label>
            @error('body')
                <p class="lms-error">{{ $message }}</p>
            @enderror

            <label class="lms-field">
                <span class="lms-field-label">Priority</span>
                <select class="lms-input" wire:model="priority">
                    <option value="low">Low</option>
                    <option value="normal">Normal</option>
                    <option value="high">High</option>
                </select>
            </label>
            @error('priority')
                <p class="lms-error">{{ $message }}</p>
            @enderror

            <button type="submit" class="lms-button" style="width:auto;">Raise ticket</button>
        </form>

        <hr class="lms-divider">

        <h3 class="lms-field-label">My tickets</h3>
        <ul>
            @forelse ($tickets as $ticket)
                <li class="lms-list-item">
                    <button type="button" class="lms-link" style="margin-top:0;" wire:click="openTicket({{ $ticket->id }})">{{ $ticket->subject }}</button>
                    <span class="lms-badge lms-badge--muted">{{ $ticket->status }}</span>
                </li>
            @empty
                <li class="lms-meta">No tickets raised yet.</li>
            @endforelse
        </ul>
    @else
        <button type="button" class="lms-link" style="margin-top:0;" wire:click="closeTicket">Back to tickets</button>

        <ul>
            @foreach ($replies as $reply)
                <li class="lms-list-item"><strong>{{ $reply->author->name }}</strong>: {{ $reply->body }}</li>
            @endforeach
        </ul>
    @endif

    {{-- S19 N16 pt.3 — real-time push over help-desk-ticket.{id}
         (routes/channels.php, B9). openTicketId changes as the viewer
         opens/closes tickets, same re-subscribe-on-change shape the forum
         views (B3) already establish. --}}
    @script
        <script>
            let currentTicketChannel = null;

            function subscribeToTicket(ticketId) {
                if (currentTicketChannel) {
                    window.Echo.leave(currentTicketChannel);
                    currentTicketChannel = null;
                }

                if (!ticketId || !window.Echo) {
                    return;
                }

                currentTicketChannel = `help-desk-ticket.${ticketId}`;
                window.Echo.private(currentTicketChannel).listen('.reply.created', (event) => {
                    $wire.call('receiveReply', event);
                });
            }

            if (window.Echo) {
                subscribeToTicket($wire.openTicketId);
            }

            $wire.$watch('openTicketId', (value) => subscribeToTicket(value));
        </script>
    @endscript
</div>
