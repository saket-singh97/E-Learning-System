<div>
    @auth
        <p>
            Notifications ({{ $unreadCount }} unread)
            @if ($unreadCount > 0)
                <button type="button" wire:click="markAllAsRead">Mark all read</button>
            @endif
        </p>

        @if ($notifications->isEmpty())
            <p>No notifications yet.</p>
        @else
            <ul>
                @foreach ($notifications as $notification)
                    <li wire:key="notification-{{ $notification->id }}">
                        <span>{{ $notification->created_at->diffForHumans() }}</span>
                        —
                        <span>{{ $this->summarize($notification) }}</span>
                        @if ($notification->read_at === null)
                            <button type="button" wire:click="markAsRead('{{ $notification->id }}')">Mark read</button>
                        @else
                            <em>(read)</em>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @endauth
</div>
