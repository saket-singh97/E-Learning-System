<?php

namespace App\Domains\Comms\Events;

use App\Domains\Comms\Models\HelpDeskTicketReply;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * S19 N16 pt.3 — fired only for a public reply, never an
 * `is_internal_note` one: every subscriber to `help-desk-ticket.{id}`
 * (routes/channels.php) receives the exact same payload, and the
 * requester is one of the two roles authorized on that channel, so an
 * internal note broadcasting here would leak it to them regardless of
 * `HelpDeskTicketForm::replies()`'s own server-side filter.
 */
class HelpDeskTicketReplyCreated implements ShouldBroadcastNow
{
    public function __construct(public readonly HelpDeskTicketReply $reply) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('help-desk-ticket.'.$this->reply->ticket_id)];
    }

    public function broadcastAs(): string
    {
        return 'reply.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->reply->id,
            'author_id' => $this->reply->author_id,
            'author_name' => $this->reply->author->name,
            'body' => $this->reply->body,
            'created_at' => $this->reply->created_at->toIso8601String(),
        ];
    }
}
