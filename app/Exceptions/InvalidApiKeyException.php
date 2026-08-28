<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidApiKeyException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Missing, invalid, or revoked API key.');
    }
}
