<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidCourseImportFileException extends RuntimeException
{
    public static function badHeaders(): self
    {
        return new self('The CSV must have a header row with exactly these columns: title, slug, category.');
    }

    public static function empty(): self
    {
        return new self('The CSV has no data rows.');
    }
}
