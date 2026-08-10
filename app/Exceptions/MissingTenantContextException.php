<?php

namespace App\Exceptions;

use RuntimeException;

class MissingTenantContextException extends RuntimeException
{
    public static function make(): self
    {
        return new self('No tenant is bound to the current context.');
    }
}
