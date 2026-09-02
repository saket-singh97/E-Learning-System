<div>
    <h1 class="lms-heading">Calendar</h1>

    @if (! $canRespond)
        <p class="lms-subtext">Log in to see your calendar.</p>
    @else
        @php
            $monthStart = \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $gridStart = $monthStart->copy()->subDays($monthStart->dayOfWeek);
            $gridEnd = $monthEnd->copy()->addDays(6 - $monthEnd->dayOfWeek);
            $eventsByDay = $events->groupBy(fn ($event) => $event['starts_at']->format('Y-m-d'));
            $today = today()->format('Y-m-d');
        @endphp

        <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:1rem; flex-wrap:wrap;">
            <button type="button" class="lms-button-secondary" style="width:auto;" wire:click="previousMonth">&laquo; Previous</button>
            <span class="lms-heading" style="margin-bottom:0;">{{ $monthStart->format('F Y') }}</span>
            <button type="button" class="lms-button-secondary" style="width:auto;" wire:click="nextMonth">Next &raquo;</button>
        </div>

        @if ($feedUrl)
            <p><a class="lms-link" style="margin-top:0;" href="{{ $feedUrl }}">Subscribe to calendar feed (.ics)</a></p>
        @endif

        <div style="display:grid; grid-template-columns:repeat(7, 1fr); gap:1px; margin-bottom:0.25rem; font-size:0.75rem; font-weight:600; color:var(--color-muted); text-align:center;">
            @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $label)
                <div>{{ $label }}</div>
            @endforeach
        </div>

        <div class="lms-calendar-grid">
            @php($cursor = $gridStart->copy())
            @while ($cursor->lte($gridEnd))
                @php($dayKey = $cursor->format('Y-m-d'))

                <div
                    @class([
                        'lms-calendar-day',
                        'lms-calendar-day--today' => $dayKey === $today,
                        'lms-calendar-day--other-month' => $cursor->format('Y-m') !== $month,
                    ])
                    wire:key="day-{{ $dayKey }}"
                >
                    <div>{{ $cursor->day }}</div>

                    @foreach ($eventsByDay->get($dayKey, []) as $event)
                        @if ($event['source'] === 'custom')
                            <button
                                type="button"
                                class="lms-calendar-event"
                                style="width:100%; text-align:left; border:none; cursor:pointer;"
                                wire:click="editEvent({{ $event['id'] }})"
                                wire:key="event-{{ $event['source'] }}-{{ $event['id'] }}"
                                title="{{ $event['title'] }}"
                            >{{ $event['title'] }}</button>
                        @else
                            <span
                                class="lms-calendar-event"
                                wire:key="event-{{ $event['source'] }}-{{ $event['id'] }}"
                                title="{{ $event['title'] }} ({{ str_replace('_', ' ', $event['source']) }})"
                            >{{ $event['title'] }}</span>
                        @endif
                    @endforeach
                </div>

                @php($cursor->addDay())
            @endwhile
        </div>

        <h2 class="lms-field-label" style="margin-top:1.5rem;">{{ $editingEventId ? 'Edit event' : 'Add event' }}</h2>
        <form wire:submit="{{ $editingEventId ? 'updateEvent' : 'createEvent' }}">
            <label class="lms-field">
                <span class="lms-field-label">Title</span>
                <input class="lms-input" type="text" wire:model="title" placeholder="Title">
            </label>
            @error('title')
                <p class="lms-error">{{ $message }}</p>
            @enderror

            <label class="lms-field">
                <span class="lms-field-label">Description</span>
                <textarea class="lms-input" wire:model="description" placeholder="Description (optional)"></textarea>
            </label>
            @error('description')
                <p class="lms-error">{{ $message }}</p>
            @enderror

            <label class="lms-field">
                <span class="lms-field-label">Starts</span>
                <input class="lms-input" type="datetime-local" wire:model="startsAt">
            </label>
            @error('startsAt')
                <p class="lms-error">{{ $message }}</p>
            @enderror

            <label class="lms-field">
                <span class="lms-field-label">Ends</span>
                <input class="lms-input" type="datetime-local" wire:model="endsAt">
            </label>
            @error('endsAt')
                <p class="lms-error">{{ $message }}</p>
            @enderror

            <div style="display:flex; gap:0.75rem;">
                <button type="submit" class="lms-button" style="width:auto;">{{ $editingEventId ? 'Save' : 'Add' }}</button>
                @if ($editingEventId)
                    <button type="button" class="lms-button-secondary" style="width:auto;" wire:click="deleteEvent({{ $editingEventId }})">Delete</button>
                    <button type="button" class="lms-button-secondary" style="width:auto;" wire:click="cancelEdit">Cancel</button>
                @endif
            </div>
        </form>
    @endif
</div>
