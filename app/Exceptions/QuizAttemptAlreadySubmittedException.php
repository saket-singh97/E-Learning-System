<?php

namespace App\Exceptions;

use RuntimeException;

class QuizAttemptAlreadySubmittedException extends RuntimeException
{
    public static function forAttempt(int $attemptId): self
    {
        return new self("Quiz attempt [{$attemptId}] has already been submitted.");
    }
}
