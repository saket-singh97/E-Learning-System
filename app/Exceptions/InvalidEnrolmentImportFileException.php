<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidEnrolmentImportFileException extends RuntimeException
{
    public static function badHeaders(): self
    {
        return new self('The CSV must have a header row with exactly these columns: email, batch.');
    }

    public static function empty(): self
    {
        return new self('The CSV has no data rows.');
    }
}
