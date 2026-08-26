<?php

namespace App\Domains\Comms\Events;

use App\Domains\Comms\Models\DirectMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * S19 N11 pt.2 — broadcast-only; consumed client-side over the `user.{id}`
 * private channel (routes/channels.php, B0), not a cross-domain Events/
 * contract under CLAUDE.md's domain-events rule. `ShouldBroadcastNow`, not
 * the queued `ShouldBroadcast`: a chat message arriving shouldn't depend on
 * whether a queue worker happens to be running, matching real-time chat's
 * own latency expectation.
 */
class DirectMessageSent implements ShouldBroadcastNow
{
    public function __construct(public readonly DirectMessage $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->message->recipient_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'sender_id' => $this->message->sender_id,
            'sender_name' => $this->message->sender->name,
            'body' => $this->message->body,
            'created_at' => $this->message->created_at->toIso8601String(),
        ];
    }
}
