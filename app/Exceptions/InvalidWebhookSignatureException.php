<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidWebhookSignatureException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Webhook signature verification failed.');
    }
}
