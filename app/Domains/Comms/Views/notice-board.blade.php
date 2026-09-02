<div>
    @if ($notices->isNotEmpty())
        <div class="lms-panel">
            <h2 class="lms-field-label">Notices</h2>

            <ul>
                @foreach ($notices as $notice)
                    <li class="lms-list-item">
                        <strong>{{ $notice->title }}</strong>
                        @unless (in_array($notice->id, $readNoticeIds, true))
                            <span class="lms-meta">(new)</span>
                        @endunless
                        <p>{{ $notice->body }}</p>

                        @unless (in_array($notice->id, $readNoticeIds, true))
                            <button type="button" class="lms-link" style="margin-top:0;" wire:click="markAsRead({{ $notice->id }})">Mark as read</button>
                        @endunless
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
