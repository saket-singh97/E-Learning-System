<?php

namespace App\Exceptions;

use RuntimeException;

class ForumReplyDepthExceededException extends RuntimeException
{
    public static function forParent(int $parentPostId): self
    {
        return new self("Replying to post [{$parentPostId}] would exceed the forum's 3-level nesting limit.");
    }
}
