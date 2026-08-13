<?php

namespace App\Exceptions;

use RuntimeException;

class CannotImpersonateException extends RuntimeException
{
    public static function superAdminTarget(): self
    {
        return new self('A super_admin cannot impersonate another super_admin.');
    }
}
