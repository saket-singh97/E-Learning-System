<div>
    <h2>Help desk</h2>

    @if (! $canRespond)
        <p>Log in to raise or view a ticket.</p>
    @elseif ($openTicketId === null)
        <form wire:submit="raiseTicket">
            <input type="text" wire:model="subject" placeholder="Subject">
            @error('subject')
                <p>{{ $message }}</p>
            @enderror
            <textarea wire:model="body" placeholder="Describe the issue"></textarea>
            @error('body')
                <p>{{ $message }}</p>
            @enderror
            <select wire:model="priority">
                <option value="low">Low</option>
                <option value="normal">Normal</option>
                <option value="high">High</option>
            </select>
            @error('priority')
                <p>{{ $message }}</p>
            @enderror
            <button type="submit">Raise ticket</button>
        </form>

        <h3>My tickets</h3>
        <ul>
            @forelse ($tickets as $ticket)
                <li>
                    <button type="button" wire:click="openTicket({{ $ticket->id }})">{{ $ticket->subject }}</button>
                    ({{ $ticket->status }})
                </li>
            @empty
                <li>No tickets raised yet.</li>
            @endforelse
        </ul>
    @else
        <button type="button" wire:click="closeTicket">Back to tickets</button>

        <ul>
            @foreach ($replies as $reply)
                <li><strong>{{ $reply->author->name }}</strong>: {{ $reply->body }}</li>
            @endforeach
        </ul>
    @endif
</div>
