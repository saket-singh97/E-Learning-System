<div>
    @if ($notices->isNotEmpty())
        <h2>Notices</h2>

        <ul>
            @foreach ($notices as $notice)
                <li>
                    <strong>{{ $notice->title }}</strong>
                    @unless (in_array($notice->id, $readNoticeIds, true))
                        <span>(new)</span>
                    @endunless
                    <p>{{ $notice->body }}</p>

                    @unless (in_array($notice->id, $readNoticeIds, true))
                        <button type="button" wire:click="markAsRead({{ $notice->id }})">Mark as read</button>
                    @endunless
                </li>
            @endforeach
        </ul>
    @endif
</div>
