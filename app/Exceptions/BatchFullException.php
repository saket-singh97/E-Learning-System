<?php

namespace App\Exceptions;

use RuntimeException;

class BatchFullException extends RuntimeException
{
    public static function forBatch(int $batchId): self
    {
        return new self("Batch [{$batchId}] has no seats remaining; approve a cancellation or increase seat_limit first.");
    }
}
