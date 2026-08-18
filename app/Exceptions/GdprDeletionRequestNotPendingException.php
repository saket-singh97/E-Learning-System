<?php

namespace App\Exceptions;

use RuntimeException;

class GdprDeletionRequestNotPendingException extends RuntimeException
{
    public static function forRequest(int $requestId): self
    {
        return new self("GDPR deletion request [{$requestId}] is no longer pending — it can no longer be cancelled.");
    }
}
