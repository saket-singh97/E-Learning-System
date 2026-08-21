<?php

namespace App\Exceptions;

use RuntimeException;

class LivePollStateException extends RuntimeException
{
    public static function cannotOpen(int $pollId, string $currentStatus): self
    {
        return new self("Live poll [{$pollId}] cannot be opened from status [{$currentStatus}] — only a draft poll may be opened.");
    }

    public static function cannotClose(int $pollId, string $currentStatus): self
    {
        return new self("Live poll [{$pollId}] cannot be closed from status [{$currentStatus}] — only an open poll may be closed.");
    }

    public static function sessionNotLive(int $batchSessionId): self
    {
        return new self("Batch session [{$batchSessionId}] is not currently live — a poll can only be opened during a live session.");
    }
}
