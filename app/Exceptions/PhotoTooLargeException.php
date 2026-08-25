<?php

namespace App\Exceptions;

use RuntimeException;

class PhotoTooLargeException extends RuntimeException
{
    public static function forSize(int $sizeKb, int $maxSizeKb): self
    {
        return new self("Photo is {$sizeKb}KB, which exceeds the {$maxSizeKb}KB limit.");
    }
}
