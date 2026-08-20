<?php

namespace App\Exceptions;

use App\Domains\Content\Models\Lesson;
use RuntimeException;

class CircularPrerequisiteException extends RuntimeException
{
    public static function forEdge(Lesson $lesson, Lesson $prerequisite): self
    {
        return new self("Setting lesson [{$lesson->id}] to require lesson [{$prerequisite->id}] would create a circular prerequisite chain.");
    }
}
