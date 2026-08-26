<?php

namespace App\Domains\Comms\Events;

use App\Domains\Comms\Models\ForumPost;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * S19 N14 pt.2 — fired from `ForumPostComposer::post()`, the single write
 * chokepoint both `BatchForum` and `GeneralForum` already share, so both
 * forum kinds get real-time push from this one dispatch point rather than
 * two separate ones threaded through each Livewire component's own
 * `createThread()`/`reply()`. Broadcast-only, consumed client-side over the
 * `forum-thread.{id}` private channel (routes/channels.php, B3) — not a
 * cross-domain `Events/` contract under CLAUDE.md's domain-events rule.
 */
class ForumPostCreated implements ShouldBroadcastNow
{
    public function __construct(public readonly ForumPost $post) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('forum-thread.'.$this->post->forum_thread_id)];
    }

    public function broadcastAs(): string
    {
        return 'post.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->post->id,
            'parent_id' => $this->post->parent_id,
            'author_id' => $this->post->author_id,
            'author_name' => $this->post->author->name,
            'body' => $this->post->body,
            'created_at' => $this->post->created_at->toIso8601String(),
        ];
    }
}
