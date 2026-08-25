<?php

namespace App\Exceptions;

use RuntimeException;

class LessonArtifactNotReadyException extends RuntimeException
{
    public static function forLesson(int $lessonId): self
    {
        return new self("Lesson [{$lessonId}] has no ready LessonArtifact yet — ingestion may still be in progress.");
    }
}
