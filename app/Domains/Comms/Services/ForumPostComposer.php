<?php

namespace App\Domains\Comms\Services;

use App\Domains\Comms\Models\ForumPost;
use App\Domains\Comms\Models\ForumThread;
use App\Exceptions\ForumReplyDepthExceededException;
use App\Models\User;

/**
 * Single write path for forum_posts (same "one shared path" posture
 * ParentInviteCodeService/InstructorInvitationService already established)
 * — depth is derived from the parent's own stored depth, not walked or
 * recomputed elsewhere, and refused before persisting rather than
 * silently flattened (design decision 5).
 */
class ForumPostComposer
{
    public const MAX_DEPTH = 2;

    public function post(ForumThread $thread, ?ForumPost $parent, User $author, string $body): ForumPost
    {
        $depth = $parent === null ? 0 : $parent->depth + 1;

        if ($depth > self::MAX_DEPTH) {
            throw ForumReplyDepthExceededException::forParent($parent->id);
        }

        return ForumPost::create([
            'forum_thread_id' => $thread->id,
            'parent_id' => $parent?->id,
            'depth' => $depth,
            'author_id' => $author->id,
            'body' => $body,
        ]);
    }
}
