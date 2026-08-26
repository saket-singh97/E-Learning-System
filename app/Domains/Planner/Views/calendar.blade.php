<div>
    <h2>Calendar</h2>

    @if (! $canRespond)
        <p>Log in to see your calendar.</p>
    @else
        <button type="button" wire:click="previousMonth">&laquo; Previous</button>
        <span>{{ $month }}</span>
        <button type="button" wire:click="nextMonth">Next &raquo;</button>

        <ul>
            @forelse ($events as $event)
                <li>
                    {{ $event['starts_at']->format('Y-m-d H:i') }}
                    — <strong>{{ $event['title'] }}</strong>
                    ({{ str_replace('_', ' ', $event['source']) }})

                    @if ($event['source'] === 'custom')
                        <button type="button" wire:click="editEvent({{ $event['id'] }})">Edit</button>
                        <button type="button" wire:click="deleteEvent({{ $event['id'] }})">Delete</button>
                    @endif
                </li>
            @empty
                <li>No events this month.</li>
            @endforelse
        </ul>

        <h3>{{ $editingEventId ? 'Edit event' : 'Add event' }}</h3>
        <form wire:submit="{{ $editingEventId ? 'updateEvent' : 'createEvent' }}">
            <input type="text" wire:model="title" placeholder="Title">
            @error('title')
                <p>{{ $message }}</p>
            @enderror
            <textarea wire:model="description" placeholder="Description (optional)"></textarea>
            @error('description')
                <p>{{ $message }}</p>
            @enderror
            <input type="datetime-local" wire:model="startsAt">
            @error('startsAt')
                <p>{{ $message }}</p>
            @enderror
            <input type="datetime-local" wire:model="endsAt">
            @error('endsAt')
                <p>{{ $message }}</p>
            @enderror
            <button type="submit">{{ $editingEventId ? 'Save' : 'Add' }}</button>
            @if ($editingEventId)
                <button type="button" wire:click="cancelEdit">Cancel</button>
            @endif
        </form>
    @endif
</div>
