<div>
    @auth
        <div class="lms-panel">
            <p class="lms-field-label" style="display:flex; align-items:center; justify-content:space-between;">
                <span>Notifications ({{ $unreadCount }} unread)</span>
                @if ($unreadCount > 0)
                    <button type="button" class="lms-link" style="margin-top:0;" wire:click="markAllAsRead">Mark all read</button>
                @endif
            </p>

            @if ($notifications->isEmpty())
                <p class="lms-meta">No notifications yet.</p>
            @else
                <ul>
                    @foreach ($notifications as $notification)
                        <li class="lms-list-item" wire:key="notification-{{ $notification->id }}">
                            <span class="lms-meta">{{ $notification->created_at->diffForHumans() }}</span>
                            —
                            <span>{{ $this->summarize($notification) }}</span>
                            @if ($notification->read_at === null)
                                <button type="button" class="lms-link" style="margin-top:0;" wire:click="markAsRead('{{ $notification->id }}')">Mark read</button>
                            @else
                                <em class="lms-meta">(read)</em>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endauth
</div>
